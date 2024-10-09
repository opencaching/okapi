<?php

namespace okapi\services\cachesets\cachesets;

use okapi\Okapi;
use okapi\OkapiRequest;
use okapi\BadRequest;
use okapi\Settings;
use okapi\ParamMissing;
use okapi\Db;
use ArrayObject;
use okapi\services\cachesets\CachesetStatics;
use okapi\InvalidParam;

require_once('cacheset_static.inc.php');

class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 1
        );
    }

    private static $valid_field_names = array('uuid', 'name', 'names',
        'location', 'type', 'status', 'mentor', 'authors', 'url', 'image_url',
        'description','descriptions','geocaches_total','geocaches_found',
        'geocaches_found_ratio','min_founds_to_complete','min_ratio_to_complete',
        'my_completed_status','completed_count','last_completed','date_created',
        'cslog_uuids');

    public static function call(OkapiRequest $request)
    {
        $cacheset_uuids = $request->get_parameter('cacheset_uuids');
        if ($cacheset_uuids === null) throw new ParamMissing('cacheset_uuids');
        if ($cacheset_uuids === "")
        {
            # All of the queries below have to be ready for $cacheset_uuid to be empty!
            $cacheset_uuids = array();
        }
        else
            $cacheset_uuids = explode("|", $cacheset_uuids);

        if ((count($cacheset_uuids) > 500) && (!$request->skip_limits))
            throw new InvalidParam('cacheset_uuids', "Maximum allowed number of referenced ".
                "caches is 500. You provided ".count($cacheset_uuids)." cachesets uuids.");
        if (count($cacheset_uuids) != count(array_unique($cacheset_uuids)))
            throw new InvalidParam('cacheset_uuid', "Duplicate uuids detected (make sure each cacheset is referenced only once).");

        $langpref = $request->get_parameter('langpref');
        if (!$langpref) $langpref = "en";
        $langpref .= "|".Settings::get('SITELANG');
        $langpref = explode("|", $langpref);

        $fields = $request->get_parameter('fields');
        if (!$fields) $fields = "uuid|name|type|status|url";
        $fields = explode("|", $fields);
        foreach ($fields as $field)
            if (!in_array($field, self::$valid_field_names))
                throw new InvalidParam('fields', "'$field' is not a valid field code.");

        $rs = Db::query("
            select
                pt.uuid as uuid, pt.name, pt.centerLatitude as latitude,
                pt.centerLongitude as longitude,
                pt.type, pt.status, pt.dateCreated as date_created, pt.cacheCount as geocaches_total,
                pt.description, pt.image as image_url, pt.perccentRequired as min_ratio_to_complete,
                pt.conquestedCount as completed_count
            from
                PowerTrail pt
            where
                uuid in ('".implode("','", array_map('\okapi\Db::escape_string', $cacheset_uuids))."')
                and pt.status in (1,3,4)
        ");

        $results = array();
        $complete_ratio_in_paths = array();
        $total_caches_in_paths = array();

        while ($row = Db::fetch_assoc($rs))
        {
            $entry = array();
            foreach ($fields as $field)
            {
                switch ($field)
                {
                    case 'uuid': $entry['uuid'] = $row['uuid']; break;
                    case 'name': $entry['name'] = $row['name']; break;
                    case 'names': $entry['names'] = array(Settings::get('SITELANG') => $row['name']); break; // for the future
                    case 'location': $entry['location'] = round($row['latitude'], 6)."|".round($row['longitude'], 6); break;
                    case 'type': $entry['type'] = CachesetStatics::cacheset_type_id2name($row['type']); break;
                    case 'status': $entry['status'] = CachesetStatics::cacheset_status_id2name($row['status']); break;
                    case 'mentor':
                        $entry['mentor'] = null;
                        /* continued later */
                        break;
                    case 'authors':
                        $entry['authors'] = array();
                        /* continued later */
                        break;
                    case 'url':
                        // str_replace is temporary - https://forum.opencaching.pl/viewtopic.php?f=6&t=7089&p=136968#p136968
                        $entry['url'] = (
                            str_replace("https://", "http://", Settings::get('SITE_URL')).
                            "powerTrail.php?ptAction=showSerie&ptrail=".$row['uuid']
                            ); //TODO: will be changed soon
                        break;
                    case 'image_url': $entry['image_url'] = $row['image_url']; break;
                    case 'description': $entry['description'] = Okapi::fix_oc_html($row['description'], Okapi::OBJECT_TYPE_CACHESET); break;
                    case 'descriptions':
                        $entry['descriptions'] =
                            array(Settings::get('SITELANG') => Okapi::fix_oc_html($row['description'], Okapi::OBJECT_TYPE_CACHESET));
                        break; // for the future
                    case 'geocaches_total': $entry['geocaches_total'] = $row['geocaches_total']; break;
                    case 'geocaches_found':
                        /* handled separately */ break;

                    case 'min_founds_to_complete':
                    case 'my_completed_status':
                        $complete_ratio_in_paths[$row['uuid']] = $row['min_ratio_to_complete'];
                        /* DON'T BREAK - continue below! */

                    case 'geocaches_found_ratio':
                        $total_caches_in_paths[$row['uuid']] = $row['geocaches_total'];
                        /* continued later */ break;

                    case 'min_ratio_to_complete':
                        $entry['min_ratio_to_complete'] = $row['min_ratio_to_complete']/100;
                        break;

                    case 'completed_count':
                        $entry['completed_count'] = $row['completed_count'];
                        break;

                    case 'last_completed':
                        $entry['last_completed'] = null;
                        /* continued later */ break;
                    case 'date_created':
                        $entry['date_created'] = date('c', strtotime($row['date_created'])); break;
                        break;
                    case 'cslog_uuids':
                        $entry['cslog_uuids'] = array();
                        /* continued later */
                        break;
                    default: throw new Exception("Missing field case: ".$field);
                }
            }
            $results[$row['uuid']] = $entry;
        }
        Db::free_result($rs);

        # mentor & authors

        $process_authors = in_array('authors', $fields);
        $process_mentor = in_array('mentor', $fields);

        if ( ( $process_authors || $process_mentor ) && count($results) > 0)
        {
            $privileges = array();
            if( $process_authors ) $privileges[] = 1;
            if( $process_mentor ) $privileges[] = 2;


            $rs = Db::query("
                select user_id, u.uuid as user_uuid, username, pt.uuid as cacheset_uuid, pto.privileages as privileges
                from
                    PowerTrail as pt
                    join PowerTrail_owners as pto
                        on pt.id = pto.PowerTrailId
                    join user as u
                        on u.user_id = pto.userId
                where
                    pt.uuid in ('".implode("','", array_map('\okapi\Db::escape_string', array_keys($results)))."')
                    and privileages in ('".implode("','", array_map('\okapi\Db::escape_string', array_values($privileges)))."')
            ");

            while ($row = Db::fetch_assoc($rs))
            {
                if( $process_authors )
                {
                    /* every mentor is also an author */
                    $results[$row['cacheset_uuid']]['authors'][] = array(
                        'uuid' => $row['user_uuid'],
                        'username' => $row['username'],
                        'profile_url' => Settings::get('SITE_URL')."viewprofile.php?userid=".$row['user_id']
                    );
                }

                if( $process_mentor && $row['privileges'] == 2 )
                {
                    $results[$row['cacheset_uuid']]['mentor'] = array(
                        'uuid' => $row['user_uuid'],
                        'username' => $row['username'],
                        'profile_url' => Settings::get('SITE_URL')."viewprofile.php?userid=".$row['user_id']
                    );
                }
            }
            Db::free_result($rs);
        }

        # geocaches_found
        # geocaches_found_ratio
        # min_founds_to_complete
        # my_completed_status

        if (
            count($results) > 0
            && (
                in_array('geocaches_found', $fields)
                || in_array('geocaches_found_ratio', $fields)
                || in_array('min_founds_to_complete', $fields)
                || in_array('my_completed_status', $fields)
            )
        ) {
            if ($request->token == null)
                throw new BadRequest(
                    "Level 3 Authentication is required to access my_notes data."
                );

            $user_id = $request-> token->user_id;

            $rs = Db::query("
                select count(*) as founds, pt.uuid as cacheset_uuid
                from cache_logs
                    join powerTrail_caches
                        on cache_logs.cache_id = powerTrail_caches.cacheId
                    join PowerTrail as pt
                        on powerTrail_caches.PowerTrailId = pt.id
                where
                    cache_logs.user_id = '".Db::escape_string($user_id)."'
                    and cache_logs.type = 1
                    and cache_logs.deleted = 0
                    and pt.uuid in (
                        '".implode("','", array_map('\okapi\Db::escape_string', array_keys($results)))."'
                    )
                group by pt.uuid
            ");

            $founds_in_paths = array();
            foreach(array_keys($results) as $cacheset_uuid)
            {
                $founds_in_paths[$cacheset_uuid] = 0;
            }

            while ($row = Db::fetch_assoc($rs))
            {
                $founds_in_paths[$row['cacheset_uuid']] = $row['founds'];
            }
            Db::free_result($rs);

            $completed_paths = array();
            if(in_array('my_completed_status', $fields))
            {
                # find completed paths
                $completed_paths = Db::select_column("
                    select pt.uuid as cacheset_uuid
                    from
                        PowerTrail_comments
                        join PowerTrail as pt
                            on PowerTrail_comments.PowerTrailId = pt.id
                    where
                        userId = '".Db::escape_string($user_id)."'
                        and commentType = 2
                        and deleted = 0
                        and pt.uuid in (
                            '".implode("','", array_map('\okapi\Db::escape_string', array_keys($results)))."'
                        )
                ");
            }


            foreach($results as $cacheset_uuid => &$ref_path)
            {
                $founds = $founds_in_paths[$cacheset_uuid];


                if(in_array('geocaches_found', $fields))
                    $ref_path['geocaches_found'] = $founds;

                if(in_array('geocaches_found_ratio', $fields))
                {
                    if($founds == 0)
                        $ref_path['geocaches_found_ratio'] = 0;
                    else
                        $ref_path['geocaches_found_ratio'] =
                            $founds / $total_caches_in_paths[$cacheset_uuid];
                }

                if(in_array('min_founds_to_complete', $fields))
                {

                    $caches_to_complete = ceil( $complete_ratio_in_paths[$cacheset_uuid] *
                        $total_caches_in_paths[$cacheset_uuid] / 100) - $founds;

                    $ref_path['min_founds_to_complete'] =
                        ($caches_to_complete < 0) ? 0 : $caches_to_complete;
                }

                if(in_array('my_completed_status', $fields))
                {
                    if(in_array($cacheset_uuid, $completed_paths)){
                        # path completed
                        $ref_path['my_completed_status'] = 'completed';
                    }
                    else
                    {
                        $ref_path['my_completed_status'] =
                        ( $founds >= ceil( $complete_ratio_in_paths[$cacheset_uuid] *
                            $total_caches_in_paths[$cacheset_uuid] / 100 )) ? 'eligable':'not_eligable';
                    }
                }
            }
        }

        # last_completed

        if ( count($results) > 0 && in_array('last_completed', $fields) )
        {
            $rs = Db::query("
                select pt.uuid as cacheset_uuid, max(logDateTime) as last_completed
                from
                    PowerTrail_comments
                    join PowerTrail as pt
                        on PowerTrail_comments.PowerTrailId = pt.id
                where
                    commentType = 2
                    and deleted = 0
                    and pt.uuid in (
                        '".implode("','", array_map('\okapi\Db::escape_string', array_keys($results)))."'
                    )
                group by pt.uuid
            ");

            while ($row = Db::fetch_assoc($rs))
            {
                $results[$row['cacheset_uuid']]['last_completed'] =
                    date('c', strtotime($row['last_completed']));
            }
            Db::free_result($rs);
        }

        # cslog_uuids

        if ( in_array('cslog_uuids', $fields) && count($results) > 0)
        {
            $rs = Db::query("
                select ptc.uuid as cslog_uuid, pt.uuid as cacheset_uuid
                from
                    PowerTrail_comments as ptc
                    join PowerTrail as pt
                        on ptc.PowerTrailId = pt.id
                where pt.uuid in (
                    '".implode("','", array_map('\okapi\Db::escape_string', array_keys($results)))."'
                )
            ");

            while ($row = Db::fetch_assoc($rs))
            {
                if( $process_authors )
                    $results[$row['cacheset_uuid']]['cslog_uuids'][] = $row['cslog_uuid'];

            }
            Db::free_result($rs);
        }

        # Check which cachesets were not found and mark them with null.
        foreach ($cacheset_uuids as $cacheset_uuid)
            if (!isset($results[$cacheset_uuid]))
                $results[$cacheset_uuid] = null;


        # Order the results in the same order as the input uuids were given.
        # This might come in handy for languages which support ordered dictionaries
        # (especially with conjunction with the search_and_retrieve method).
        # See issue#97. PHP dictionaries (assoc arrays) are ordered structures,
        # so we just have to rewrite it (sequentially).

        $ordered_results = new ArrayObject();
        foreach ($cacheset_uuids as $cacheset_uuid)
            $ordered_results[$cacheset_uuid] = $results[$cacheset_uuid];


        return Okapi::formatted_response($request, $ordered_results);
    }
}
