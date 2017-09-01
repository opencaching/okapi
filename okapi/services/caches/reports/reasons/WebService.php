<?php

namespace okapi\services\caches\reports\reasons;

use okapi\Okapi;
use okapi\Request\OkapiRequest;
use okapi\services\caches\reports\CacheReports;


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
        $langpref = $request->get_parameter('langpref');
        if (!$langpref) $langpref = "en";
        $langprefs = explode("|", $langpref);

        Okapi::gettext_domain_init($langprefs);
        $result = CacheReports::getReasonsWithoutInternalIds();
        Okapi::gettext_domain_restore();

        return Okapi::formatted_response($request, $result);
    }
}
