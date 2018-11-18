<?php

namespace okapi\services\caches\capabilities;

use okapi\core\Db;
use okapi\core\Exception\InvalidParam;
use okapi\core\Okapi;
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
        $result = array();

        $result['types'] = Okapi::get_local_okapi_cache_types();
        $result['sizes'] = Okapi::get_local_cache_sizes();
        $result['has_ratings'] = (Settings::get('OC_BRANCH') == 'oc.pl');
        $result['password_max_length'] = Db::field_length('caches', 'logpw') + 0;

        return Okapi::formatted_response($request, $result);
    }
}
