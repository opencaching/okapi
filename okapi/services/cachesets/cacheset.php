<?php

namespace okapi\services\cachesets\cacheset;

use okapi\Okapi;
use okapi\OkapiRequest;
use okapi\Settings;
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
        $langpref = $request->get_parameter('langpref');
        if (!$langpref) $langpref = "en";
        $langpref .= "|".Settings::get('SITELANG');
        $fields = $request->get_parameter('fields');
        if (!$fields) $fields = "uuid|name|type|status|url";

        $params = array(
            'cacheset_uuids' => $cacheset_uuid,
            'langpref' => $langpref,
            'fields' => $fields
        );

        $results = OkapiServiceRunner::call('services/cachesets/cachesets', new OkapiInternalRequest(
            $request->consumer, $request->token, $params));
        $result = $results[$cacheset_uuid];
        if ($result === null)
        {
            $exists = Db::select_value("
                select 1
                from PowerTrail
                where id=".Db::escape_string($cacheset_uuid)."
            ");
            if ($exists) {
                throw new InvalidParam('cacheset_uuid', "This cacheset is not accessible via OKAPI.");
            } else {
                throw new InvalidParam('cacheset_uuid', "This cacheset does not exist.");
            }
        }

        return Okapi::formatted_response($request, $result);
    }
}
