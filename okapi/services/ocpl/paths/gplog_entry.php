<?php

namespace okapi\services\OCPL\paths\gplog_entry;

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
        $gplog_uuid = $request->get_parameter('gplog_uuid');
        if (!$gplog_uuid) throw new ParamMissing('gplog_uuid');
        if (strpos($gplog_uuid, "|") !== false) throw new InvalidParam('gplog_uuid');

        $fields = $request->get_parameter('fields');
        if (!$fields) $fields = "date|user|type|comment";

        $params = array(
            'gplog_uuids' => $gplog_uuid,
            'fields' => $fields
        );

        $results = OkapiServiceRunner::call('services/ocpl/paths/gplog_entries', new OkapiInternalRequest(
            $request->consumer, $request->token, $params));
        $result = $results[$gplog_uuid];
        if ($result === null)
        {
            $exists = Db::select_value("
                select 1
                from PowerTrail_comments
                where id=".Db::escape_string($gplog_uuid)."
            ");
            if ($exists) {
                throw new InvalidParam('gplog_uuid', "This geopath log is not accessible via OKAPI.");
            } else {
                throw new InvalidParam('gplog_uuid', "This geopath log does not exist.");
            }
        }

        return Okapi::formatted_response($request, $result);
    }
}
