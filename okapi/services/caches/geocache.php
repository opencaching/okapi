<?php

namespace okapi\services\caches\geocache;

use okapi\OkapiInternalRequest;

use okapi\OkapiServiceRunner;

use okapi\Okapi;
use okapi\OkapiRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\services\caches\search\SearchAssistant;

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
		$cache_wpt = $request->get_parameter('cache_wpt');
		if (!$cache_wpt) throw new ParamMissing('cache_wpt');
		$fields = $request->get_parameter('fields');
		if (!$fields) $fields = "wpt|name|location|type|status";
		
		# There's no need to validate the fields parameter as the 'geocaches'
		# method does this (it will raise a proper exception on invalid values).
		
		$results = OkapiServiceRunner::call('services/caches/geocaches', new OkapiInternalRequest(
			$request->consumer, $request->token, array('cache_wpts' => $cache_wpt,
			'fields' => $fields)));
		$result = $results[$cache_wpt];
		if ($result == null)
			throw new InvalidParam('cache_wpt', "There is no geocache by this waypoint code.");
		return Okapi::formatted_response($request, $result);
	}
}
