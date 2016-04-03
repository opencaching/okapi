<?php

namespace okapi\services\apisrv\installation;

use Exception;
use okapi\Okapi;
use okapi\Settings;
use okapi\OkapiRequest;

class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 0
        );
    }

    public static function call(OkapiRequest $request)
    {
        switch (Settings::get('DB_CHARSET'))
        {
            case 'utf8':
                $submit_charset = 'utf8mb2';
                break;

            case 'utf8mb4':
                $submit_charset = 'utf8mb4';
                break;

            default:
                throw new Exception('Unknown DB_CHARSET: ' . Settings::get('DB_CHARSET'));
        }

        $result = array();
        $result['site_url'] = Settings::get('SITE_URL');
        $result['okapi_base_url'] = $result['site_url']."okapi/";
        $result['site_name'] = Okapi::get_normalized_site_name();
        $result['okapi_version_number'] = Okapi::$version_number;
        $result['okapi_revision'] = Okapi::$version_number; /* Important for backward-compatibility! */
        $result['okapi_git_revision'] = Okapi::$git_revision;
        $result['registration_url'] = Settings::get('REGISTRATION_URL');
        $result['mobile_registration_url'] = Settings::get('MOBILE_REGISTRATION_URL');
        $result['submit_charset'] = $submit_charset;
        $result['image_max_upload_size'] = Settings::get('IMAGE_MAX_UPLOAD_SIZE');
        $result['image_rcmd_max_pixels'] = Settings::get('IMAGE_MAX_PIXEL_COUNT');
        return Okapi::formatted_response($request, $result);
    }
}
