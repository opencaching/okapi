<?php

namespace okapi\services\apiref\method_index;

use okapi\OkapiInternalRequest;

use Exception;
use okapi\Okapi;
use okapi\OkapiRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\OkapiServiceRunner;

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
		// WRTODO: cache it!
		
		$methodnames = OkapiServiceRunner::$all_names;
		sort($methodnames);
		$results = array();
		foreach ($methodnames as $methodname)
		{
			$info = OkapiServiceRunner::call('services/apiref/method', new OkapiInternalRequest(
				null, null, array('name' => $methodname)));
			$results[] = array(
				'name' => $info['name'],
				'brief_description' => $info['brief_description'],
			);
		}
		return Okapi::formatted_response($request, $results);
	}
}
