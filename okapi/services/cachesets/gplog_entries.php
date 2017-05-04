<?php

namespace okapi\services\ocpl\paths\gplog_entries;

use okapi\Okapi;
use okapi\OkapiRequest;
use okapi\Settings;
use okapi\ParamMissing;
use okapi\Db;
use ArrayObject;
use Exception;
use okapi\services\ocpl\paths\GpLogStatics;


require_once('cacheset_static.inc.php');

class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 1
        );
    }

    private static $valid_field_names = array('uuid','cacheset_uuid', 'date','user','type','comment');


    public static function call(OkapiRequest $request)
    {
        $gplog_uuids = $request->get_parameter('gplog_uuids');
        if ($gplog_uuids === null) throw new ParamMissing('gplog_uuids');
        if ($gplog_uuids === "")
        {
            # Issue 106 requires us to allow empty list of cacheset logs uuids to be passed into this method.
            # All of the queries below have to be ready for $gplog_uuids to be empty!
            $gplog_uuids = array();
        }
        else
            $gplog_uuids = explode("|", $gplog_uuids);

        if ((count($gplog_uuids) > 500) && (!$request->skip_limits))
            throw new InvalidParam('gplog_uuids', "Maximum allowed number of referenced ".
                "cacheset logs is 500. You provided ".count($gplog_uuids)." cache codes.");
        if (count($gplog_uuids) != count(array_unique($gplog_uuids)))
            throw new InvalidParam('gplog_uuids', "Duplicate cacheset logs uuid detected ".
                "(make sure each cacheset log uuid is referenced only once).");


        $fields = $request->get_parameter('fields');
        if (!$fields) $fields = "date|user|type|comment";
        $fields = explode("|", $fields);
        foreach ($fields as $field)
            if (!in_array($field, self::$valid_field_names))
                throw new InvalidParam('fields', "'$field' is not a valid field code.");

        $rs = Db::query("
            select
                id as uuid, userId, PowerTrailId as cacheset_uuid, commentType as type,
                commentText as comment, logDateTime as date
            from
                PowerTrail_comments
            where
                id in ('".implode("','", array_map('\okapi\Db::escape_string', $gplog_uuids))."')
                and deleted <> 1
        ");

        $user_ids = array();
        $results = array();
        while ($row = Db::fetch_assoc($rs))
        {
            $entry = array();
            foreach ($fields as $field)
            {
                switch ($field)
                {
                    case 'uuid': $entry['uuid'] = $row['uuid']; break;
                    case 'cacheset_uuid': $entry['cacheset_uuid'] = $row['cacheset_uuid']; break;
                    case 'date': $entry['date'] = date('c', strtotime($row['date'])); break;
                    case 'user':
                        $user_ids[$row['uuid']] = $row['userId'];
                        /* continued later */
                        break;
                    case 'type':
                        $entry['type'] = GpLogStatics::gplog_type_id2name($row['type']);
                        break;
                    case 'comment':
                        $entry['comment'] = Okapi::fix_oc_html($row['comment'], Okapi::OBJECT_TYPE_CACHESET_LOG);
                        break;

                    default: throw new Exception("Missing field case: ".$field);
                }
            }
            $results[$row['uuid']] = $entry;
        }
        Db::free_result($rs);

        # user

        if (in_array('user', $fields) && (count($results) > 0))
        {
            $rs = Db::query("
                select user_id, uuid, username
                from user
                where user_id in ('".implode("','", array_map('\okapi\Db::escape_string', array_unique($user_ids)))."')
            ");
            $tmp = array();
            while ($row = Db::fetch_assoc($rs))
                $tmp[$row['user_id']] = $row;

            foreach ($results as $gplog_uuid => &$result_ref)
            {
                $row = $tmp[$user_ids[$gplog_uuid]];
                $result_ref['user'] = array(
                    'uuid' => $row['uuid'],
                    'username' => $row['username'],
                    'profile_url' => Settings::get('SITE_URL')."viewprofile.php?userid=".$row['user_id']
                );
            }
            Db::free_result($rs);
        }

        # Check which cacheset logs were not found and mark them with null.
        foreach ($gplog_uuids as $gplog_uuid)
            if (!isset($results[$gplog_uuid]))
                $results[$gplog_uuid] = null;

        # Order the results in the same order as the input uuids were given.
        # This might come in handy for languages which support ordered dictionaries
        # (especially with conjunction with the search_and_retrieve method).
        # See issue#97. PHP dictionaries (assoc arrays) are ordered structures,
        # so we just have to rewrite it (sequentially).

        $ordered_results = new ArrayObject();
        foreach ($gplog_uuids as $gplog_uuid)
            $ordered_results[$gplog_uuid] = $results[$gplog_uuid];


        return Okapi::formatted_response($request, $ordered_results);
    }
}
