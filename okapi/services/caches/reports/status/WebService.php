<?php

namespace okapi\services\caches\reports\status;

use Exception;
use okapi\Okapi;
use okapi\Db;
use okapi\OkapiRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\BadRequest;
use okapi\OkapiInternalRequest;
use okapi\OkapiServiceRunner;
use okapi\OkapiAccessToken;
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
        # User is already verified (via OAuth), but we need to verify the
        # cache code (check if it exists). We will simply call a geocache method
        # on it - this will also throw a proper exception if it doesn't exist.

        $cache_code = $request->get_parameter('cache_code');
        if ($cache_code == null)
            throw new ParamMissing('cache_code');
        $geocache = OkapiServiceRunner::call('services/caches/geocache', new OkapiInternalRequest(
            $request->consumer, $request->token, array('cache_code' => $cache_code, 'fields' => 'internal_id')));


        $result = array(
            'success' => true,
        );
        return Okapi::formatted_response($request, $result);
    }
}
