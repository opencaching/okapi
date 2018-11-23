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

        # 'geocache_passwd_max_length' was replaced by 'password_max_length'
        # in the new services/caches/capabilities method. It had never been
        # in use at OCPL sites (services/caches/edit was never called).
        # We leave it here as undocumented OCDE field, until we can verify
        # if it was in use there. If no - drop it. If yes - document it as a
        # deprecated OCDE-only field.

        # Note that for backward compatibility with a former Db::field_length()
        # bug, the OCDE field is of type string, while the OCPL field was
        # (as intended) numeric.

        if (Settings::get('OC_BRANCH') == 'oc.de')
            $result['geocache_passwd_max_length'] = (string)Db::field_length('caches', 'logpw');

        return Okapi::formatted_response($request, $result);
    }
}
