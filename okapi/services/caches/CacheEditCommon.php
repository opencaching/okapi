<?php

namespace okapi\services\caches;

use okapi\core\Db;
use okapi\core\Exception\BadRequest;
use okapi\core\Exception\InvalidParam;
use okapi\core\Okapi;
use okapi\core\OkapiServiceRunner;
use okapi\core\Request\OkapiInternalRequest;
use okapi\core\Request\OkapiRequest;
use okapi\services\logs\LogsCommon;
use okapi\Settings;

/**
 * Shared by services/caches/create and services/caches/update, which write the
 * OCDE database layout, and by services/caches/archive|disable|enable, which
 * work on every installation.
 *
 * The rules follow the OCDE website (newcache.php, editcache.php), so a cache
 * edited here looks the same as one edited on the website.
 */
class CacheEditCommon
{
    const NOT_SUPPORTED = 'This method is not supported in this OKAPI installation. See the has_cache_editing field in services/apisrv/installation method.';

    # OCDE waypoint subtypes (coordinates_type table). OCPL waypoint types are
    # accepted as aliases, so a client can send back what it has read.
    private static $wpt_subtypes = [
        'parking' => 1,
        'stage' => 2, 'physical-stage' => 2, 'virtual-stage' => 2,
        'path' => 3, 'trailhead' => 3,
        'final' => 4,
        'poi' => 5,
    ];

    public static function require_cache_editing()
    {
        if (Settings::get('OC_BRANCH') != 'oc.de')
            throw new BadRequest(self::NOT_SUPPORTED);
    }

