<?php

namespace okapi\services\caches\create;

use okapi\core\Db;
use okapi\core\Exception\BadRequest;
use okapi\core\Exception\InvalidParam;
use okapi\core\Okapi;
use okapi\core\Request\OkapiRequest;
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
        if (Settings::get('OC_BRANCH') != 'oc.de')
            throw new BadRequest('This method is not supported in this OKAPI installation. See the has_cache_editing field in services/apisrv/installation method.');

        $user_id = $request->token->user_id;

        # Required parameters

        $cache_name = $request->get_parameter('cache_name');
        if (empty($cache_name))
            throw new InvalidParam('cache_name', 'cache_name is mandatory and must not be empty.');
        if (strlen($cache_name) > 255)
            throw new InvalidParam('cache_name', 'cache_name must not exceed 255 characters.');

        $cache_type = $request->get_parameter('cache_type');
        if (empty($cache_type))
            throw new InvalidParam('cache_type', 'cache_type is mandatory.');
        try {
            $type_id = Okapi::cache_type_name2id($cache_type);
        } catch (\Exception $e) {
            throw new InvalidParam('cache_type', "Unknown cache type '$cache_type'.");
        }

        $latitude = $request->get_parameter('latitude');
        $longitude = $request->get_parameter('longitude');
        if ($latitude === null || $longitude === null)
            throw new InvalidParam('latitude', 'latitude and longitude are mandatory.');
        $latitude = floatval($latitude);
        $longitude = floatval($longitude);
        if ($latitude < -90 || $latitude > 90)
            throw new InvalidParam('latitude', 'latitude must be between -90 and 90.');
        if ($longitude < -180 || $longitude > 180)
            throw new InvalidParam('longitude', 'longitude must be between -180 and 180.');

        # Optional parameters

        # difficulty/terrain stored as 2-10 (= rating * 2; 1.0=2, 2.5=5, 5.0=10)
        $difficulty = $request->get_parameter('difficulty');
        $diff_int = $difficulty !== null && $difficulty !== ''
            ? max(2, min(10, (int)round(floatval($difficulty) * 2)))
            : 4; # default 2.0

        $terrain = $request->get_parameter('terrain');
        $terr_int = $terrain !== null && $terrain !== ''
            ? max(2, min(10, (int)round(floatval($terrain) * 2)))
            : 4; # default 2.0

        # size: stored as tinyint matching cache_size table
        $size2 = $request->get_parameter('size2');
        $size_map = ['none' => 7, 'nano' => 8, 'micro' => 2, 'small' => 3, 'regular' => 4, 'large' => 5, 'xlarge' => 6, 'other' => 7];
        $size_int = ($size2 && isset($size_map[$size2])) ? $size_map[$size2] : 3;

        $short_desc  = (string)($request->get_parameter('short_description') ?? '');
        $description = (string)($request->get_parameter('description') ?? '');
        $hint2       = (string)($request->get_parameter('hint2') ?? '');
        $country     = (string)($request->get_parameter('country') ?? 'DE');
        $desc_lang   = strtoupper((string)($request->get_parameter('desc_lang') ?? 'EN'));

        $date_hidden = $request->get_parameter('date_hidden') ?: date('Y-m-d');

        $now = date('Y-m-d H:i:s');

        Db::execute('start transaction');

        try {
            # Minimal INSERT — mirrors Symfony's newCache controller exactly.
            # Database triggers handle: uuid, wp_oc (from waypoint pool), date_created,
            # last_modified, listing_last_modified, search index, cache_coordinates, etc.
            # Fields not set by the BEFORE INSERT trigger (meta_last_modified,
            # wp_gc_maintained, wp_nc, desc_languages, default_desclang,
            # show_cachelists, protect_old_coords, needs_maintenance,
            # listing_outdated, flags_last_modified) must be supplied explicitly
            # because OKAPI's DB connection runs in strict SQL mode.
            Db::query("
                INSERT INTO caches
                    (user_id, name, longitude, latitude, type, status, country,
                     date_hidden, date_activate, size, difficulty, terrain,
                     logpw, search_time, way_length, wp_gc, node,
                     meta_last_modified, wp_gc_maintained, wp_nc, desc_languages,
                     default_desclang, show_cachelists, protect_old_coords,
                     needs_maintenance, listing_outdated, flags_last_modified)
                VALUES (
                    '".Db::escape_string($user_id)."',
                    '".Db::escape_string($cache_name)."',
                    '".Db::escape_string($longitude)."',
                    '".Db::escape_string($latitude)."',
                    '".Db::escape_string($type_id)."',
                    1,
                    '".Db::escape_string($country)."',
                    '".Db::escape_string($date_hidden)."',
                    '".Db::escape_string($now)."',
                    '".Db::escape_string($size_int)."',
                    '".Db::escape_string($diff_int)."',
                    '".Db::escape_string($terr_int)."',
                    '', 0, 0, '', 4,
                    NOW(), '', '', '',
                    '".Db::escape_string($desc_lang)."', 1, 0,
                    0, 0, NOW()
                )
            ");

            $cache_id = Db::last_insert_id();

            # Fetch the wp_oc that the trigger assigned from the waypoint pool
            $cache_code = Db::select_value("
                SELECT wp_oc FROM caches WHERE cache_id = '".Db::escape_string($cache_id)."'
            ");

            # Insert description into cache_desc (separate table, not in caches)
            Db::query("
                INSERT INTO cache_desc
                    (cache_id, language, `desc`, desc_html, hint, short_desc,
                     last_modified, desc_htmledit, node)
                VALUES (
                    '".Db::escape_string($cache_id)."',
                    '".Db::escape_string($desc_lang)."',
                    '".Db::escape_string($description)."',
                    0,
                    '".Db::escape_string($hint2)."',
                    '".Db::escape_string($short_desc)."',
                    '".Db::escape_string($now)."',
                    0,
                    4
                )
            ");

            # Handle alternative waypoints
            $alt_wpts_json = $request->get_parameter('alt_wpts');
            $alt_wpts_count = 0;

            if ($alt_wpts_json) {
                $alt_wpts = json_decode($alt_wpts_json, true);
                if (!is_array($alt_wpts))
                    throw new InvalidParam('alt_wpts', 'alt_wpts must be a valid JSON array.');

                foreach ($alt_wpts as $wpt) {
                    $wpt_location = isset($wpt['location']) ? $wpt['location'] : '';
                    $wpt_type     = isset($wpt['type'])     ? $wpt['type']     : 'other';
                    $wpt_desc     = isset($wpt['description']) ? $wpt['description'] : '';

                    if (empty($wpt_location))
                        throw new InvalidParam('alt_wpts', 'Each waypoint must have a location.');
                    $loc_parts = explode('|', $wpt_location);
                    if (count($loc_parts) != 2)
                        throw new InvalidParam('alt_wpts', 'Waypoint location must be in "lat|lon" format.');

                    $wpt_lat = floatval($loc_parts[0]);
                    $wpt_lon = floatval($loc_parts[1]);
                    $subtype = self::get_wpt_subtype($wpt_type);

                    # coordinates table is used by both OCDE and this installation
                    Db::query("
                        INSERT INTO coordinates
                            (cache_id, type, subtype, latitude, longitude, description,
                             date_created, last_modified)
                        VALUES (
                            '".Db::escape_string($cache_id)."',
                            1,
                            '".Db::escape_string($subtype)."',
                            '".Db::escape_string($wpt_lat)."',
                            '".Db::escape_string($wpt_lon)."',
                            '".Db::escape_string($wpt_desc)."',
                            '".Db::escape_string($now)."',
                            '".Db::escape_string($now)."'
                        )
                    ");
                    $alt_wpts_count++;
                }
            }

            Db::execute("commit");

            $site_url = Settings::get('SITE_URL');
            $url = $site_url . 'cache.php?id=' . urlencode($cache_code);

            $result = array(
                'success'        => true,
                'message'        => 'Cache created successfully.',
                'cache_code'     => $cache_code,
                'cache_id'       => $cache_id,
                'alt_wpts_count' => $alt_wpts_count,
                'url'            => $url,
            );
            return Okapi::formatted_response($request, $result);

        } catch (\Exception $e) {
            Db::execute("rollback");
            throw $e;
        }
    }

    private static function get_wpt_subtype($okapi_type)
    {
        $type_map = [
            'parking'        => 1,
            'stage'          => 2,
            'path'           => 3,
            'final'          => 4,
            'poi'            => 5,
            'physical-stage' => 2,
            'virtual-stage'  => 2,
            'trailhead'      => 3,
            'other'          => 0,
        ];
        return isset($type_map[$okapi_type]) ? $type_map[$okapi_type] : 0;
    }
}
