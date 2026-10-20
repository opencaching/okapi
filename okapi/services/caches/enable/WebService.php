<?php

namespace okapi\services\caches\enable;

use okapi\core\Request\OkapiRequest;
use okapi\services\caches\CacheEditCommon;

class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 3
        );
    }

    public static function call(OkapiRequest $request)
    {
        return CacheEditCommon::change_status($request, 'Ready to search');
    }
}
