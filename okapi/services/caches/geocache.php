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
			'consumer'   => 'required',
			'token'      => 'ignored',
		);
	}

	public static function call(OkapiRequest $request)
	{
		$oxcode = $request->get_parameter('oxcode');
		if (!$oxcode) throw new ParamMissing('oxcode');
		$fields = $request->get_parameter('fields');
		if (!$fields) $fields = "oxcode|name|location|type|status";
		
		# There's no need to validate the fields parameter as the 'geocaches'
		# method does this (it will raise a proper exception on invalid values).
		
		$results = OkapiServiceRunner::call('services/caches/geocaches', new OkapiInternalRequest(
			$request->consumer, $request->token, array('oxcodes' => $oxcode,
			'fields' => $fields)));
		$result = $results[$oxcode];
		if ($result == null)
			throw new InvalidParam('oxcode', "There is no geocache by this OX code.");
		return Okapi::formatted_response($request, $result);
	}
}
