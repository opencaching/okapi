<?php

namespace okapi\services\apisrv\installation;

use okapi\core\Db;
use okapi\core\Okapi;
use okapi\core\Request\OkapiRequest;
use okapi\Settings;

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
        $result = array();
        $result['site_url'] = Settings::get('SITE_URL');
        $result['okapi_base_url'] = Okapi::get_recommended_base_url();
        $result['okapi_base_urls'] = Okapi::get_allowed_base_urls();
        $result['site_name'] = Okapi::get_normalized_site_name();
        $result['okapi_version_number'] = Okapi::getVersionNumber();
        $result['okapi_revision'] = Okapi::getVersionNumber(); /* Important for backward-compatibility! */
        $result['okapi_git_revision'] = Okapi::getGitRevision();
        $result['registration_url'] = Settings::get('REGISTRATION_URL');
        $result['mobile_registration_url'] = Settings::get('MOBILE_REGISTRATION_URL');
        $result['image_max_upload_size'] = Settings::get('IMAGE_MAX_UPLOAD_SIZE');
        $result['image_rcmd_max_pixels'] = Settings::get('IMAGE_MAX_PIXEL_COUNT');

        # Version 1623 added the 'geocache_passwd_max_length' field. It turned out
        # to have wrong data type (string) only at OCDE. services/caches/edit had
        # not be used until then at least at OCPL sites, and the OCDE field was
        # buggy. So we removed it and added a new 'password_max_length' field with
        # services/caches/capabilities.

        return Okapi::formatted_response($request, $result);
    }
}
