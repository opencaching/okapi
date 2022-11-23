<?php

namespace okapi\services\caches\geocaches;

use ArrayObject;
use Exception;
use okapi\core\Db;
use okapi\core\Exception\BadRequest;
use okapi\core\Exception\InvalidParam;
use okapi\core\Exception\ParamMissing;
use okapi\core\Okapi;
use okapi\core\OkapiServiceRunner;
use okapi\core\Request\OkapiInternalRequest;
use okapi\core\Request\OkapiRequest;
use okapi\services\attrs\AttrHelper;
use okapi\Settings;

class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 1
        );
    }

    private static $valid_field_names = array('code', 'name', 'names', 'location', 'type',
        'status', 'url', 'owner', 'distance', 'bearing', 'bearing2', 'bearing3', 'is_found',
        'is_not_found', 'founds', 'notfounds', 'size', 'size2', 'oxsize', 'difficulty', 'terrain',
        'rating', 'rating_votes', 'recommendations', 'req_passwd', 'description',
        'descriptions', 'hint', 'hints', 'images', 'attr_acodes', 'attrnames', 'latest_logs',
        'my_notes', 'trackables_count', 'trackables', 'alt_wpts', 'last_found',
        'last_modified', 'date_created', 'date_hidden', 'internal_id', 'is_watched',
        'is_ignored', 'willattends',
        'country', 'country2', 'state', 'region',
        'preview_image', 'trip_time', 'trip_distance', 'attribution_note','gc_code', 'hint2', 'hints2',
        'protection_areas', 'short_description', 'short_descriptions', 'needs_maintenance',
        'watchers', 'my_rating', 'is_recommended', 'oc_team_annotation');

    public static function call(OkapiRequest $request)
    {
        $cache_codes = $request->get_parameter('cache_codes');
        if ($cache_codes === null) throw new ParamMissing('cache_codes');
        if ($cache_codes === "")
        {
            # Issue 106 requires us to allow empty list of cache codes to be passed into this method.
            # All of the queries below have to be ready for $cache_codes to be empty!
            $cache_codes = array();
        }
        else
            $cache_codes = explode("|", $cache_codes);

        if ((count($cache_codes) > 500) && (!$request->skip_limits))
            throw new InvalidParam('cache_codes', "Maximum allowed number of referenced ".
                "caches is 500. You provided ".count($cache_codes)." cache codes.");
        if (count($cache_codes) != count(array_unique($cache_codes)))
            throw new InvalidParam('cache_codes', "Duplicate codes detected (make sure each cache is referenced only once).");

        $langpref = $request->get_parameter('langpref');
        if (!$langpref) $langpref = "en";
        $langprefs = explode("|", $langpref);

        $fields = $request->get_parameter('fields');
        if (!$fields) $fields = "code|name|location|type|status";
        $fields = explode("|", $fields);
        foreach ($fields as $field)
            if (!in_array($field, self::$valid_field_names))
                throw new InvalidParam('fields', "'$field' is not a valid field code.");

        $owner_fields = $request->get_parameter('owner_fields');
        if (!$owner_fields) $owner_fields = "uuid|username|profile_url";  // validation is done on call
        $owner_fields = explode('|', $owner_fields);

        # Some fields need to be temporarily included whenever a description-
        # related field is included. That's a little ugly, but helps performance
        # and conforms to the DRY rule.

        $fields_to_remove_later = array();
        $owner_fields_to_remove_later = array();
        if (
            in_array('description', $fields) || in_array('descriptions', $fields)
            || in_array('editable_description', $fields) || in_array('editable_descriptions', $fields)
            || in_array('short_description', $fields) || in_array('short_descriptions', $fields)
            || in_array('hint', $fields) || in_array('hints', $fields)
            || in_array('hint2', $fields) || in_array('hints2', $fields)
            || in_array('attribution_note', $fields) || in_array('oc_team_annotation', $fields)
        )
        {
            if (!in_array('internal_id', $fields))
            {
                $fields[] = "internal_id";
                $fields_to_remove_later[] = "internal_id";
            }
            if (!in_array('owner', $fields))
            {
                $fields[] = "owner";
                $fields_to_remove_later[] = "owner";
            }
            if (!in_array('username', $owner_fields))
            {
                $owner_fields[] = "username";
                $owner_fields_to_remove_later[] = "username";
            }
            if (!in_array('profile_url', $owner_fields))
            {
                $owner_fields[] = "profile_url";
                $owner_fields_to_remove_later[] = "profile_url";
            }
        }

        $attribution_append = $request->get_parameter('attribution_append');
        if (!$attribution_append) $attribution_append = 'full';
        if (!in_array($attribution_append, array('none', 'static', 'full')))
            throw new InvalidParam('attribution_append');

        $oc_team_annotation = $request->get_parameter('oc_team_annotation');
        if (!$oc_team_annotation) $oc_team_annotation = 'description';
        if (!in_array($oc_team_annotation, array('description', 'separate')))
            throw new InvalidParam('oc_team_annotation');
        if ($oc_team_annotation == 'separate' && !in_array('oc_team_annotation', $fields))
            $fields[] = 'oc_team_annotation';

        $user_uuid = $request->get_parameter('user_uuid');
        if ($user_uuid != null)
        {
            $user_id = Db::select_value("select user_id from user where uuid='".Db::escape_string($user_uuid)."'");
            if ($user_id == null)
                throw new InvalidParam('user_uuid', "User not found.");
            if (($request->token != null) && ($request->token->user_id != $user_id))
                throw new InvalidParam('user_uuid', "User does not match the Access Token used.");
        }
        elseif (($user_uuid == null) && ($request->token != null))
            $user_id = $request->token->user_id;
        else
            $user_id = null;

        $log_fields = $request->get_parameter('log_fields');
        if (!$log_fields) $log_fields = "uuid|date|user|type|comment";  // validation is done on call
        $log_user_fields = $request->get_parameter('log_user_fields');
        if (!$log_user_fields) $log_user_fields = "uuid|username|profile_url";  // validation is done on call

        $lpc = $request->get_parameter('lpc');
        if ($lpc === null) $lpc = 10;
        if ($lpc == 'all')
            $lpc = null;
        else
        {
            if (!is_numeric($lpc))
                throw new InvalidParam('lpc', "Invalid number: '$lpc'");
            $lpc = intval($lpc);
            if ($lpc < 0)
                throw new InvalidParam('lpc', "Must be a positive value.");
        }

        $user_logs_only = $request->get_parameter('user_logs_only');
        if ($user_logs_only === null)
            $user_logs_only = 'false';
        elseif (!in_array($user_logs_only, array('true', 'false')))
            throw new InvalidParam('user_logs_only', "Unknown option: '$user_logs_only'.");

        if (in_array('distance', $fields) || in_array('bearing', $fields) || in_array('bearing2', $fields)
            || in_array('bearing3', $fields))
        {
            $tmp = $request->get_parameter('my_location');
            if (!$tmp)
                throw new BadRequest("When using 'distance' or 'bearing' fields, you have to supply 'my_location' parameter.");
            $parts = explode('|', $tmp);
            if (count($parts) != 2)
                throw new InvalidParam('my_location', "Expecting 2 pipe-separated parts, got ".count($parts).".");
            foreach ($parts as &$part_ref)
            {
                if (!preg_match("/^-?[0-9]+(\.?[0-9]*)$/", $part_ref))
                    throw new InvalidParam('my_location', "'$part_ref' is not a valid float number.");
                $part_ref = floatval($part_ref);
            }
            list($center_lat, $center_lon) = $parts;
            if ($center_lat > 90 || $center_lat < -90)
                throw new InvalidParam('current_position', "Latitudes have to be within -90..90 range.");
            if ($center_lon > 180 || $center_lon < -180)
                throw new InvalidParam('current_position', "Longitudes have to be within -180..180 range.");
        }

        if (Settings::get('OC_BRANCH') == 'oc.de')
        {
            # DE branch:
            # - Caches do not have ratings.
            # - Total numbers of founds and notfounds are kept in the "stat_caches" table.
            # - search_time and way_length are both round trip values and cannot be null;
            #     0 = not specified
            # - will-attend-count is stored in separate field

            $rs = Db::query("
                select
                    c.cache_id, c.name, c.longitude, c.latitude, c.listing_last_modified as last_modified,
                    c.date_created, c.type, c.status, c.date_hidden, c.size, c.difficulty,
                    c.terrain, c.wp_oc, c.wp_gc, c.wp_gc_maintained, c.logpw, c.user_id,
                    if(c.search_time=0, null, c.search_time) as trip_time,
                    if(c.way_length=0, null, c.way_length) as trip_distance,
                    c.listing_outdated, c.needs_maintenance,
                    ifnull(sc.toprating, 0) as topratings,
                    ifnull(sc.found, 0) as founds,
                    ifnull(sc.notfound, 0) as notfounds,
                    ifnull(sc.will_attend, 0) as willattends,
                    ifnull(sc.watch, 0) as watchers,
                    sc.last_found,
                    0 as votes, 0 as score
                    -- SEE ALSO OC.PL BRANCH BELOW
                from
                    caches c
                    left join stat_caches as sc on c.cache_id = sc.cache_id
                where
                    wp_oc in ('".implode("','", array_map('\okapi\core\Db::escape_string', $cache_codes))."')
                    and status in (1,2,3)
            ");
        }
        elseif (Settings::get('OC_BRANCH') == 'oc.pl')
        {
            # PL branch:
            # - Caches have ratings.
            # - Total numbers of found and notfounds are kept in the "caches" table.
            # - search_time is round trip and way_length one way or both ways (this is different on OCDE!);
            #   both can be null; 0 or null = not specified
            # - will-attend-count is stored in caches.notfounds

            $rs = Db::query("
                select
                    c.cache_id, c.name, c.longitude, c.latitude, c.last_modified,
                    c.date_created, c.type, c.status, c.date_hidden, c.size, c.difficulty,
                    c.terrain, c.wp_oc, c.wp_gc, '' as wp_gc_maintained, c.logpw, c.user_id,
                    if(c.search_time=0, null, c.search_time) as trip_time,
                    if(c.way_length=0, null, c.way_length) as trip_distance,
                    0 as listing_outdated, 0 as needs_maintenance,
                    c.topratings,
                    c.founds,
                    c.notfounds,
                    c.notfounds as willattends,
                    c.watcher as watchers,
                    c.last_found,
                    c.votes, c.score
                    -- SEE ALSO OC.DE BRANCH ABOVE
                from
                    caches c
                where
                    wp_oc in ('".implode("','", array_map('\okapi\core\Db::escape_string', $cache_codes))."')
                    and c.status in (1,2,3)
            ");
        }

        $results = new ArrayObject();
        $cacheid2wptcode = array();
        $owner_ids = array();
        $outdated_listings = array();
        while ($row = Db::fetch_assoc($rs))
        {
            $entry = array();
            $cacheid2wptcode[$row['cache_id']] = $row['wp_oc'];
            foreach ($fields as $field)
            {
                switch ($field)
                {
                    case 'code': $entry['code'] = $row['wp_oc']; break;
                    case 'gc_code':
                        $wp_gc = $row['wp_gc_maintained'] ? $row['wp_gc_maintained'] : $row['wp_gc'];
                        // OC software allows entering anything here, and that's what users do.
                        // We do a formal verification so that only a valid GC code is returned:
                        if (preg_match('/^\s*[Gg][Cc][A-Za-z0-9]+\s*$/', $wp_gc))
                            $entry['gc_code'] = strtoupper(trim($wp_gc));
                        else
                            $entry['gc_code'] = null;
                        unset($wp_gc);
                        break;
                    case 'name': $entry['name'] = $row['name']; break;
                    case 'names': $entry['names'] = array(Settings::get('SITELANG') => $row['name']); break; // for the future
                    case 'location': $entry['location'] = Okapi::coords2latlon($row['latitude'], $row['longitude']); break;
                    case 'type': /* handled below */ break;
                    case 'status': $entry['status'] = Okapi::cache_status_id2name($row['status']); break;
                    case 'needs_maintenance': $entry['needs_maintenance'] = $row['needs_maintenance'] > 0; break;
                    case 'url': $entry['url'] = Settings::get('SITE_URL')."viewcache.php?wp=".$row['wp_oc']; break;
                    case 'owner':
                        $owner_ids[$row['wp_oc']] = $row['user_id'];
                        /* continued later */
                        break;
                    case 'distance':
                        $entry['distance'] = (int)Okapi::get_distance($center_lat, $center_lon, $row['latitude'], $row['longitude']);
                        break;
                    case 'bearing':
                        $tmp = Okapi::get_bearing($center_lat, $center_lon, $row['latitude'], $row['longitude']);
                        $entry['bearing'] = ($tmp !== null) ? ((int)(10*$tmp)) / 10.0 : null;
                        break;
                    case 'bearing2':
                        $tmp = Okapi::get_bearing($center_lat, $center_lon, $row['latitude'], $row['longitude']);
                        $entry['bearing2'] = Okapi::bearing_as_two_letters($tmp);
                        break;
                    case 'bearing3':
                        $tmp = Okapi::get_bearing($center_lat, $center_lon, $row['latitude'], $row['longitude']);
                        $entry['bearing3'] = Okapi::bearing_as_three_letters($tmp);
                        break;
                    case 'is_found': /* handled separately */ break;
                    case 'is_not_found': /* handled separately */ break;
                    case 'is_watched': /* handled separately */ break;
                    case 'is_ignored': /* handled separately */ break;
                    case 'my_rating': /* handled separately */ break;
                    case 'is_recommended': /* handled separately */ break;
                    case 'founds': $entry['founds'] = $row['founds'] + 0; break;
                    case 'notfounds':
                        if ($row['type'] != 6) {  # non-event
                            $entry['notfounds'] = $row['notfounds'] + 0;
                        } else {  # event
                            $entry['notfounds'] = 0;
                        }
                        break;
                    case 'willattends':
                        if ($row['type'] == 6) {  # event
                            $entry['willattends'] = $row['willattends'] + 0;
                        } else {  # non-event
                            $entry['willattends'] = 0;
                        }
                        break;
                    case 'watchers': $entry['watchers'] = $row['watchers'] + 0; break;
                    case 'size':
                        # Deprecated. Leave it for backward-compatibility. See issue 155.
                        switch (Okapi::cache_sizeid_to_size2($row['size']))
                        {
                            case 'none': $entry['size'] = null; break;
                            case 'nano': $entry['size'] = 1.0; break;  # same as micro
                            case 'micro': $entry['size'] = 1.0; break;
                            case 'small': $entry['size'] = 2.0; break;
                            case 'regular': $entry['size'] = 3.0; break;
                            case 'large': $entry['size'] = 4.0; break;
                            case 'xlarge': $entry['size'] = 5.0; break;
                            case 'other': $entry['size'] = null; break;  # same as none
                            default: throw new Exception();
                        }
                        break;
                    case 'size2': $entry['size2'] = Okapi::cache_sizeid_to_size2($row['size']); break;
                    case 'oxsize': $entry['oxsize'] = Okapi::cache_size2_to_oxsize(Okapi::cache_sizeid_to_size2($row['size'])); break;
                    case 'difficulty': $entry['difficulty'] = round($row['difficulty'] / 2.0, 1); break;
                    case 'terrain': $entry['terrain'] = round($row['terrain'] / 2.0, 1); break;
                    case 'trip_time':
                        # search time is entered in hours:minutes and converted to decimal hours,
                        # which can produce lots of unneeded decimal places; 2 of them are sufficient here
                        $entry['trip_time'] = $row['trip_time'] === null ? null : round($row['trip_time'],2); break;
                        break;
                    case 'trip_distance':
                        # way length is entered in km as decimal fraction, but number conversions can
                        # create fake digits which should be stripped; meter precision is sufficient here
                        $entry['trip_distance'] = $row['trip_distance'] === null ? null : round($row['trip_distance'],3); break;
                        break;
                    case 'rating':
                        if ($row['votes'] < 3)
                            $entry['rating'] = null;
                        else
                            $entry['rating'] = Okapi::decode_geocache_rating($row['score']);
                        break;
                    case 'rating_votes': $entry['rating_votes'] = $row['votes'] + 0; break;
                    case 'recommendations': $entry['recommendations'] = $row['topratings'] + 0; break;
                    case 'req_passwd': $entry['req_passwd'] = $row['logpw'] ? true : false; break;
                    case 'short_description': /* handled separately */ break;
                    case 'short_descriptions': /* handled separately */ break;
                    case 'description': /* handled separately */ break;
                    case 'descriptions': /* handled separately */ break;
                    case 'editable_description': /* handled separately */ break;
                    case 'editable_descriptions': /* handled separately */ break;
                    case 'hint': /* handled separately */ break;
                    case 'hints': /* handled separately */ break;
                    case 'hint2': /* handled separately */ break;
                    case 'hints2': /* handled separately */ break;
                    case 'images': /* handled separately */ break;
                    case 'preview_image': /* handled separately */ break;
                    case 'attr_acodes': /* handled separately */ break;
                    case 'attrnames': /* handled separately */ break;
                    case 'latest_logs': /* handled separately */ break;
                    case 'my_notes': /* handles separately */ break;
                    case 'trackables_count': /* handled separately */ break;
                    case 'trackables': /* handled separately */ break;
                    case 'alt_wpts': /* handled separately */ break;
                    case 'country': /* handled separately */ break;
                    case 'country2': /* handled separately */ break;
                    case 'country_code': /* handled separately */ break;
                    case 'state': /* handled separately */ break;
                    case 'region': /* handled separately */ break;
                    case 'region_code': /* handled separately */ break;
                    case 'oc_team_annotation': /* handled separately */ break;
                    case 'last_found': $entry['last_found'] = ($row['last_found'] > '1980') ? date('c', strtotime($row['last_found'])) : null; break;
                    case 'last_modified': $entry['last_modified'] = date('c', strtotime($row['last_modified'])); break;
                    case 'date_created': $entry['date_created'] = date('c', strtotime($row['date_created'])); break;
                    case 'date_hidden': $entry['date_hidden'] = date('c', strtotime($row['date_hidden'])); break;
                    case 'internal_id': $entry['internal_id'] = $row['cache_id']; break;
                    case 'attribution_note': /* handled separately */ break;
                    case 'protection_areas': /* handled separately */ break;
                    default: throw new Exception("Missing field case: ".$field);
                }
            }

            # type

            if (array_intersect(['type', 'attr_acodes', 'attrnames'], $fields))
            {
                $type_name = Okapi::cache_type_id2name($row['type']);
                $mapto = Okapi::map_cache_type($type_name);
                if (in_array('type', $fields))
                    $entry['type'] = $mapto['name'];
                if (in_array('attr_acodes', $fields) || in_array('attrnames', $fields))
                    $entry['attr_acodes'] = $mapto['acodes'];
            }

            $results[$row['wp_oc']] = $entry;
            if ($row['listing_outdated'] > 0)
                $outdated_listings[] = $row['wp_oc'];
        }
        Db::free_result($rs);

        # owner

        if (in_array('owner', $fields) && count($results) > 0)
        {
            # If the user only requests default fields or other "basic fields"
            # which can easily be handled, we will directly serve the request.
            # Otherwise we call the more expensive users/by_internal_ids method.

            $basic_owner_fields = ['uuid', 'username', 'profile_url', 'internal_id'];

            if (count(array_diff($owner_fields, $basic_owner_fields)) == 0)
            {
                $rs = Db::query("
                    select user_id, uuid, username
                    from user
                    where user_id in ('".implode("','", array_map('\okapi\core\Db::escape_string', array_values($owner_ids)))."')
                ");
                $tmp = array();
                while ($row = Db::fetch_assoc($rs))
                    $tmp[$row['user_id']] = $row;
                Db::free_result($rs);

                foreach ($results as $cache_code => &$result_ref)
                {
                    if (isset($tmp[$owner_ids[$cache_code]])) {
                        $row = $tmp[$owner_ids[$cache_code]];
                    } else {
                        # broken OC database - invalid owner ID or missing 'user' table entry
                        throw new Exception(
                            "Unknown owner ID ".$owner_ids[$cache_code]." for ".$cache_code
                        );
                    }
                    $owner = [];
                    foreach ($owner_fields as $field)
                    {
                        if ($field == 'uuid')
                            $owner['uuid'] = $row['uuid'];
                        elseif ($field == 'username')
                            $owner['username'] = $row['username'];
                        elseif ($field == 'profile_url')
                            $owner['profile_url'] = Settings::get('SITE_URL')."viewprofile.php?userid=".$row['user_id'];
                        elseif ($field == 'internal_id')
                            $owner['internal_id'] = $row['user_id'];
                    }
                    $result_ref['owner'] = $owner;
                }
                unset($owner);
            }
            else
            {
                $ownersRequest = new OkapiInternalRequest(
                    $request->consumer,
                    $request->token,
                    array(
                        'internal_ids' => implode('|', $owner_ids),
                        'fields' => implode('|', $owner_fields),
                    )
                );
                $ownersRequest->skip_limits = true;
                try
                {
                    $tmp = OkapiServiceRunner::call("services/users/by_internal_ids", $ownersRequest);
                }
                catch (Exception $e)
                {
                    if (($e instanceof InvalidParam) && ($e->paramName == 'fields')) {
                        throw new InvalidParam('owner_fields', $e->whats_wrong_about_it);
                    }
                    else {
                        /* Something is wrong with OUR code. */
                        throw new Exception($e);
                    }
                }
                foreach ($results as $cache_code => &$result_ref) {
                    $result_ref['owner'] = $tmp[$owner_ids[$cache_code]];
                }
                unset($ownersRequest);
            }
            unset($owner_fields_exploded);
            unset($basic_owner_fields);
        }

        # is_found

        if (in_array('is_found', $fields))
        {
            if ($user_id == null)
                throw new BadRequest("Either 'user_uuid' parameter OR Level 3 Authentication is required to access 'is_found' field.");
            $tmp = Db::select_column("
                select c.wp_oc
                from
                    caches c,
                    cache_logs cl
                where
                    c.cache_id = cl.cache_id
                    and cl.type in (
                        '".Db::escape_string(Okapi::logtypename2id("Found it"))."',
                        '".Db::escape_string(Okapi::logtypename2id("Attended"))."'
                    )
                    and cl.user_id = '".Db::escape_string($user_id)."'
                    ".((Settings::get('OC_BRANCH') == 'oc.pl') ? "and cl.deleted = 0" : "")."
            ");
            $tmp2 = array();
            foreach ($tmp as $cache_code)
                $tmp2[$cache_code] = true;
            foreach ($results as $cache_code => &$result_ref)
                $result_ref['is_found'] = isset($tmp2[$cache_code]);
        }

        # is_not_found

        if (in_array('is_not_found', $fields))
        {
            if ($user_id == null)
                throw new BadRequest("Either 'user_uuid' parameter OR Level 3 Authentication is required to access 'is_not_found' field.");
            $tmp = Db::select_column("
                select c.wp_oc
                from
                    caches c,
                    cache_logs cl
                where
                    c.cache_id = cl.cache_id
                    and cl.type = '".Db::escape_string(Okapi::logtypename2id("Didn't find it"))."'
                    and cl.user_id = '".Db::escape_string($user_id)."'
                    ".((Settings::get('OC_BRANCH') == 'oc.pl') ? "and cl.deleted = 0" : "")."
            ");
            $tmp2 = array();
            foreach ($tmp as $cache_code)
                $tmp2[$cache_code] = true;
            foreach ($results as $cache_code => &$result_ref)
                $result_ref['is_not_found'] = isset($tmp2[$cache_code]);
        }

        # is_watched

        if (in_array('is_watched', $fields))
        {
            if ($request->token == null)
                throw new BadRequest("Level 3 Authentication is required to access 'is_watched' field.");
            $tmp = Db::select_column("
                select c.wp_oc
                from
                    caches c,
                    cache_watches cw
                where
                    c.cache_id = cw.cache_id
                    and cw.user_id = '".Db::escape_string($request->token->user_id)."'
            ");
            $tmp2 = array();
            foreach ($tmp as $cache_code)
                $tmp2[$cache_code] = true;

            # OCDE caches can also be indirectly watched by watching cache lists:
            if (Settings::get('OC_BRANCH') == 'oc.de')
            {
              $tmp = Db::select_column("
                  select c.wp_oc
                  from
                      caches c,
                      cache_list_items cli,
                      cache_list_watches clw
                  where
                      cli.cache_id = c.cache_id
                      and clw.cache_list_id = cli.cache_list_id
                      and clw.user_id = '".Db::escape_string($request->token->user_id)."'
              ");
              foreach ($tmp as $cache_code)
                  $tmp2[$cache_code] = true;
            }

            foreach ($results as $cache_code => &$result_ref)
                $result_ref['is_watched'] = isset($tmp2[$cache_code]);
        }

        # is_ignored

        if (in_array('is_ignored', $fields))
        {
            if ($request->token == null)
                throw new BadRequest("Level 3 Authentication is required to access 'is_ignored' field.");
            $tmp = Db::select_column("
                select c.wp_oc
                from
                    caches c,
                    cache_ignore ci
                where
                    c.cache_id = ci.cache_id
                    and ci.user_id = '".Db::escape_string($request->token->user_id)."'
            ");
            $tmp2 = array();
            foreach ($tmp as $cache_code)
                $tmp2[$cache_code] = true;
            foreach ($results as $cache_code => &$result_ref)
                $result_ref['is_ignored'] = isset($tmp2[$cache_code]);
        }

        # my_rating

        if (in_array('my_rating', $fields))
        {
            if ($request->token == null)
                throw new BadRequest("Level 3 Authentication is required to access 'my_rating' field.");
            $tmp2 = array();
            if (Settings::get('OC_BRANCH') == 'oc.pl')
            {
                $tmp = Db::select_all("
                    select c.wp_oc as cache_code, s.score
                    from
                        caches c,
                        scores s
                    where
                        c.cache_id = s.cache_id
                        and s.user_id = '".Db::escape_string($request->token->user_id)."'
                ");
                foreach ($tmp as $row)
                    $tmp2[$row['cache_code']] = Okapi::decode_geocache_rating($row['score']);
            }
            foreach ($results as $cache_code => &$result_ref)
                $result_ref['my_rating'] = (isset($tmp2[$cache_code]) ? $tmp2[$cache_code] : null);
        }

        # is_recommended

        if (in_array('is_recommended', $fields))
        {
            if ($user_id == null)
                throw new BadRequest("Either 'user_uuid' parameter OR Level 3 Authentication is required to access 'is_recommended' field.");
            $tmp = Db::select_column("
                select c.wp_oc
                from
                    caches c,
                    cache_rating cr
                where
                    c.cache_id = cr.cache_id
                    and cr.user_id = '".Db::escape_string($user_id)."'
            ");
            $tmp2 = array();
            foreach ($tmp as $cache_code)
                $tmp2[$cache_code] = true;
            foreach ($results as $cache_code => &$result_ref)
                $result_ref['is_recommended'] = isset($tmp2[$cache_code]);
        }

        # Descriptions and hints.

        if (in_array('description', $fields) || in_array('descriptions', $fields)
            || in_array('editable_description', $fields) || in_array('editable_descriptions', $fields)
            || in_array('short_description', $fields) || in_array('short_descriptions', $fields)
            || in_array('hint', $fields) || in_array('hints', $fields)
            || in_array('hint2', $fields) || in_array('hints2', $fields)
            || in_array('oc_team_annotation', $fields))
        {
            # At first, we will fill all those fields, even if user requested just one
            # of them. We will chop off the unwanted ones at the end.

            foreach ($results as &$result_ref)
            {
                $result_ref['short_descriptions'] = new ArrayObject();
                $result_ref['descriptions'] = new ArrayObject();
                $result_ref['editable_descriptions'] = new ArrayObject();
                $result_ref['empty_descriptions'] = [];
                $result_ref['hints'] = new ArrayObject();
                $result_ref['hints2'] = new ArrayObject();
                $result_ref['oc_team_annotations'] = new ArrayObject();
            }
            $fields_to_remove_later[] = 'empty_descriptions';
            $fields_to_remove_later[] = 'oc_team_annotations';

            # Get cache descriptions and hints.

            if (Settings::get('OC_BRANCH') == 'oc.pl') {
                $oc_team_annotation_SQL = 'rr_comment';
                $reactivation_rule_SQL = 'reactivation_rule';
            } else {
                $oc_team_annotation_SQL = "null";
                $reactivation_rule_SQL = "null";
            }
            $rs = Db::query("
                select
                    cache_id, language, `desc`, short_desc, hint,
                    ".$oc_team_annotation_SQL." as oc_team_annotation,
                    ".$reactivation_rule_SQL." as reactivation_rule
                from cache_desc
                where cache_id in ('".implode("','", array_map('\okapi\core\Db::escape_string', array_keys($cacheid2wptcode)))."')
            ");
            while ($row = Db::fetch_assoc($rs))
            {
                $cache_code = $cacheid2wptcode[$row['cache_id']];

                # Owners may supply just one empty description. A "listing is outdated"
                # note must always be returned; therefore we must process empty
                # descriptions, too.

                // strtolower - ISO 639-1 codes are lowercase
                $language = strtolower($row['language']);

                $listing_is_outdated = in_array($cache_code, $outdated_listings);
                $include_team_annotation = ($row['oc_team_annotation'] && $oc_team_annotation == 'description');
                $include_reactivation_rule = !empty($row['reactivation_rule']);
                if ($row['desc'] || $listing_is_outdated || $include_team_annotation)
                {
                    /* Note, that the "owner" and "internal_id" fields are automatically included,
                     * whenever the cache description is included. */

                    $tmp = Okapi::fix_oc_html($row['desc'], 0);

                    if ($request->token != null && $request->token->user_id == $owner_ids[$cache_code])
                    {
                        # Though this is no private data, we expose it only to the
                        # cache owner, because the "naked" descriptions should not be
                        # published.

                        $results[$cache_code]['editable_descriptions'][$language] = $tmp;
                    }
                    else
                        $results[$cache_code]['editable_descriptions'][$language] = null;

                    Okapi::gettext_domain_init(
                        array_merge([$language], $langprefs)
                    );
                    if ($listing_is_outdated)
                    {
                        $tmp = (
                            "<p style='color:#c00000'><strong>".
                            _('Parts of this geocache listing may be outdated.').
                            "</strong> ".
                            _('See the log entries for more information.').
                            "</p>\n".
                            $tmp
                        );
                    }

                    if ($include_reactivation_rule)
                    {
                        # Append the description with reactivation rules if needed
                        $tmp .= '<div class="reactivationRules">'.
                            "<b>"._("Reactivation rules:")."</b><br />\n".
                            $row['reactivation_rule'].
                            "</div>";
                    }

                    if ($include_team_annotation)
                    {
                        # Some ugly hacks so that team annotations are readble without OC CSS;
                        # see issue #533.

                        # First replace <span> by <div>, because some HTML renderers ignore
                        # "display:block" etc. for spans. Then add explicit style for the
                        # div, so that it works without defining the "ocTeamCommentHeader"
                        # CSS class.

                        $formatted_team_annotation = mb_ereg_replace(
                            '<span class="ocTeamCommentHeader"(.+?)</span>',
                            '<div class="ocTeamCommentHeader"\1</div>',
                            $row['oc_team_annotation'])
                        ;
                        $formatted_team_annotation = mb_ereg_replace(
                            'class="ocTeamCommentHeader"',
                            'class="ocTeamCommentHeader" style="display: block; padding-top: 0.5em;"',
                            $formatted_team_annotation
                        );
                        $tmp = (
                            # Add a margin-top, because some clients (e.g. c:geo) show the
                            # description directly below the short description.

                            '<div class="ocTeamCommentSection" style="margin-top: 1em">'.
                            "<b>"._("Annotations by the Opencaching team:")."</b><br />\n".
                            $formatted_team_annotation.
                            "<div style='display: block; padding-top: 0.5em'>("._("End of annotations").")</div>".
                            "<hr /></div>\n" .
                            $tmp
                        );
                    }
                    if ($row['desc'] && $attribution_append != 'none')
                    {
                        $tmp .= "\n<p><em>".
                            self::get_cache_attribution_note(
                                $row['cache_id'], null, [],
                                $results[$cache_code]['owner'], $attribution_append
                            ).
                            "</em></p>";
                    }

                    Okapi::gettext_domain_restore();

                    $results[$cache_code]['descriptions'][$language] = $tmp;
                    if (!$row['desc'])
                    {
                        $results[$cache_code]['empty_descriptions'][] = $language;
                    }
                }
                if ($row['short_desc'])
                {
                    $results[$cache_code]['short_descriptions'][$language] = $row['short_desc'];
                }
                if ($row['hint'])
                {
                    $results[$cache_code]['hints'][$language] = $row['hint'];
                    $results[$cache_code]['hints2'][$language]
                        = htmlspecialchars_decode(mb_ereg_replace("<br />", "" , $row['hint']), ENT_COMPAT);
                }
                if ($row['oc_team_annotation'])
                {
                    $results[$cache_code]['oc_team_annotations'][$language] = $row['oc_team_annotation'];
                }
            }
            unset($listing_is_outdated);
            unset($language);
            unset($include_team_annotation);

            foreach ($results as &$result_ref)
            {
                # If the owner supplied at least one non-empty description, we discard
                # all descriptions which contain only a "listing is outdated" note.

                if (count($result_ref['descriptions']) > count($result_ref['empty_descriptions']))
                {
                    foreach ($result_ref['empty_descriptions'] as $tmp)
                        unset($result_ref['descriptions'][$tmp]);
                }
                $result_ref['short_description'] = Okapi::pick_best_language($result_ref['short_descriptions'], $langprefs);
                $result_ref['description'] = Okapi::pick_best_language($result_ref['descriptions'], $langprefs);
                $result_ref['editable_description'] = Okapi::pick_best_language($result_ref['editable_descriptions'], $langprefs);
                if ($result_ref['editable_description'] === null) {
                    $result_ref['editable_descriptions'] = null;
                }
                $result_ref['hint'] = Okapi::pick_best_language($result_ref['hints'], $langprefs);
                $result_ref['hint2'] = Okapi::pick_best_language($result_ref['hints2'], $langprefs);

                # OCPL currently stores the same team comments redundantly in all
                # descriptions of the geocache. We might pick any of them. Probably
                # they will be moved to the 'caches' table later (see issue #533).

                $result_ref['oc_team_annotation'] = Okapi::pick_best_language($result_ref['oc_team_annotations'], $langprefs);
            }

            # Remove unwanted fields.

            foreach (array(
                'short_description', 'short_descriptions', 'description', 'descriptions',
                'editable_description', 'editable_descriptions',
                'hint', 'hints', 'hint2', 'hints2', 'oc_team_annotation',
            ) as $field)
                if (!in_array($field, $fields))
                    foreach ($results as &$result_ref)
                        unset($result_ref[$field]);
        }

        # Images.

        if (in_array('images', $fields) || in_array('preview_image', $fields))
        {
            if (in_array('images', $fields))
                foreach ($results as &$result_ref)
                    $result_ref['images'] = array();
            if (in_array('preview_image', $fields))
                foreach ($results as &$result_ref)
                    $result_ref['preview_image'] = null;

            if (Db::field_exists('pictures', 'mappreview'))
                $preview_field = "mappreview";
            else
                $preview_field = "0";
            $rs = Db::query("
                select object_id, uuid, url, title, spoiler, ".$preview_field." as preview
                from pictures
                where
                    object_id in ('".implode("','", array_map('\okapi\core\Db::escape_string', array_keys($cacheid2wptcode)))."')
                    and display = 1
                    and object_type = 2
                    and unknown_format = 0
                order by object_id, seq, date_created
            ");

            unset($sql);
            $prev_cache_code = null;
            while ($row = Db::fetch_assoc($rs))
            {
                $cache_code = $cacheid2wptcode[$row['object_id']];
                if ($cache_code != $prev_cache_code)
                {
                    # Group images together. Images must have unique captions within one cache.
                    self::reset_unique_captions();
                    $prev_cache_code = $cache_code;
                }
                if (Settings::get('OC_BRANCH') == 'oc.de') {
                    $object_type_param = 'type=2&';
                } else {
                    $object_type_param = '';
                }
                $image = array(
                    'uuid' => $row['uuid'],
                    'url' => Okapi::use_current_site_url($row['url']),
                    'thumb_url' => Settings::get('SITE_URL') . 'thumbs.php?'.$object_type_param.'uuid=' . $row['uuid'],
                    'caption' => $row['title'],
                    'unique_caption' => self::get_unique_caption($row['title']),
                    'is_spoiler' => ($row['spoiler'] ? true : false),
                );
                if (in_array('images', $fields))
                    $results[$cache_code]['images'][] = $image;
                if ($row['preview'] != 0 && in_array('preview_image', $fields))
                    $results[$cache_code]['preview_image'] = $image;
            }
        }

        # A-codes and attrnames

        if (in_array('attr_acodes', $fields) || in_array('attrnames', $fields))
        {
            # Either case, we'll need acodes. If the user didn't want them,
            # remember to remove them later.

            if (!in_array('attr_acodes', $fields)) {
                $fields_to_remove_later[] = 'attr_acodes';
            }
            foreach ($results as &$result_ref) {
                # can have been initialized by type mapping
                if (!isset($result_ref['attr_acodes']))
                    $result_ref['attr_acodes'] = array();
            }

            # Load internal_attr_id => acode mapping.

            $internal2acode = AttrHelper::get_internal_id_to_acode_mapping();

            $rs = Db::query("
                select cache_id, attrib_id
                from caches_attributes
                where cache_id in ('".implode("','", array_map('\okapi\core\Db::escape_string', array_keys($cacheid2wptcode)))."')
            ");
            while ($row = Db::fetch_assoc($rs))
            {
                $cache_code = $cacheid2wptcode[$row['cache_id']];
                $attr_internal_id = $row['attrib_id'];
                if (!isset($internal2acode[$attr_internal_id]))
                {
                    # Unknown attribute. Ignore.
                    continue;
                }
                $results[$cache_code]['attr_acodes'][] = $internal2acode[$attr_internal_id];
            }

            # Type mapping can have created duplicate acodes. Remove them.

            foreach ($results as &$result_ref) {
                $result_ref['attr_acodes'] = array_values(array_unique($result_ref['attr_acodes']));
            }

            # Now, each cache object has a list of its acodes. We can get
            # the attrnames now.

            if (in_array('attrnames', $fields))
            {
                $acode2bestname = AttrHelper::get_acode_to_name_mapping($langprefs);
                foreach ($results as &$result_ref)
                {
                    $result_ref['attrnames'] = array();
                    foreach ($result_ref['attr_acodes'] as $acode)
                        $result_ref['attrnames'][] = $acode2bestname[$acode];
                }
            }
        }

        # Latest log entries.

        if (in_array('latest_logs', $fields))
        {
            if ($user_logs_only == 'true') {
                if ($user_id == null) {
                    # This error can also be triggered via caches/formatters/gpx, which has
                    # the "latest_logs=user" option instead of "only_user_logs=true".
                    # Therefore we avoid to mention "only_user_logs" in the error message.

                    throw new BadRequest("Either 'user_uuid' parameter OR Level 3 Authentication is required to retrieve the user's logs.");
                }
                $add_where_sql = " and cache_logs.user_id = '".Db::escape_string($user_id)."'";
            } else {
                $add_where_sql = "";
            }

            foreach ($results as &$result_ref)
                $result_ref['latest_logs'] = array();

            # Get all log IDs in proper order, then filter out the latest
            # ones. This should be the fastest technique ...

            # OCDE allows to submit logs without time. To minimize problems
            # when ordering logs with and without time on the same day, there
            # is a separate order_date field which is caluclated from 'date'
            # and 'date_created'.
            #
            # OCPL log entries are all submitted with time, so the 'date'
            # field is sufficient for ordering.

            if (Settings::get('OC_BRANCH') == 'oc.de') {
                $logs_order_field_SQL = 'order_date';
            } else {
                $logs_order_field_SQL = 'date';
            }

            $rs = Db::query("
                select cache_id, uuid
                from cache_logs
                where
                    cache_id in ('".implode("','", array_map('\okapi\core\Db::escape_string', array_keys($cacheid2wptcode)))."')
                    and ".((Settings::get('OC_BRANCH') == 'oc.pl') ? "deleted = 0" : "true").
                    $add_where_sql."
                order by cache_id, ".$logs_order_field_SQL." desc, cache_logs.date_created desc, cache_logs.id desc
            ");

            $loguuids = array();
            $log2cache_map = array();
            if ($lpc !== null)
            {
                # User wants some of the latest logs.
                $tmp = array();
                while ($row = Db::fetch_assoc($rs))
                    $tmp[$row['cache_id']][] = $row;
                foreach ($tmp as $cache_key => &$rowslist_ref)
                {
                    for ($i = 0; $i < min(count($rowslist_ref), $lpc); $i++)
                    {
                        $loguuids[] = $rowslist_ref[$i]['uuid'];
                        $log2cache_map[$rowslist_ref[$i]['uuid']] = $cacheid2wptcode[$rowslist_ref[$i]['cache_id']];
                    }
                }
            }
            else
            {
                # User wants ALL logs.
                while ($row = Db::fetch_assoc($rs))
                {
                    $loguuids[] = $row['uuid'];
                    $log2cache_map[$row['uuid']] = $cacheid2wptcode[$row['cache_id']];
                }
            }

            Db::free_result($rs);

            # We need to retrieve logs/entry for each of the $logids. We do this in groups
            # (there is a limit for log uuids passed to logs/entries method).

            try
            {
                foreach (Okapi::make_groups($loguuids, 500) as $subset)
                {
                    $entries = OkapiServiceRunner::call(
                        "services/logs/entries",
                        new OkapiInternalRequest(
                            $request->consumer, $request->token, array(
                                'log_uuids' => implode("|", $subset),
                                'fields' => $log_fields,
                                'user_fields' => $log_user_fields,
                            )
                        )
                    );
                    foreach ($subset as $log_uuid)
                    {
                        if ($entries[$log_uuid])
                            $results[$log2cache_map[$log_uuid]]['latest_logs'][] = $entries[$log_uuid];
                    }
                }
            }
            catch (Exception $e)
            {
                if (($e instanceof InvalidParam) && ($e->paramName == 'fields'))
                {
                    throw new InvalidParam('log_fields', $e->whats_wrong_about_it);
                }
                elseif (($e instanceof InvalidParam) && ($e->paramName == 'user_fields'))
                {
                    throw new InvalidParam('log_user_fields', $e->whats_wrong_about_it);
                }
                else
                {
                    /* Something is wrong with OUR code. */
                    throw new Exception($e);
                }
            }
        }

        # My notes

        if (in_array('my_notes', $fields))
        {
            if ($request->token == null)
                throw new BadRequest("Level 3 Authentication is required to access 'my_notes' field.");
            foreach ($results as &$result_ref)
                $result_ref['my_notes'] = null;
            if (Settings::get('OC_BRANCH') == 'oc.pl')
            {
                # OCPL uses cache_notes table to store notes.

                $rs = Db::query("
                    select cache_id, max(date) as date, group_concat(`desc`) as `desc`
                    from cache_notes
                    where
                        cache_id in ('".implode("','", array_map('\okapi\core\Db::escape_string', array_keys($cacheid2wptcode)))."')
                        and user_id = '".Db::escape_string($request->token->user_id)."'
                    group by cache_id
                ");
            }
            else
            {
                # OCDE uses coordinates table (with type == 2) to store notes (this is somewhat weird).

                $rs = Db::query("
                    select cache_id, null as date, description as `desc`
                    from coordinates
                    where
                        type = 2  -- personal note
                        and cache_id in ('".implode("','", array_map('\okapi\core\Db::escape_string', array_keys($cacheid2wptcode)))."')
                        and user_id = '".Db::escape_string($request->token->user_id)."'
                    group by cache_id
                ");
            }
            while ($row = Db::fetch_assoc($rs))
            {
                # This one is plain-text. We may add my_notes_html for those who want it in HTML.
                $results[$cacheid2wptcode[$row['cache_id']]]['my_notes'] = strip_tags($row['desc']);
            }
        }

        if (in_array('trackables', $fields))
        {
            # Currently we support Geokrety only. But this interface should remain
            # compatible. In future, other trackables might be returned the same way.

            $rs = Db::query("
                select
                    gkiw.wp as cache_code,
                    gki.id as gk_id,
                    gki.name
                from
                    gk_item_waypoint gkiw,
                    gk_item gki
                where
                    gkiw.id = gki.id
                    and gkiw.wp in ('".implode("','", array_map('\okapi\core\Db::escape_string', $cache_codes))."')
            ");
            $trs = array();
            while ($row = Db::fetch_assoc($rs))
                $trs[$row['cache_code']][] = $row;
            foreach ($results as $cache_code => &$result_ref)
            {
                $result_ref['trackables'] = array();
                if (!isset($trs[$cache_code]))
                    continue;
                foreach ($trs[$cache_code] as $t)
                {
                    $result_ref['trackables'][] = array(
                        'code' => 'GK'.str_pad(strtoupper(dechex($t['gk_id'])), 4, "0", STR_PAD_LEFT),
                        # NOTE: The 'code' is decoded to the decimal ID in formatters/gpx/WebServoce.php.
                        'name' => $t['name'],
                        'url' => 'https://geokrety.org/konkret.php?id='.$t['gk_id']
                    );
                }
            }
            unset($trs);
        }
        if (in_array('trackables_count', $fields))
        {
            if (in_array('trackables', $fields))
            {
                # We already got all trackables data, no need to query database again.
                foreach ($results as $cache_code => &$result_ref)
                    $result_ref['trackables_count'] = count($result_ref['trackables']);
            }
            else
            {
                $rs = Db::query("
                    select wp as cache_code, count(*) as count
                    from gk_item_waypoint
                    where wp in ('".implode("','", array_map('\okapi\core\Db::escape_string', $cache_codes))."')
                    group by wp
                ");
                $tr_counts = new ArrayObject();
                while ($row = Db::fetch_assoc($rs))
                    $tr_counts[$row['cache_code']] = $row['count'];
                foreach ($results as $cache_code => &$result_ref)
                {
                    if (isset($tr_counts[$cache_code]))
                        $result_ref['trackables_count'] = $tr_counts[$cache_code] + 0;
                    else
                        $result_ref['trackables_count'] = 0;
                }
                unset($tr_counts);
            }
        }

        # Alternate/Additional waypoints.

        if (in_array('alt_wpts', $fields))
        {
            $internal_wpt_type_id2names = array();
            if (Settings::get('OC_BRANCH') == 'oc.de')
            {
                $rs = Db::query("
                    select
                        ct.id,
                        LOWER(stt.lang) as language,
                        stt.`text`
                    from
                        coordinates_type ct
                        left join sys_trans_text stt on stt.trans_id = ct.trans_id
                ");
                while ($row = Db::fetch_assoc($rs))
                    $internal_wpt_type_id2names[$row['id']][$row['language']] = $row['text'];
                Db::free_result($rs);
            }
            else
            {
                $rs = Db::query("
                    select *
                    from waypoint_type
                    where id > 0
                ");
                while ($row = Db::fetch_assoc($rs))
                {
                    $wpt_type_id = $row['id'];
                    /*
                     * OCPL-based databases MAY contain explicitly undefined language
                     * columns. This is a bit weird, but we can work with that. We need to
                     * dynamically scan for all the languages supported by this particular
                     * database.
                     *
                     * https://github.com/opencaching/okapi/issues/458
                     */
                    foreach ($row as $lang => $content) {
                        $lang = strtolower($lang);
                        if (strlen($lang) != 2) {
                            continue;
                        }
                        if ($lang == 'id') {
                            continue;
                        }
                        $internal_wpt_type_id2names[$wpt_type_id][$lang] = $content;
                    }
                }
            }

            foreach ($results as &$result_ref)
                $result_ref['alt_wpts'] = array();
            $cache_codes_escaped_and_imploded = "'".implode("','", array_map('\okapi\core\Db::escape_string', array_keys($cacheid2wptcode)))."'";

            if (Settings::get('OC_BRANCH') == 'oc.pl')
            {
                # OCPL uses 'waypoints' table to store additional waypoints and defines
                # waypoint types in 'waypoint_type' table.
                # OCPL also have a special 'status' field to denote a hidden waypoint
                # (i.e. final location of a multicache). Such hidden waypoints are not
                # exposed by OKAPI.

                $cacheid2waypoints = Db::select_group_by("cache_id", "
                    select
                        cache_id, stage, `desc`,
                        if (status=1, latitude, 0) as latitude,
                        if (status=1, longitude, 0) as longitude,
                        type as internal_type_id,
                        case type
                            when 3 then 'Flag, Red'
                            when 4 then 'Circle with X'
                            when 5 then 'Parking Area'
                            when 6 then 'Trail Head'
                            else 'Flag, Green'
                        end as sym,
                        case type
                            when 1 then 'physical-stage'
                            when 2 then 'virtual-stage'
                            when 3 then 'final'
                            when 4 then 'poi'
                            when 5 then 'parking'
                            when 6 then 'trailhead'
                            else 'other'
                        end as okapi_type
                    from waypoints
                    where
                        cache_id in (".$cache_codes_escaped_and_imploded.")
                        and status in (1,2)
                    order by cache_id, stage, wp_id
                ");
            }
            else
            {
                # OCDE uses 'coordinates' table (with type=1) to store additional waypoints
                # and defines waypoint types in 'coordinates_type' table.
                # All additional waypoints are public.

                $cacheid2waypoints = Db::select_group_by("cache_id", "
                    select
                        cache_id,
                        false as stage,
                        latitude, longitude,
                        description as `desc`,
                        subtype as internal_type_id,
                        case subtype
                            when 1 then 'Parking Area'
                            when 3 then 'Trail Head'
                            when 4 then 'Circle with X'
                            when 5 then 'Diamond, Green'
                            else 'Flag, Green'
                        end as sym,
                        case subtype
                            when 1 then 'parking'
                            when 2 then 'stage'
                            when 3 then 'path'
                            when 4 then 'final'
                            when 5 then 'poi'
                            else 'other'
                        end as okapi_type
                    from coordinates
                    where
                        type = 1
                        and cache_id in (".$cache_codes_escaped_and_imploded.")
                    order by cache_id, id
                ");
            }

            static $gc_wpt_type = [
                'parking' => 'Parking Area',
                'trailhead' => 'Trailhead',
                'path' => 'Trailhead',
                'physical-stage' => 'Physical Stage',
                'virtual-stage' => 'Virtual Stage',
                'final' => 'Final Location',
                'poi' => 'Point of Interest',
                # All other OKAPI types map to 'Reference Point'.
            ];

            # Set the language for "Stage", "User location" etc.
            Okapi::gettext_domain_init($langprefs);

            foreach ($cacheid2waypoints as $cache_id => $waypoints)
            {
                $cache_code = $cacheid2wptcode[$cache_id];
                $wpt_format = $cache_code."-%0".strlen(count($waypoints))."d";
                $index = 0;
                foreach ($waypoints as $row)
                {
                    if (!isset($internal_wpt_type_id2names[$row['internal_type_id']]))
                    {
                        # Sanity check. Waypoints of undefined type won't be accessible via OKAPI.
                        # See issue 219.
                        continue;
                    }
                    $index++;
                    $results[$cache_code]['alt_wpts'][] = array(
                        'name' => sprintf($wpt_format, $index),
                        'location' => Okapi::coords2latlon($row['latitude'], $row['longitude']),
                        'type' => $row['okapi_type'],
                        'type_name' => Okapi::pick_best_language($internal_wpt_type_id2names[$row['internal_type_id']], $langprefs),
                        'gc_type' => isset($gc_wpt_type[$row['okapi_type']]) ? $gc_wpt_type[$row['okapi_type']] : 'Reference Point',
                        'sym' => $row['sym'],
                        'description' => ($row['stage'] ? _("Stage")." ".$row['stage'].": " : "").$row['desc'],
                    );
                }
            }

            # Issue #298 - User coordinates implemented in oc.pl
            # Issue #305 - User coordinates implemented in oc.de
            if ($request->token != null)
            {
                # Query DB for user provided coordinates
                if (Settings::get('OC_BRANCH') == 'oc.pl')
                {
                    $cacheid2user_coords = Db::select_group_by('cache_id', "
                        select
                            cache_id, longitude, latitude
                        from cache_mod_cords
                        where
                            cache_id in ($cache_codes_escaped_and_imploded)
                            and user_id = '".Db::escape_string($request->token->user_id)."'
                    ");
                } else {
                    # oc.de
                    $cacheid2user_coords = Db::select_group_by('cache_id', "
                        select
                            cache_id, longitude, latitude
                        from coordinates
                        where
                            cache_id in ($cache_codes_escaped_and_imploded)
                            and user_id = '".Db::escape_string($request->token->user_id)."'
                            and type = 2
                            and longitude != 0
                            and latitude != 0
                    ");
                }
                foreach ($cacheid2user_coords as $cache_id => $waypoints)
                {
                    $cache_code = $cacheid2wptcode[$cache_id];
                    foreach ($waypoints as $row)
                    {
                        # there should be only one user waypoint per cache...
                        $results[$cache_code]['alt_wpts'][] = array(
                            'name' => $cache_code.'-USER-COORDS',
                            'location' => Okapi::coords2latlon($row['latitude'], $row['longitude']),
                            'type' => 'user-coords',
                            'type_name' => _("User location"),
                            'gc_type' => 'Reference Point',
                            'sym' => 'Block, Green',
                            'description' => sprintf(
                                _("Your own custom coordinates for the %s geocache"),
                                $cache_code
                            ),
                        );
                    }
                }
            }

            Okapi::gettext_domain_restore();
        }

        # Country and/or region

        if (array_intersect(
            ['country', 'country2', 'country_code', 'state', 'region', 'region_code'],
            $fields)
        ) {
            # The 'state' field for many years had an OCPL bug, returning null
            # instead of "" if the region was undefined. We have kept it like
            # this for backward compatibility, documented it with version 1850
            # and deprecated the field. (~ 600 OC.PL caches had undefined
            # region as of April 2018.)

            # The OCPL 'country' field had the same bug; there are a few
            # OC.PL caches with bad country data that return null. As there
            # also was a language problem, we deprecated this field, too, and
            # introduced country2.

            $countries = array();
            $countries2 = array();
            $country_codes = array();
            $states = array();
            $regions = array();
            $region_codes = array();

            # To be on the safe site, we fully validate country and region codes.

            if (Settings::get('OC_BRANCH') == 'oc.de')
            {
                # OCDE:
                #  - cache_location entries are created by a cronjob *after* listing the
                #      caches and may not yet exist.
                #  - The adm1 ... adm4 fields represent country and NUTS levels 1 to 3.
                #      We either pick level 1 or 2 for 'region', whatever is the best match
                #      (see Github issue #498).
                #  - The 'region' (or similar entity) is in adm2 or adm3 field.
                #  - caches.country overrides cache_location.code1/adm1. If both differ,
                #      cache_location.adm2 to adm4 are invalid and the state unknown.
                #  - OCDE databases may contain caches with invalid country code.
                #      Such errors must be handled gracefully.
                #  - adm1 should always be ignored. Instead, code1 should be translated
                #      into a country name, depending on langpref.

                # build country code translation table
                $rs = Db::query("
                    select distinct
                        c.country,
                        lower(stt.lang) as language,
                        stt.`text`
                    from
                        caches c
                        inner join countries on countries.short = c.country
                        inner join sys_trans_text stt on stt.trans_id = countries.trans_id
                    where
                        c.wp_oc in ('".implode("','", array_map('\okapi\core\Db::escape_string', $cache_codes))."')
                ");
                $country_codes2names = array();
                while ($row = Db::fetch_assoc($rs))
                    $country_codes2names[$row['country']][$row['language']] = $row['text'];
                Db::free_result($rs);

                # get geocache countries and regions
                $rs = Db::query("
                    select
                        c.wp_oc as cache_code,
                        ifnull(cl.adm1,'') as country_in_default_language,
                        if(c.country<>cl.code1,'',ifnull(if(co.adm_display2=2,cl.adm2,if(co.adm_display2=4,adm4,adm3)),'')) as state,
                        if(c.country<>cl.code1,'',ifnull(if(co.adm_display2=2,cl.adm2,if(co.adm_display2=4,adm4,adm3)),'')) as region,
                        co.short as validated_country_code,    /* see issue #498 on adm2 vs. adm3 */
                        if(left(nc.code,2) = co.short, nc.code, null) as validated_region_code
                    from
                        caches c
                        left join cache_location cl on c.cache_id = cl.cache_id
                        left join countries co on co.short = c.country
                        left join nuts_codes nc on nc.code = if(co.adm_display2=2,cl.code2,if(co.adm_display2=4,code4,code3))
                    where
                        c.wp_oc in ('".implode("','", array_map('\okapi\core\Db::escape_string', $cache_codes))."')
                ");
                $broken_okapi_country_field = false;
            }
            else
            {
                # OCPL:
                #  - cache_location data is entered by the user.
                #  - Only country (adm1) and NUTS level 2 (adm3) is available for geocaches.
                #      So we always return the NUTS 2 entity as "state", which is wrong for Germany.
                #  - The region is in adm3 field.
                #  - There are some broken code3/adm3 entries which do not match code1/adm1.

                # build country code translation table
                $rs = Db::query("
                    select distinct
                        c.country, en, pl, nl
                    from
                        caches c
                        inner join countries on countries.short = c.country
                    where
                        c.wp_oc in ('".implode("','", array_map('\okapi\core\Db::escape_string', $cache_codes))."')
                ");
                $country_codes2names = array();
                while ($row = Db::fetch_assoc($rs)) {
                    foreach (['en', 'pl', 'nl'] as $lang)
                        if ($row[$lang] != '')
                            $country_codes2names[$row['country']][$lang] = $row[$lang];
                }
                Db::free_result($rs);

                # get geocache countries and regions
                $rs = Db::query("
                    select
                        c.wp_oc as cache_code,
                        cl.adm1 as country_in_default_language,  /* can be null */
                        if(cl.code3 is null or left(cl.code3,2)<>cl.code1, null, cl.adm3) as state,
                        if(cl.adm3 is null or cl.code3 is null or left(cl.code3,2)<>cl.code1,'',cl.adm3) as region,
                        countries.short as validated_country_code,
                        if(left(nc.code,2)=countries.short, nc.code, null) as validated_region_code
                    from
                        caches c
                        left join cache_location cl on c.cache_id = cl.cache_id
                        left join countries on countries.short = cl.code1
                        left join nuts_codes nc on nc.code = cl.code3
                    where
                        c.wp_oc in ('".implode("','", array_map('\okapi\core\Db::escape_string', $cache_codes))."')
                ");
                $broken_okapi_country_field = true;
            }

            while ($row = Db::fetch_assoc($rs))
            {
                if (!isset($country_codes2names[$row['validated_country_code']]))
                    $countries2[$row['cache_code']] = ($row['country_in_default_language'] === null ? '' : $row['country_in_default_language']);
                else
                    $countries2[$row['cache_code']] = Okapi::pick_best_language($country_codes2names[$row['validated_country_code']], $langprefs);

                if ($broken_okapi_country_field)
                    $countries[$row['cache_code']] = $row['country_in_default_language'];
                else
                    $countries[$row['cache_code']] = $countries2[$row['cache_code']];

                $states[$row['cache_code']] = $row['state'];
                $regions[$row['cache_code']] = $row['region'];
                $country_codes[$row['cache_code']] = $row['validated_country_code'];
                $region_codes[$row['cache_code']] = $row['validated_region_code'];
            }
            Db::free_result($rs);

            if (in_array('country', $fields)) {
                foreach ($results as $cache_code => &$row_ref)
                    $row_ref['country'] = isset($countries[$cache_code]) ? $countries[$cache_code] : null;
            }
            if (in_array('country2', $fields)) {
                foreach ($results as $cache_code => &$row_ref)
                    $row_ref['country2'] = isset($countries2[$cache_code]) ? $countries2[$cache_code] : '';
            }
            if (in_array('country_code', $fields)) {
                foreach ($results as $cache_code => &$row_ref)
                    $row_ref['country_code'] = isset($country_codes[$cache_code]) ? $country_codes[$cache_code] : null;
            }
            if (in_array('state', $fields)) {
                foreach ($results as $cache_code => &$row_ref)
                    $row_ref['state'] = isset($states[$cache_code]) ? $states[$cache_code] : null;
            }
            if (in_array('region', $fields)) {
                foreach ($results as $cache_code => &$row_ref)
                    $row_ref['region'] = isset($regions[$cache_code]) ? $regions[$cache_code] : '';
            }
            if (in_array('region_code', $fields)) {
                foreach ($results as $cache_code => &$row_ref)
                    $row_ref['region_code'] = isset($region_codes[$cache_code]) ? $region_codes[$cache_code] : null;
            }
            unset($countries);
            unset($country_codes);
            unset($states);
            unset($regions);
            unset($region_codes);
            unset($country_codes2names);
            unset($broken_okapi_country_field);
        }

        # Attribution note

        if (in_array('attribution_note', $fields))
        {
            /* Note, that the "owner" and "internal_id" fields are automatically included,
             * whenever the attribution_note is included. */

            foreach ($results as $cache_code => &$result_ref)
                $result_ref['attribution_note'] =
                    self::get_cache_attribution_note(
                        $result_ref['internal_id'], $langprefs[0], $langprefs,
                        $results[$cache_code]['owner'], 'full'
                    );
        }

        # Protection areas

        if (in_array('protection_areas', $fields))
        {
            $cache_ids_escaped_and_imploded = "'".implode("','", array_map('\okapi\core\Db::escape_string', array_keys($cacheid2wptcode)))."'";

            if (Settings::get('OC_BRANCH') == 'oc.de')
            {
                $rs = Db::query("
                    select
                        c.wp_oc as cache_code,
                        npa_types.name as type,
                        npa_areas.name as name
                    from
                        caches c
                        inner join cache_npa_areas on cache_npa_areas.cache_id=c.cache_id
                        inner join npa_areas on cache_npa_areas.npa_id = npa_areas.id
                        inner join npa_types on npa_areas.type_id = npa_types.id
                    where
                        c.cache_id in (".$cache_ids_escaped_and_imploded.")
                    group by npa_areas.type_id, npa_areas.name
                    order by npa_types.ordinal
                ");
            }
            else if (in_array(Okapi::get_oc_installation_code(), array("OCPL", "OCNL")))
            {
                # Current OCPL table definitions use collation 'latin1' for parkipl
                # and 'utf8' for np_areas. Union needs identical collations.
                # To be sure, we convert both to utf8.
                #
                # TODO: use DB_CHARSET setting instead of literal 'utf8'
                $rs = Db::query("
                    select
                        c.wp_oc as cache_code,
                        '"._('National Park / Landscape')."' as type,
                        CONVERT(parkipl.name USING utf8) as name
                    from
                        caches c
                        inner join cache_npa_areas on cache_npa_areas.cache_id=c.cache_id
                        inner join parkipl on cache_npa_areas.parki_id=parkipl.id
                    where
                        c.cache_id in (".$cache_ids_escaped_and_imploded.")
                        and cache_npa_areas.parki_id != 0
                    union
                    select
                        c.wp_oc as cache_code,
                        'Natura 2000' as type,
                        CONVERT(npa_areas.sitename USING utf8) as name
                    from
                        caches c
                        inner join cache_npa_areas on cache_npa_areas.cache_id=c.cache_id
                        inner join npa_areas on cache_npa_areas.npa_id=npa_areas.id
                    where
                        c.cache_id in (".$cache_ids_escaped_and_imploded.")
                        and cache_npa_areas.npa_id != 0
                ");
            }
            else
            {
                # OC.US and .UK do not have a 'parkipl' table.
                # OC.US has a 'us_parks' table instead.
                # Natura 2000 is Europe-only.
                $rs = null;
            }

            foreach ($results as &$result_ref)
                $result_ref['protection_areas'] = array();
            if ($rs)
            {
                while ($row = Db::fetch_assoc($rs))
                {
                    $results[$row['cache_code']]['protection_areas'][] = array(
                        'type' => $row['type'],
                        'name' => $row['name'],
                    );
                }
                Db::free_result($rs);
            }
        }

        # Check which cache codes were not found and mark them with null.
        foreach ($cache_codes as $cache_code)
            if (!isset($results[$cache_code]))
                $results[$cache_code] = null;

        if (count($fields_to_remove_later) > 0)
        {
            # Some of the fields in $results were added only temporarily
            # (the Consumer did not ask for them). We will remove these fields now.

            foreach ($results as &$result_ref) {
                foreach ($owner_fields_to_remove_later as $field)
                    unset($result_ref['owner'][$field]);
                foreach ($fields_to_remove_later as $field)
                    unset($result_ref[$field]);
            }
        }

        # Order the results in the same order as the input codes were given.
        # This might come in handy for languages which support ordered dictionaries
        # (especially with conjunction with the search_and_retrieve method).
        # See issue#97. PHP dictionaries (assoc arrays) are ordered structures,
        # so we just have to rewrite it (sequentially).

        $ordered_results = new ArrayObject();
        foreach ($cache_codes as $cache_code)
            $ordered_results[$cache_code] = $results[$cache_code];

        /* Handle OCPL's "access logs" feature. */

        if (
            (Settings::get('OC_BRANCH') == 'oc.pl')
            && Settings::get('OCPL_ENABLE_GEOCACHE_ACCESS_LOGS')
        ) {
            $cache_ids = array_keys($cacheid2wptcode);

            /* Log this event only if some specific fields were accessed. */

            if (
                in_array('location', $fields)
                && (count(array_intersect(array(
                    'hint', 'hints', 'hint2', 'hints2',
                    'description', 'descriptions',
                    'editable_description', 'editable_descriptions',
                ), $fields)) > 0)
            ) {
                \okapi\lib\OCPLAccessLogs::log_geocache_access($request, $cache_ids);
            }
        }

        return Okapi::formatted_response($request, $ordered_results);
    }

    /**
     * Create unique caption, safe to be used as a file name for images
     * uploaded into Garmin's GPS devices. Use reset_unique_captions to reset
     * unique counter.
     */
    private static function get_unique_caption($caption)
    {
        # Garmins keep hanging on long file names. We don't have any specification from
        # Garmin and cannot determine WHY. That's why we won't use captions until we
        # know more.

        $caption = self::$caption_no."";
        self::$caption_no++;
        return $caption;

        /* This code is harmful for Garmins!
        $caption = preg_replace('#[^\\pL\d ]+#u', '-', $caption);
        $caption = trim($caption, '-');
        if (function_exists('iconv'))
        {
            $new = iconv("utf-8", "ASCII//TRANSLIT", $caption);
            if (!$new)
                $new = iconv("UTF-8", "ASCII//IGNORE", $caption);
        } else {
            $new = $caption;
        }
        $new = str_replace(array('/', '\\', '?', '%', '*', ':', '|', '"', '<', '>', '.'), '', $new);
        $new = trim($new);
        if ($new == "")
            $new = "(no caption)";
        if (strlen($new) > 240)
            $new = substr($new, 0, 240);
        $new = self::$caption_no." - ".$new;
        self::$caption_no++;
        return $new;*/
    }
    private static $caption_no = 1;
    private static function reset_unique_captions()
    {
        self::$caption_no = 1;
    }

    /**
     * Return attribution note for the given geocache.
     *
     * The $lang parameter identifies the language of the cache description
     * to which the attribution note will be appended to (one cache may
     * have descriptions in multiple languages!).
     *
     * The $langpref parameter is *an array* of language preferences
     * extracted from the langpref parameter passed to the method by the
     * OKAPI Consumer.
     *
     * Both values ($lang and $langpref) will be taken into account when
     * generating the attribution note, but $lang will have a higher
     * priority than $langpref (we don't want to mix the languages in the
     * descriptions if we don't have to).
     *
     * $owner is in object describing the user, it has the same format as
     * defined in "geocache" method specs (see the "owner" field).
     *
     * The $type is either "full" or "static". Full attributions may contain
     * dates and are not suitable for the replicate module. Static attributions
     * don't change that frequently.
     */
    public static function get_cache_attribution_note(
        $cache_id, $lang, array $langprefs, $owner, $type
    ) {
        $site_url = Settings::get('SITE_URL');

        # Note that $site_url will either start with http:// or https://,
        # depending on the current request. This applies also to the cronjob
        # which replicates geocaches!

        $site_name = Okapi::get_normalized_site_name();
        $cache_url = $site_url."viewcache.php?cacheid=$cache_id";

        if ($lang !== null) {
            Okapi::gettext_domain_init(array_merge(array($lang), $langprefs));
        }
        if (Settings::get('OC_BRANCH') == 'oc.pl')
        {
            # This does not vary on $type (yet).

            $note = sprintf(
                _("This <a href='%s'>geocache</a> description comes from the <a href='%s'>%s</a> site."),
                $cache_url, $site_url, $site_name
            );
        }
        else
        {
            # OC.de wants the tld in lowercase here
            $site_name = ucfirst(strtolower($site_name));
            if ($type == 'full')
            {
                $note = sprintf(
                    _(
                        "&copy; <a href='%s'>%s</a>, <a href='%s'>%s</a>, ".
                        "<a href='https://creativecommons.org/licenses/by-nc-nd/3.0/de/deed.en'>CC-BY-NC-ND</a>, ".
                        "as of %s; all log entries &copy; their authors"
                    ),
                    $owner['profile_url'], $owner['username'], $cache_url, $site_name, strftime('%x')
                );
            }
            elseif ($type == 'static')
            {
                $note = sprintf(
                    _(
                        "&copy; <a href='%s'>%s</a>, <a href='%s'>%s</a>, ".
                        "<a href='https://creativecommons.org/licenses/by-nc-nd/3.0/de/deed.en'>CC-BY-NC-ND</a>; ".
                        "all log entries &copy; their authors"
                    ),
                    $owner['profile_url'], $owner['username'], $cache_url, $site_name
                );
            }
        }

        if ($lang !== null) {
            Okapi::gettext_domain_restore();
        }

        return $note;
    }
}
