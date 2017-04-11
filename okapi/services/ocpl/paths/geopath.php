<?php

# TODO:...
# This method is the simplest of all. It just returns all cashes, in any order.
# Results might be limited only with the "standard filtering arguments",
# implemented in the OkapiSearchAssistant::get_common_search_params.
#
# Its existance is intentional - though a bit inpractical, it serves as a
# reference base for every other search method which might use "standard
# filters" (those defined in OkapiSearchAssistant::get_common_search_params).

namespace okapi\services\OCPL\paths\geopath;

use okapi\Okapi;
use okapi\OkapiRequest;
use okapi\Settings;
use okapi\OkapiServiceRunner;
use okapi\OkapiInternalRequest;
use okapi\ParamMissing;

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
        $path_uuid = $request->get_parameter('path_uuid');
        if (!$path_uuid) throw new ParamMissing('path_uuid');
        if (strpos($path_uuid, "|") !== false) throw new InvalidParam('path_uuid');
        $langpref = $request->get_parameter('langpref');
        if (!$langpref) $langpref = "en";
        $langpref .= "|".Settings::get('SITELANG');
        $fields = $request->get_parameter('fields');
        if (!$fields) $fields = "uuid|name|type|status|url";

        $params = array(
            'path_uuids' => $path_uuid,
            'langpref' => $langpref,
            'fields' => $fields
        );

        $results = OkapiServiceRunner::call('services/ocpl/paths/geopaths', new OkapiInternalRequest(
            $request->consumer, $request->token, $params));
        $result = $results[$path_uuid];
        if ($result === null)
        {
            $exists = Db::select_value("
                select 1
                from PowerTrail
                where id=".Db::escape_string($path_uuid)."
            ");
            if ($exists) {
                throw new InvalidParam('path_uuid', "This geopath is not accessible via OKAPI.");
            } else {
                throw new InvalidParam('path_uuid', "This geopath does not exist.");
            }
        }

        return Okapi::formatted_response($request, $result);
    }
}
