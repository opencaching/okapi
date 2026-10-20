<?php

namespace okapi\services\caches\create;

use okapi\core\Db;
use okapi\core\Exception\InvalidParam;
use okapi\core\Okapi;
use okapi\core\Request\OkapiRequest;
use okapi\services\caches\CacheEditCommon;
use okapi\Settings;

class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 3
        );
    }

    public static function call(OkapiRequest $request)
    {
        # Writes the OCDE database layout; other installations would fail half-way.
        CacheEditCommon::require_cache_editing();

        $user_id = $request->token->user_id;

        # Required parameters

        $cache_name = CacheEditCommon::parse_text('cache_name',
            (string)$request->get_parameter('cache_name'), 'caches', 'name', false);

        $cache_type = $request->get_parameter('cache_type');
        if (!$cache_type)
            throw new InvalidParam('cache_type', 'cache_type is mandatory.');
        try {
            $type_id = Okapi::cache_type_name2id($cache_type);
        } catch (\Exception $e) {
            throw new InvalidParam('cache_type', "Unknown cache type '$cache_type'.");
        }

        list($latitude, $longitude) = CacheEditCommon::parse_location(
            $request->get_parameter('latitude'), $request->get_parameter('longitude'));

        # Required on the website too
        foreach (['difficulty', 'terrain', 'size2'] as $name)
            if ($request->get_parameter($name) === null)
                throw new InvalidParam($name, "$name is mandatory.");
        $diff_int = CacheEditCommon::parse_rating('difficulty', $request->get_parameter('difficulty'));
        $terr_int = CacheEditCommon::parse_rating('terrain', $request->get_parameter('terrain'));
        $size_int = CacheEditCommon::parse_size($request->get_parameter('size2'));

        # Optional parameters

        $short_desc = CacheEditCommon::parse_text('short_description',
            (string)$request->get_parameter('short_description'), 'cache_desc', 'short_desc', true);

        $description_format = $request->get_parameter('description_format') ?: 'plaintext';
        list($description, $desc_html) = CacheEditCommon::process_description(
            (string)$request->get_parameter('description'), $description_format);

        $hint = CacheEditCommon::encode_hint(trim((string)$request->get_parameter('hint2')));

        $passwd = (string)$request->get_parameter('passwd');
        if (strlen($passwd) > Db::field_length('caches', 'logpw'))
            throw new InvalidParam('passwd', 'Password too long.');

        $desc_lang = strtoupper($request->get_parameter('desc_lang') ?: 'EN');
        if (!Db::select_value("select 1 from languages where short = '".Db::escape_string($desc_lang)."'"))
            throw new InvalidParam('desc_lang', "Unknown language '$desc_lang'.");

        $country = $request->get_parameter('country');
        if ($country === null)
            $country = Db::select_value("select country from user where user_id = '".Db::escape_string($user_id)."'");
        $country = strtoupper((string)$country);
        if (!Db::select_value("select 1 from countries where short = '".Db::escape_string($country)."'"))
            throw new InvalidParam('country', $country === '' ? 'country is mandatory, because your profile has none.' : "Unknown country '$country'.");

        $date_hidden = $request->get_parameter('date_hidden') ?: date('Y-m-d');
        $d = \DateTime::createFromFormat('!Y-m-d', $date_hidden);
        if (!$d || $d->format('Y-m-d') !== $date_hidden)
            throw new InvalidParam('date_hidden', "Must be a date like '2026-10-20'.");

        $waypoints = [];
        $alt_wpts = $request->get_parameter('alt_wpts');
        if ($alt_wpts !== null && $alt_wpts !== '')
            $waypoints = CacheEditCommon::parse_waypoints('alt_wpts', $alt_wpts);

        # As on the website: a cache hidden in the future is not available yet,
        # except events (their date is the event date).
        $status = ($date_hidden > date('Y-m-d') && $cache_type != 'Event')
            ? Okapi::cache_status_name2id('Temporarily unavailable')
            : Okapi::cache_status_name2id('Available');

        $node = Settings::get('OC_NODE_ID');

        Db::execute('start transaction');

        try {
            # Same columns as the website's newcache.php. The database triggers set
            # uuid, wp_oc (from the waypoint pool), date_created, last_modified,
            # listing_last_modified, the search index and cache_coordinates. The
            # columns after `node` have no default, and OKAPI's connection runs
            # in strict SQL mode, so they are given here.
            Db::execute("
                insert into caches
                    (user_id, name, longitude, latitude, type, status, country,
                     date_hidden, date_activate, size, difficulty, terrain,
                     logpw, search_time, way_length, wp_gc, node,
                     meta_last_modified, wp_gc_maintained, wp_nc, desc_languages,
                     default_desclang, show_cachelists, protect_old_coords,
                     needs_maintenance, listing_outdated, flags_last_modified)
                values (
                    '".Db::escape_string($user_id)."',
                    '".Db::escape_string($cache_name)."',
                    '".Db::escape_string($longitude)."',
                    '".Db::escape_string($latitude)."',
                    '".Db::escape_string($type_id)."',
                    '".Db::escape_string($status)."',
                    '".Db::escape_string($country)."',
                    '".Db::escape_string($date_hidden)."',
                    null,
                    '".Db::escape_string($size_int)."',
                    '".Db::escape_string($diff_int)."',
                    '".Db::escape_string($terr_int)."',
                    '".Db::escape_string($passwd)."',
                    0, 0, '',
                    '".Db::escape_string($node)."',
                    now(), '', '', '',
                    '".Db::escape_string($desc_lang)."', 1, 0,
                    0, 0, now()
                )
            ");
            $cache_id = Db::last_insert_id();
            $cache_code = Db::select_value("
                select wp_oc from caches where cache_id = '".Db::escape_string($cache_id)."'
            ");

            Db::execute("
                insert into cache_desc
                    (cache_id, language, `desc`, desc_html, hint, short_desc,
                     last_modified, desc_htmledit, node)
                values (
                    '".Db::escape_string($cache_id)."',
                    '".Db::escape_string($desc_lang)."',
                    '".Db::escape_string($description)."',
                    '".Db::escape_string($desc_html)."',
                    '".Db::escape_string($hint)."',
                    '".Db::escape_string($short_desc)."',
                    now(), 0,
                    '".Db::escape_string($node)."'
                )
            ");

            CacheEditCommon::insert_waypoints($cache_id, $waypoints);

            Db::execute("commit");
        } catch (\Exception $e) {
            Db::execute("rollback");
            throw $e;
        }

        $result = array(
            'cache_code' => $cache_code,
            'url' => Settings::get('SITE_URL')."viewcache.php?wp=".$cache_code,
        );
        return Okapi::formatted_response($request, $result);
    }
}
