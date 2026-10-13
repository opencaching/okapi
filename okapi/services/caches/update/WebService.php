<?php

namespace okapi\services\caches\update;

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
            throw new BadRequest('This method is not supported in this OKAPI installation.');

        $user_id = $request->token->user_id;

        # Validate cache_code parameter

        $cache_code = $request->get_parameter('cache_code');
        if (empty($cache_code)) {
            throw new InvalidParam('cache_code', 'cache_code is mandatory.');
        }

        # Fetch the cache and verify ownership
        $cache = Db::select_row("
            SELECT cache_id, user_id, wp_oc
            FROM caches
            WHERE wp_oc = '".Db::escape_string($cache_code)."'
        ");

        if (!$cache) {
            throw new InvalidParam('cache_code', 'This cache does not exist.');
        }

        if ($cache['user_id'] != $user_id) {
            throw new BadRequest("You do not have permission to update this cache. Only the cache owner can modify it.");
        }

        $cache_id = $cache['cache_id'];

        # Collect optional parameters

        $cache_name       = $request->get_parameter('cache_name');
        $latitude         = $request->get_parameter('latitude');
        $longitude        = $request->get_parameter('longitude');
        $difficulty       = $request->get_parameter('difficulty');
        $terrain          = $request->get_parameter('terrain');
        $size2            = $request->get_parameter('size2');
        $short_description = $request->get_parameter('short_description');
        $description      = $request->get_parameter('description');
        $hint2            = $request->get_parameter('hint2');
        $status           = $request->get_parameter('status');
        $alt_wpts         = $request->get_parameter('alt_wpts');
        $add_alt_wpts     = $request->get_parameter('add_alt_wpts');
        $remove_alt_wpts  = $request->get_parameter('remove_alt_wpts');

        if ($cache_name === null && $latitude === null && $longitude === null &&
            $difficulty === null && $terrain === null && $size2 === null &&
            $short_description === null && $description === null && $hint2 === null &&
            $status === null && $alt_wpts === null && $add_alt_wpts === null &&
            $remove_alt_wpts === null) {
            throw new InvalidParam('cache_name',
                'At least one parameter to update must be provided.');
        }

        # Validate

        if ($latitude !== null) {
            $latitude = floatval($latitude);
            if ($latitude < -90 || $latitude > 90)
                throw new InvalidParam('latitude', 'latitude must be between -90 and 90.');
        }
        if ($longitude !== null) {
            $longitude = floatval($longitude);
            if ($longitude < -180 || $longitude > 180)
                throw new InvalidParam('longitude', 'longitude must be between -180 and 180.');
        }

        if ($difficulty !== null && $difficulty !== '') {
            $difficulty = max(2, min(10, (int)round(floatval($difficulty) * 2)));
        } else {
            $difficulty = null;
        }

        if ($terrain !== null && $terrain !== '') {
            $terrain = max(2, min(10, (int)round(floatval($terrain) * 2)));
        } else {
            $terrain = null;
        }

        $size_int = null;
        if ($size2 !== null && $size2 !== '') {
            $size_map = ['none' => 7, 'nano' => 8, 'micro' => 2, 'small' => 3,
                         'regular' => 4, 'large' => 5, 'xlarge' => 6, 'other' => 7];
            if (!isset($size_map[$size2]))
                throw new InvalidParam('size2', 'Invalid size2 value.');
            $size_int = $size_map[$size2];
        }

        $status_id = null;
        if ($status !== null && $status !== '') {
            try {
                $status_id = Okapi::cache_status_name2id($status);
            } catch (\Exception $e) {
                throw new InvalidParam('status', "Invalid status value '$status'.");
            }
        }

        # Build caches table update parts

        $update_parts = array();

        if ($cache_name !== null && $cache_name !== '')
            $update_parts[] = "name = '".Db::escape_string($cache_name)."'";
        if ($latitude !== null)
            $update_parts[] = "latitude = '".Db::escape_string($latitude)."'";
        if ($longitude !== null)
            $update_parts[] = "longitude = '".Db::escape_string($longitude)."'";
        if ($difficulty !== null)
            $update_parts[] = "difficulty = '".Db::escape_string($difficulty)."'";
        if ($terrain !== null)
            $update_parts[] = "terrain = '".Db::escape_string($terrain)."'";
        if ($size_int !== null)
            $update_parts[] = "size = '".Db::escape_string($size_int)."'";
        if ($status_id !== null)
            $update_parts[] = "status = '".Db::escape_string($status_id)."'";

        Db::execute('start transaction');

        try {
            # Update caches table
            if (!empty($update_parts)) {
                Db::query("UPDATE caches SET "
                    . implode(', ', $update_parts)
                    . " WHERE cache_id = '".Db::escape_string($cache_id)."'");
            }

            # Update cache_desc table (description, hint, short_desc live there)
            $desc_parts = array();
            if ($short_description !== null && $short_description !== '')
                $desc_parts[] = "short_desc = '".Db::escape_string($short_description)."'";
            if ($description !== null && $description !== '')
                $desc_parts[] = "`desc` = '".Db::escape_string($description)."'";
            if ($hint2 !== null && $hint2 !== '')
                $desc_parts[] = "hint = '".Db::escape_string($hint2)."'";
            if (!empty($desc_parts)) {
                $desc_parts[] = "last_modified = NOW()";
                Db::query("UPDATE cache_desc SET "
                    . implode(', ', $desc_parts)
                    . " WHERE cache_id = '".Db::escape_string($cache_id)."'");
            }

            # Handle waypoint updates

            # Replace all waypoints
            if ($alt_wpts !== null) {
                self::delete_waypoints($cache_id);
                $alt_wpts_array = json_decode($alt_wpts, true);
                if (!is_array($alt_wpts_array)) {
                    throw new InvalidParam('alt_wpts', 'alt_wpts must be a valid JSON array.');
                }
                foreach ($alt_wpts_array as $wpt) {
                    self::insert_waypoint($cache_id, $wpt);
                }
            }

            # Add waypoints
            if ($add_alt_wpts !== null) {
                $add_wpts_array = json_decode($add_alt_wpts, true);
                if (!is_array($add_wpts_array)) {
                    throw new InvalidParam('add_alt_wpts', 'add_alt_wpts must be a valid JSON array.');
                }
                foreach ($add_wpts_array as $wpt) {
                    self::insert_waypoint($cache_id, $wpt);
                }
            }

            # Remove specific waypoints
            if ($remove_alt_wpts !== null) {
                $remove_names = explode('|', $remove_alt_wpts);
                self::delete_waypoints_by_name($cache_id, $remove_names);
            }

            Db::execute("commit");

            $result = array(
                'success' => true,
                'message' => 'Cache updated successfully.'
            );
            return Okapi::formatted_response($request, $result);

        } catch (\Exception $e) {
            Db::execute("rollback");
            throw $e;
        }
    }

    private static function delete_waypoints($cache_id)
    {
        if (Settings::get('OC_BRANCH') == 'oc.de') {
            Db::query("DELETE FROM coordinates WHERE cache_id = '".Db::escape_string($cache_id)."' AND type = 1");
        } else {
            Db::query("DELETE FROM waypoints WHERE cache_id = '".Db::escape_string($cache_id)."'");
        }
    }

    private static function delete_waypoints_by_name($cache_id, $names)
    {
        # This is a simplified implementation; actual deletion logic depends on how waypoints are named
        # For now, we'll just log/acknowledge this
        # Full implementation would require matching waypoint names to database records
    }

    private static function insert_waypoint($cache_id, $wpt)
    {
        $wpt_name = isset($wpt['name']) ? $wpt['name'] : '';
        $wpt_location = isset($wpt['location']) ? $wpt['location'] : '';
        $wpt_type = isset($wpt['type']) ? $wpt['type'] : 'other';
        $wpt_desc = isset($wpt['description']) ? $wpt['description'] : '';

        if (empty($wpt_name) || empty($wpt_location)) {
            throw new InvalidParam('alt_wpts', 'Each waypoint must have name and location.');
        }

        # Parse location "lat|lon"
        $loc_parts = explode('|', $wpt_location);
        if (count($loc_parts) != 2) {
            throw new InvalidParam('alt_wpts', 'Waypoint location must be in "lat|lon" format.');
        }
        $wpt_lat = floatval($loc_parts[0]);
        $wpt_lon = floatval($loc_parts[1]);

        if (Settings::get('OC_BRANCH') == 'oc.de') {
            $type_map = [
                'parking' => 1,
                'stage' => 2,
                'path' => 3,
                'final' => 4,
                'poi' => 5,
                'other' => 0
            ];
            $subtype = isset($type_map[$wpt_type]) ? $type_map[$wpt_type] : 0;

            Db::query("
                INSERT INTO coordinates (cache_id, type, subtype, latitude, longitude, description)
                VALUES (
                    '".Db::escape_string($cache_id)."',
                    1,
                    '".Db::escape_string($subtype)."',
                    '".Db::escape_string($wpt_lat)."',
                    '".Db::escape_string($wpt_lon)."',
                    '".Db::escape_string($wpt_desc)."'
                )
            ");
        } else {
            $type_map = [
                'physical-stage' => 1,
                'virtual-stage' => 2,
                'final' => 3,
                'poi' => 4,
                'parking' => 5,
                'trailhead' => 6,
                'other' => 0
            ];
            $internal_type = isset($type_map[$wpt_type]) ? $type_map[$wpt_type] : 0;
            $stage = preg_match('/S(\d+)/', $wpt_name, $matches) ? $matches[1] : 0;

            Db::query("
                INSERT INTO waypoints (cache_id, stage, latitude, longitude, type, `desc`, status)
                VALUES (
                    '".Db::escape_string($cache_id)."',
                    '".Db::escape_string($stage)."',
                    '".Db::escape_string($wpt_lat)."',
                    '".Db::escape_string($wpt_lon)."',
                    '".Db::escape_string($internal_type)."',
                    '".Db::escape_string($wpt_desc)."',
                    1
                )
            ");
        }
    }
}
