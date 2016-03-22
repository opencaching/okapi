<?php

namespace okapi\services\logs\images\delete;

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

    private static function call(OkapiRequest $request)
    {
        # When uploading images, OCPL stores the user_id of the uploader
        # in the 'pictures' table. This is redundant to cache_logs.user_id,
        # because only the log entry author may append images. We will stick
        # to log_entries.user_id here, which is the original value and works
        # for all OC branches.

    }
}
