<?php

namespace okapi\services\cachesets\cacheset_logs;

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
        $cacheset_uuid = $request->get_parameter('cacheset_uuid');
        if (!$cacheset_uuid) throw new ParamMissing('cacheset_uuid');
        if (strpos($cacheset_uuid, "|") !== false) throw new InvalidParam('cacheset_uuid');

        $fields = $request->get_parameter('fields');
        if (!$fields) $fields = "date|user|type|comment";

        $offset = $request->get_parameter('offset');
        if (!$offset) $offset = "0";
        if ((((int)$offset) != $offset) || ((int)$offset) < 0)
            throw new InvalidParam('offset', "Expecting non-negative integer.");

        $limit = $request->get_parameter('limit');
        if (!$limit) $limit = "none";
        if ($limit == "none") $limit = "999999999";
        if ((((int)$limit) != $limit) || ((int)$limit) < 0)
            throw new InvalidParam('limit', "Expecting non-negative integer or 'none'.");

        # Check if this cacheset exists and retrieve its UUID (this will throw
        # a proper exception on invalid code).

        $cacheset_uuid = OkapiServiceRunner::call('services/cachesets/cacheset', new OkapiInternalRequest(
            $request->consumer, null, array('cacheset_uuid' => $cacheset_uuid, 'fields' => 'uuid')));

        # Cacheset exists. Getting the uuids of its logs.

        $log_uuids = Db::select_column("
            select id as uuid
            from PowerTrail_comments
            where PowerTrailId = '".Db::escape_string($cacheset_uuid['uuid'])."'
                and deleted <> 1
            order by logDateTime desc
            limit $offset, $limit
        ");

        # Getting the logs themselves. Formatting as an ordered list.
        $internal_request = new OkapiInternalRequest(
            $request->consumer, $request->token, array('cslog_uuids' => implode("|", $log_uuids),
                'fields' => $fields));

        $internal_request->skip_limits = true;
        $logs = OkapiServiceRunner::call('services/cachesets/cslog_entries', $internal_request);

        $results = array();
        foreach ($log_uuids as $log_uuid)
            $results[] = $logs[$log_uuid];

        return Okapi::formatted_response($request, $results);

    }
}
