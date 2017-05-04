<?php

namespace okapi\services\cachesets\cslog_entry;

use okapi\Okapi;
use okapi\OkapiRequest;
use okapi\OkapiServiceRunner;
use okapi\OkapiInternalRequest;
use okapi\ParamMissing;
use okapi\Db;
use okapi\InvalidParam;

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
        $cslog_uuid = $request->get_parameter('cslog_uuid');
        if (!$cslog_uuid) throw new ParamMissing('cslog_uuid');
        if (strpos($cslog_uuid, "|") !== false) throw new InvalidParam('cslog_uuid');

        $fields = $request->get_parameter('fields');
        if (!$fields) $fields = "date|user|type|comment";

        $params = array(
            'cslog_uuids' => $cslog_uuid,
            'fields' => $fields
        );

        $results = OkapiServiceRunner::call('services/cachesets/cslog_entries', new OkapiInternalRequest(
            $request->consumer, $request->token, $params));
        $result = $results[$cslog_uuid];
        if ($result === null)
        {
            $exists = Db::select_value("
                select 1
                from PowerTrail_comments
                where id=".Db::escape_string($cslog_uuid)."
            ");
            if ($exists) {
                throw new InvalidParam('cslog_uuid', "This cacheset log is not accessible via OKAPI.");
            } else {
                throw new InvalidParam('cslog_uuid', "This cacheset log does not exist.");
            }
        }

        return Okapi::formatted_response($request, $result);
    }
}
