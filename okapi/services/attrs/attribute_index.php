<?php

namespace okapi\services\attrs\attribute_index;

use Exception;
use ErrorException;
use okapi\Okapi;
use okapi\Settings;
use okapi\Cache;
use okapi\OkapiRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\OkapiServiceRunner;
use okapi\OkapiInternalRequest;
use okapi\services\attrs\AttrHelper;


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
		# Read the parameters.

		$langpref = $request->get_parameter('langpref');
		if (!$langpref) $langpref = "en";

		$fields = $request->get_parameter('fields');
		if (!$fields) $fields = "name";

		# Get the list of all valid A-codes.

		require_once 'attr_helper.inc.php';
		$acodes = implode("|", array_keys(AttrHelper::get_attrdict()));

		# Retrieve the attribute objects and return the results.

		$params = array(
			'acodes' => $acodes,
			'langpref' => $langpref,
			'fields' => $fields,
		);
		$results = OkapiServiceRunner::call('services/attrs/attributes',
			new OkapiInternalRequest($request->consumer, $request->token, $params));
		return Okapi::formatted_response($request, $results);
	}
}
