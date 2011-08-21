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
		
		$result = array(
			array(
				'site_url' => "http://opencaching.pl/",
				'okapi_base_url' => "http://opencaching.pl/okapi/"
			),
			/*array(
				'site_url' => "http://www.opencaching.de/",
				'okapi_base_url' => "http://www.opencaching.de/okapi/"
			)*/
			
		);
		return Okapi::formatted_response($request, $result);
	}
}
