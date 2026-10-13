<?php

namespace okapi\services\caches\save_user_coords;

use okapi\core\Db;
use okapi\core\Exception\ParamMissing;
use okapi\core\Exception\InvalidParam;
use okapi\core\Okapi;
use okapi\core\OkapiServiceRunner;
use okapi\core\Request\OkapiInternalRequest;
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

        $user_coords = $request->get_parameter('user_coords');
        if ($user_coords === null)
            throw new ParamMissing('user_coords');
        $remove = ($user_coords === '');
        $latitude = $longitude = null;
        if (!$remove)
            list($latitude, $longitude) = self::parse_coords($user_coords);

        # Verify cache_code

        $cache_code = $request->get_parameter('cache_code');
        if ($cache_code == null)
            throw new ParamMissing('cache_code');
        $geocache = OkapiServiceRunner::call(
            'services/caches/geocache',
            new OkapiInternalRequest($request->consumer, $request->token, array(
                'cache_code' => $cache_code,
                'fields' => 'internal_id|type'
            ))
        );
        $cache_id = $geocache['internal_id'];

        if ($remove) {
            self::remove_coordinates($cache_id, $request->token->user_id);
        } else {
            self::validate_cache_type($geocache['type']);
            self::update_coordinates($cache_id, $request->token->user_id, $latitude, $longitude);
        }

        # Report the stored state, read back the same way services/caches/geocache does.

        $geocache = OkapiServiceRunner::call(
            'services/caches/geocache',
            new OkapiInternalRequest($request->consumer, $request->token, array(
                'cache_code' => $cache_code,
                'fields' => 'my_coords'
            ))
        );
        $result = array(
            'success' => true,
            'my_coords' => $geocache['my_coords'],
        );
        return Okapi::formatted_response($request, $result);
    }

    private static function parse_coords($user_coords)
    {
        $parts = explode('|', $user_coords);
        if (count($parts) != 2)
            throw new InvalidParam('user_coords', "Expecting 2 pipe-separated parts, got ".count($parts).".");
        foreach ($parts as &$part_ref)
        {
            if (!preg_match("/^-?[0-9]+(\.?[0-9]*)$/", $part_ref))
                throw new InvalidParam('user_coords', "'$part_ref' is not a valid float number.");
            $part_ref = floatval($part_ref);
        }
        list($latitude, $longitude) = $parts;
        if ($latitude < -90 || $latitude > 90)
            throw new InvalidParam('user_coords', "Latitude '$latitude' is out of range (-90 to 90).");
        if ($longitude < -180 || $longitude > 180)
            throw new InvalidParam('user_coords', "Longitude '$longitude' is out of range (-180 to 180).");
        if ($latitude == 0 && $longitude == 0)
            throw new InvalidParam('user_coords', "'0|0' is not a valid location. Use an empty string to remove the coordinates.");
        return array($latitude, $longitude);
    }

    private static function validate_cache_type($cache_type)
    {
        if (Settings::get('OC_BRANCH') != 'oc.pl') {
            return;
        }

        $allowed_types = array('Other', 'Quiz', 'Multi');

        if (!in_array($cache_type, $allowed_types, true)) {
            throw new InvalidParam(
                'cache_code',
                "User coordinates are not supported for cache type '$cache_type'."
            );
        }
    }

    private static function remove_coordinates($cache_id, $user_id)
    {
        if (Settings::get('OC_BRANCH') == 'oc.de')
        {
            # The type-2 row also holds the personal note. Like the website
            # (HandlerCacheNote), drop the row only if the note is empty too,
            # otherwise keep the note and store 0/0 ("no coordinates").

            Db::query("
                delete from coordinates
                where
                    type = 2
                    and cache_id = '".Db::escape_string($cache_id)."'
                    and user_id = '".Db::escape_string($user_id)."'
                    and (description is null or description = '')
            ");
            Db::query("
                update coordinates
                set latitude = 0, longitude = 0
                where
                    type = 2
                    and cache_id = '".Db::escape_string($cache_id)."'
                    and user_id = '".Db::escape_string($user_id)."'
            ");
        }
        else # oc.pl branch
        {
            Db::query("
                delete from cache_mod_cords
                where
                    cache_id = '".Db::escape_string($cache_id)."'
                    and user_id = '".Db::escape_string($user_id)."'
            ");
        }
    }

    private static function update_coordinates($cache_id, $user_id, $latitude, $longitude)
    {
        if (Settings::get('OC_BRANCH') == 'oc.de')
        {

            /* See:
             *
             * - https://github.com/OpencachingDeutschland/oc-server3/tree/development/htdocs/src/Oc/Libse/CacheNote
             * - https://www.opencaching.de/okapi/devel/dbstruct
             */

            $rs = Db::query("
                select max(id) as id
                from coordinates
                where
                    type = 2  -- personal note
                    and cache_id = '".Db::escape_string($cache_id)."'
                    and user_id = '".Db::escape_string($user_id)."'
            ");
            $id = null;
            if($row = Db::fetch_assoc($rs)) {
                $id = $row['id'];
            }
            if ($id == null) {
                Db::query("
                    insert into coordinates (
                        type, latitude, longitude, cache_id, user_id, description
                    ) values (
                        2,
                        '".Db::escape_string($latitude)."',
                        '".Db::escape_string($longitude)."',
                        '".Db::escape_string($cache_id)."',
                        '".Db::escape_string($user_id)."',
                        ''
                    )
                ");
            } else {
                Db::query("
                    update coordinates
                    set latitude  = '".Db::escape_string($latitude)."',
                        longitude = '".Db::escape_string($longitude)."'
                    where
                        id = '".Db::escape_string($id)."'
                        and type = 2
                ");
            }
        }
        else # oc.pl branch
        {
            # cache_mod_cords uses a composite PK (cache_id, user_id) — no id column.
            Db::query("
                INSERT INTO cache_mod_cords (
                    cache_id,
                    user_id,
                    latitude,
                    longitude,
                    date
                ) VALUES (
                    '".Db::escape_string($cache_id)."',
                    '".Db::escape_string($user_id)."',
                    '".Db::escape_string($latitude)."',
                    '".Db::escape_string($longitude)."',
                    NOW()
                )
                ON DUPLICATE KEY UPDATE
                    latitude = VALUES(latitude),
                    longitude = VALUES(longitude),
                    date = NOW()
            ");
        }
    }
}
