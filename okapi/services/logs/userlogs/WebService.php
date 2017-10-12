<?php

namespace okapi\services\logs\userlogs;

use okapi\core\Db;
use okapi\core\Exception\InvalidParam;
use okapi\core\Exception\ParamMissing;
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
            'min_auth_level' => 1
        );
    }
    public static function call(OkapiRequest $request)
    {
        $user_uuid = $request->get_parameter('user_uuid');
        if (!$user_uuid) throw new ParamMissing('user_uuid');

        $fields = $request->get_parameter('fields');
        if (!$fields) $fields = "uuid|date|cache_code|type|comment";  // validation is done on call

        $limit = $request->get_parameter('limit');
        if (!$limit) $limit = "20";
        if (!is_numeric($limit))
            throw new InvalidParam('limit', "'$limit'");
        $limit = intval($limit);
        if (($limit < 1) || ($limit > 1000))
            throw new InvalidParam('limit', "Has to be in range 1..1000.");

        $offset = $request->get_parameter('offset');
        if (!$offset) $offset = "0";
        if (!is_numeric($offset))
            throw new InvalidParam('offset', "'$offset'");
        $offset = intval($offset);
        if ($offset < 0)
            throw new InvalidParam('offset', "'$offset'");

        # Check if user exists and retrieve user's ID (this will throw
        # a proper exception on invalid UUID).
        $user = OkapiServiceRunner::call('services/users/user', new OkapiInternalRequest(
            $request->consumer, null, array('user_uuid' => $user_uuid, 'fields' => 'internal_id')));

        # User exists. Retrieving logs.

        # If the user only requests the default fields or other "basic fields" which
        # can easily be handled, we will directly serve the request. Otherwise we call
        # the more expensive logs/entries method.

        $basic_fields = ['uuid', 'date', 'cache_code', 'type', 'comment', 'internal_id'];
        $only_basic_fields = true;

        $fields_array = explode('|', $fields);
        foreach ($fields_array as $field) {
            if (!in_array($field, $basic_fields)) {
                $only_basic_fields = false;
                break;
            }
        }

        if ($only_basic_fields)
            $add_fields_SQL = ", unix_timestamp(cl.date) as date, c.wp_oc as cache_code, cl.type, cl.text, cl.text_html, cl.id";
        else
            $add_fields_SQL = "";

        # See caches/geocaches/WebService.php for explanation.
        if (Settings::get('OC_BRANCH') == 'oc.de') {
            $logs_order_field_SQL = 'order_date';
        } else {
            $logs_order_field_SQL = 'date';
        }

        $query = "
            select cl.uuid $add_fields_SQL
            from cache_logs cl, caches c
            where
                cl.user_id = '".Db::escape_string($user['internal_id'])."'
                and ".((Settings::get('OC_BRANCH') == 'oc.pl') ? "cl.deleted = 0" : "true")."
                and c.status in (1,2,3)
                and cl.cache_id = c.cache_id
            order by cl.$logs_order_field_SQL desc, cl.date_created desc, cl.id desc
            limit $offset, $limit
        ";

        if ($only_basic_fields)
        {
            $rs = Db::query($query);
            $results = [];
            while ($row = Db::fetch_assoc($rs))
            {
                $results[] = array(
                    'uuid' => $row['uuid'],
                    'date' => date('c', $row['date']),
                    'cache_code' => $row['cache_code'],
                    'type' => Okapi::logtypeid2name($row['type']),
                    'comment' => Okapi::fix_oc_html($row['text'], $row['text_html']),
                    'internal_id' => $row['id'],
                );
            }
            Db::free_result($rs);

            # Remove unwanted fields.

            foreach ($basic_fields as $field)
                if (!in_array($field, $fields_array))
                    foreach ($results as &$result_ref)
                        unset($result_ref[$field]);
        }
        else
        {
            $log_uuids = Db::select_column($query);
            $logsRequest = new OkapiInternalRequest(
                $request->consumer,
                $request->token,
                array(
                    'log_uuids' => implode('|', $log_uuids),
                    'fields' => $fields
                )
            );
            $logsRequest->skip_limits = true;
            $logsResponse = OkapiServiceRunner::call("services/logs/entries", $logsRequest);
            $results = array_values($logsResponse);
        }

        return Okapi::formatted_response($request, $results);
    }
}
