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
		$cache_code = $request->get_parameter('cache_code');
		if (!$cache_code) throw new ParamMissing('cache_code');
		$langpref = $request->get_parameter('langpref');
		if (!$langpref) $langpref = "en";
		$fields = $request->get_parameter('fields');
		if (!$fields) $fields = "code|name|location|type|status";
		
		# There's no need to validate the fields parameter as the 'geocaches'
		# method does this (it will raise a proper exception on invalid values).
		
		$results = OkapiServiceRunner::call('services/caches/geocaches', new OkapiInternalRequest(
			$request->consumer, $request->token, array('cache_codes' => $cache_code,
			'langpref' => $langpref, 'fields' => $fields)));
		$result = $results[$cache_code];
		if ($result == null)
			throw new InvalidParam('cache_code', "This cache does not exist.");
		return Okapi::formatted_response($request, $result);
	}
}
