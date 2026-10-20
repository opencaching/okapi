<?php

namespace okapi\services\caches\update;

use okapi\core\Db;
use okapi\core\Exception\BadRequest;
use okapi\core\Exception\InvalidParam;
use okapi\core\Okapi;
use okapi\core\Request\OkapiRequest;
use okapi\services\caches\CacheEditCommon;

class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 3
        );
    }

    # Every parameter this method changes something with.
    private static $fields = [
        'cache_name', 'latitude', 'longitude', 'difficulty', 'terrain', 'size2', 'passwd',
        'short_description', 'description', 'hint2',
        'alt_wpts', 'add_alt_wpts', 'remove_alt_wpts',
    ];

    public static function call(OkapiRequest $request)
    {
        # Writes the OCDE database layout; other installations would fail half-way.
        CacheEditCommon::require_cache_editing();

        $cache = CacheEditCommon::get_own_cache($request, $request->get_parameter('cache_code'));
        $cache_id = $cache['cache_id'];

        $p = [];
        foreach (self::$fields as $name)
            $p[$name] = $request->get_parameter($name);
        if (count(array_filter($p, function ($v) { return $v !== null; })) == 0)
            throw new BadRequest('Nothing to change: give at least one of '.implode(', ', self::$fields).'.');
        if ($p['alt_wpts'] !== null && ($p['add_alt_wpts'] !== null || $p['remove_alt_wpts'] !== null))
            throw new BadRequest('alt_wpts replaces all waypoints and cannot be combined with add_alt_wpts or remove_alt_wpts.');

        # Validate everything first, then write only what differs from the stored
        # values. A client that sends back all fields unchanged changes nothing.

        $changed = [];
        $cache_sets = [];

        if ($p['cache_name'] !== null) {
            $name = CacheEditCommon::parse_text('cache_name', $p['cache_name'], 'caches', 'name', false);
            if ($name !== $cache['name']) {
                $cache_sets[] = "name = '".Db::escape_string($name)."'";
                $changed[] = 'cache_name';
            }
        }
        if ($p['latitude'] !== null || $p['longitude'] !== null) {
            list($lat, $lon) = CacheEditCommon::parse_location($p['latitude'], $p['longitude']);
            if ($lat != $cache['latitude'] || $lon != $cache['longitude']) {
                $cache_sets[] = "latitude = '".Db::escape_string($lat)."', longitude = '".Db::escape_string($lon)."'";
                $changed[] = 'latitude';
                $changed[] = 'longitude';
            }
        }
        foreach (['difficulty', 'terrain'] as $name) {
            if ($p[$name] !== null) {
                $value = CacheEditCommon::parse_rating($name, $p[$name]);
                if ($value != $cache[$name]) {
                    $cache_sets[] = "$name = '".Db::escape_string($value)."'";
                    $changed[] = $name;
                }
            }
        }
        if ($p['size2'] !== null) {
            $size = CacheEditCommon::parse_size($p['size2']);
            if ($size != $cache['size']) {
                $cache_sets[] = "size = '".Db::escape_string($size)."'";
                $changed[] = 'size2';
            }
        }

        if ($p['passwd'] !== null) {
            # Same rule as services/caches/edit; an empty value removes the password.
            $max_length = Db::field_length('caches', 'logpw');
            if (strlen($p['passwd']) > $max_length)
                throw new InvalidParam('passwd', "Must not be longer than $max_length characters.");
            if ($p['passwd'] !== $cache['logpw']) {
                $cache_sets[] = "logpw = '".Db::escape_string($p['passwd'])."'";
                $changed[] = 'passwd';
            }
        }

        # Descriptions exist per language; this changes one of them.
        $desc_sets = [];
        if ($p['short_description'] !== null || $p['description'] !== null || $p['hint2'] !== null) {
            $desc_lang = strtoupper($request->get_parameter('desc_lang') ?: $cache['default_desclang']);
            $desc = Db::select_row("
                select id, short_desc, `desc`, desc_html, hint from cache_desc
                where cache_id = '".Db::escape_string($cache_id)."'
                and language = '".Db::escape_string($desc_lang)."'
            ");
            if (!$desc)
                throw new InvalidParam('desc_lang', "This cache has no description in '$desc_lang'.");

            if ($p['short_description'] !== null) {
                $value = CacheEditCommon::parse_text('short_description', $p['short_description'], 'cache_desc', 'short_desc', true);
                if ($value !== $desc['short_desc']) {
                    $desc_sets[] = "short_desc = '".Db::escape_string($value)."'";
                    $changed[] = 'short_description';
                }
            }
            if ($p['description'] !== null) {
                # Plain text only. Only a plain or simple description (paragraphs
                # and line breaks) is replaced; one with more formatting, written
                # on the website, is not, so no client removes the formatting.
                $value = CacheEditCommon::encode_description($p['description']);
                if ($value !== $desc['desc'] || $desc['desc_html'] != 1) {
                    if (!CacheEditCommon::is_simple_description($desc['desc']))
                        throw new InvalidParam('description', 'This description has formatting; it can only be changed on the website.');
                    $desc_sets[] = "`desc` = '".Db::escape_string($value)."', desc_html = 1, desc_htmledit = 0";
                    $changed[] = 'description';
                }
            }
            if ($p['hint2'] !== null) {
                $value = CacheEditCommon::encode_hint(trim($p['hint2']));
                if ($value !== $desc['hint']) {
                    $desc_sets[] = "hint = '".Db::escape_string($value)."'";
                    $changed[] = 'hint2';
                }
            }
        }

        # Waypoints. Names refer to the waypoints as they were before this call,
        # as returned by services/caches/geocache (alt_wpts).
        $replace = $p['alt_wpts'] !== null ? CacheEditCommon::parse_waypoints('alt_wpts', $p['alt_wpts']) : null;
        $add = $p['add_alt_wpts'] !== null ? CacheEditCommon::parse_waypoints('add_alt_wpts', $p['add_alt_wpts']) : [];
        $remove_ids = [];
        if ($p['remove_alt_wpts'] !== null && $p['remove_alt_wpts'] !== '') {
            $ids = CacheEditCommon::waypoint_ids_by_name($cache_id, $cache['wp_oc']);
            foreach (array_unique(explode('|', $p['remove_alt_wpts'])) as $name) {
                if (!isset($ids[$name]))
                    throw new InvalidParam('remove_alt_wpts', "This cache has no waypoint '$name'.");
                $remove_ids[] = $ids[$name];
            }
        }
        if ($replace !== null && $replace == self::current_waypoints($cache_id))
            $replace = null;  # the same list again
        if ($replace !== null || $add || $remove_ids)
            $changed[] = 'alt_wpts';

        if (!$changed) {
            $result = ['changed' => []];
            return Okapi::formatted_response($request, $result);
        }

        Db::execute('start transaction');
        try {
            # last_modified, listing_last_modified and the coordinates history are
            # kept by the database triggers of caches, cache_desc and coordinates.
            if ($cache_sets)
                Db::execute("update caches set ".implode(', ', $cache_sets)." where cache_id = '".Db::escape_string($cache_id)."'");
            if ($desc_sets)
                Db::execute("update cache_desc set ".implode(', ', $desc_sets)." where id = '".Db::escape_string($desc['id'])."'");
            if ($replace !== null) {
                Db::execute("delete from coordinates where cache_id = '".Db::escape_string($cache_id)."' and type = 1");
                CacheEditCommon::insert_waypoints($cache_id, $replace);
            }
            if ($remove_ids)
                Db::execute("
                    delete from coordinates
                    where cache_id = '".Db::escape_string($cache_id)."' and type = 1
                    and id in (".implode(',', array_map('intval', $remove_ids)).")
                ");
            CacheEditCommon::insert_waypoints($cache_id, $add);
            Db::execute("commit");
        } catch (\Exception $e) {
            Db::execute("rollback");
            throw $e;
        }

        $result = ['changed' => $changed];
        return Okapi::formatted_response($request, $result);
    }

    /** The cache's waypoints in the form parse_waypoints returns. */
    private static function current_waypoints($cache_id)
    {
        $rows = Db::select_all("
            select latitude, longitude, subtype, description from coordinates
            where cache_id = '".Db::escape_string($cache_id)."' and type = 1
            order by id
        ");
        return array_map(function ($r) {
            return ['lat' => floatval($r['latitude']), 'lon' => floatval($r['longitude']),
                'subtype' => (int)$r['subtype'], 'description' => $r['description']];
        }, $rows);
    }
}
