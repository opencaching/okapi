<?php

namespace okapi\services\logs\add_image;

use Exception;
use okapi\Okapi;
use okapi\Db;
use okapi\OkapiRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\Settings;
use okapi\BadRequest;


class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 3
        );
    }

    private static function _call(OkapiRequest $request)
    {
    }
}