    /** The cache row for $cache_code, if the token's user owns it. */
    public static function get_own_cache(OkapiRequest $request, $cache_code)
    {
        if (!$cache_code)
            throw new InvalidParam('cache_code', 'cache_code is mandatory.');
        $cache = Db::select_row("
            select cache_id, user_id, wp_oc, status, name, latitude, longitude,
                difficulty, terrain, size, logpw, default_desclang
            from caches
            where wp_oc = '".Db::escape_string($cache_code)."'
        ");
        if (!$cache)
            throw new InvalidParam('cache_code', 'This cache does not exist.');
        if ($cache['user_id'] != $request->token->user_id)
            throw new BadRequest('Only the owner of this cache can change it.');
        return $cache;
    }

    /** Non-empty text of at most the column's length. */
    public static function parse_text($param, $value, $table, $column, $may_be_empty)
    {
        $value = trim($value);
        if ($value === '' && !$may_be_empty)
            throw new InvalidParam($param, 'Must not be empty.');
        $max = Db::field_length($table, $column);
        if (mb_strlen($value, 'UTF-8') > $max)
            throw new InvalidParam($param, "Must not be longer than $max characters.");
        return $value;
    }

    /** 1, 1.5, ... 5 => the stored 2..10. */
    public static function parse_rating($param, $value)
    {
        if (!is_numeric($value))
            throw new InvalidParam($param, 'Must be a number between 1 and 5.');
        $doubled = floatval($value) * 2;
        if ($doubled < 2 || $doubled > 10 || $doubled != round($doubled))
            throw new InvalidParam($param, 'Must be between 1 and 5, in steps of 0.5.');
        return (int)$doubled;
    }

    /** [lat, lon] from two parameters; 0|0 means "no coordinates" on OCDE. */
    public static function parse_location($lat, $lon)
    {
        if ($lat === null || $lon === null)
            throw new InvalidParam('latitude', 'latitude and longitude must be given together.');
        if (!is_numeric($lat) || floatval($lat) < -90 || floatval($lat) > 90)
            throw new InvalidParam('latitude', 'Must be a number between -90 and 90.');
        if (!is_numeric($lon) || floatval($lon) < -180 || floatval($lon) > 180)
            throw new InvalidParam('longitude', 'Must be a number between -180 and 180.');
        if (floatval($lat) == 0 && floatval($lon) == 0)
            throw new InvalidParam('latitude', '0|0 is not a valid location.');
        return [floatval($lat), floatval($lon)];
    }

    public static function parse_size($size2)
    {
        try {
            return Okapi::cache_size2_to_sizeid($size2);
        } catch (\Exception $e) {
            throw new InvalidParam('size2', "Unknown size '$size2'.");
        }
    }

    /**
     * The description as stored, and the value of cache_desc.desc_html. Same
     * processing as the comment of services/logs/submit: plain text is
     * escaped (line breaks kept), HTML goes through the website's HTML
     * purifier. Both are stored as HTML (desc_html = 1), as every description
     * the website writes today; desc_html = 0 is the website's old text mode.
     */
    public static function process_description($description, $format)
    {
        if (!in_array($format, ['plaintext', 'html']))
            throw new InvalidParam('description_format', "Must be 'plaintext' or 'html'.");
        list($stored, $unused) = LogsCommon::process_comment($description, $format);
        return [$stored, 1];
    }

    /** The hint as stored by the website: HTML-escaped, line breaks as <br />. */
    public static function encode_hint($hint)
    {
        return nl2br(htmlspecialchars($hint, ENT_COMPAT, 'UTF-8'));
    }

    /** Waypoints from a JSON array of {location, type, description}. */
    public static function parse_waypoints($param, $json)
    {
        $list = json_decode($json, true);
        if (!is_array($list) || ($list && array_keys($list) !== range(0, count($list) - 1)))
            throw new InvalidParam($param, 'Must be a JSON array.');
        $result = [];
        foreach ($list as $i => $wpt) {
            $n = $i + 1;
            if (!is_array($wpt) || !isset($wpt['location']) || !isset($wpt['type']))
                throw new InvalidParam($param, "Waypoint $n needs a location and a type.");
            $parts = explode('|', $wpt['location']);
            if (count($parts) != 2)
                throw new InvalidParam($param, "Waypoint $n: location must be 'lat|lon'.");
            try {
                list($lat, $lon) = self::parse_location($parts[0], $parts[1]);
            } catch (InvalidParam $e) {
                throw new InvalidParam($param, "Waypoint $n: invalid location.");
            }
            if (!isset(self::$wpt_subtypes[$wpt['type']]))
                throw new InvalidParam($param, "Waypoint $n: unknown type '".$wpt['type']."'. Use one of: "
                    .implode(', ', array_keys(self::$wpt_subtypes)).'.');
            $desc = isset($wpt['description']) ? trim((string)$wpt['description']) : '';
            $result[] = ['lat' => $lat, 'lon' => $lon, 'subtype' => self::$wpt_subtypes[$wpt['type']], 'description' => $desc];
        }
        return $result;
    }

    public static function insert_waypoints($cache_id, $waypoints)
    {
        foreach ($waypoints as $w) {
            Db::execute("
                insert into coordinates
                    (cache_id, type, subtype, latitude, longitude, description, date_created, last_modified)
                values (
                    '".Db::escape_string($cache_id)."',
                    1,
                    '".Db::escape_string($w['subtype'])."',
                    '".Db::escape_string($w['lat'])."',
                    '".Db::escape_string($w['lon'])."',
                    '".Db::escape_string($w['description'])."',
                    now(), now()
                )
            ");
        }
    }

    /**
     * Name => coordinates.id of the cache's waypoints, named exactly as
     * services/caches/geocache returns them in alt_wpts (OC1234-1, OC1234-2, ...).
     */
    public static function waypoint_ids_by_name($cache_id, $cache_code)
    {
        $known_types = Db::select_column("select id from coordinates_type");
        $rows = Db::select_all("
            select id, subtype from coordinates
            where cache_id = '".Db::escape_string($cache_id)."' and type = 1
            order by id
        ");
        $format = $cache_code."-%0".strlen(count($rows))."d";
        $result = [];
        $index = 0;
        foreach ($rows as $row) {
            if (!in_array($row['subtype'], $known_types))
                continue;  # not returned by services/caches/geocache either
            $index++;
            $result[sprintf($format, $index)] = $row['id'];
        }
        return $result;
    }

    /**
     * Archive, disable or enable a cache: a status log of the owner, written
     * by services/logs/submit, so the rules and side effects are exactly those
     * of the existing status logs on every installation.
     */
    /**
     * Status change by the owner: a status log entry via services/logs/submit.
     * Unlike logs/submit, a change to the status the cache already has is
     * refused (the website writes a status log only when the status really
     * changes), and the comment is always plain text.
     */
    public static function change_status(OkapiRequest $request, $logtype)
    {
        $cache_code = $request->get_parameter('cache_code');
        if (!$cache_code)
            throw new InvalidParam('cache_code', 'cache_code is mandatory.');

        # Same ids on OCDE and OCPL: 1 Available, 2 Temporarily unavailable, 3 Archived.
        $from = [
            'Ready to search' => [2, 3],     # 3: only where the website allows unarchiving
            'Temporarily unavailable' => [1],
            'Archived' => [1, 2],
        ][$logtype];
        if (Settings::get('OC_BRANCH') != 'oc.de')
            $from = array_diff($from, [3]);  # OCPL does not allow to unarchive

        $cache = Db::select_row("
            select user_id, status from caches
            where wp_oc = '".Db::escape_string($cache_code)."'
        ");
        # Unknown caches and other users' caches: logs/submit gives its usual answer.
        if ($cache && $cache['user_id'] == $request->token->user_id && !in_array($cache['status'], $from)) {
            $langpref = $request->get_parameter('langpref') ?: 'en';
            Okapi::gettext_domain_init(explode('|', $langpref));
            $target = ['Ready to search' => 1, 'Temporarily unavailable' => 2, 'Archived' => 3][$logtype];
            if ($cache['status'] == $target)
                $message = _("The geocache already has this status.");
            elseif (!in_array($cache['status'], [1, 2, 3]))
                $message = _("The status of this geocache can only be changed on the website.");
            elseif ($cache['status'] == 3 && $logtype == 'Ready to search')
                $message = sprintf(_("%s does not allow to unarchive geocaches."), Okapi::get_normalized_site_name());
            else
                $message = _("This status change is not possible.");
            Okapi::gettext_domain_restore();
            $result = ['success' => false, 'message' => $message, 'log_uuid' => null, 'log_uuids' => []];
            return Okapi::formatted_response($request, $result);
        }

        $params = [
            'cache_code' => $cache_code,
            'logtype' => $logtype,
            'comment' => (string)$request->get_parameter('comment'),
            'comment_format' => 'plaintext',
        ];
        $langpref = $request->get_parameter('langpref');
        if ($langpref !== null)
            $params['langpref'] = $langpref;
        $result = OkapiServiceRunner::call(
            'services/logs/submit',
            new OkapiInternalRequest($request->consumer, $request->token, $params)
        );
        return Okapi::formatted_response($request, $result);
    }
}
