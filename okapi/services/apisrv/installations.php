<?php

namespace okapi\services\apisrv\installations;

use Exception;
use okapi\Okapi;
use okapi\OkapiRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\OkapiServiceRunner;
use okapi\OkapiInternalRequest;

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
		# Currently this list is fixed. We might think about something
		# more dynamic in the future, but probably fixed will do ok.
		
		$sites = array(
			"http://opencaching.pl/",
			"http://www.opencaching.us/",
			// hopefully coming soon - "http://www.opencaching.de/"
		);
		
		# If running on a local developer installation, then include the local
		# installation URL.
		
		if (!in_array($GLOBALS['absolute_server_URI'], $sites))
			$sites[] = $GLOBALS['absolute_server_URI'];
		
		$result = array();
		foreach ($sites as $site_url)
			$result[] = array(
				'site_url' => $site_url,
				'okapi_base_url' => $site_url."okapi/",
				'site_name' => Okapi::get_normalized_site_name($site_url)
			);
		return Okapi::formatted_response($request, $result);
	}
}
