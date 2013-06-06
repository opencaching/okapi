<?php

namespace okapi\services\attrs\allmeta;

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
		# The list of attributes is periodically refreshed by contacting OKAPI
		# repository (the refreshing is done via a cronjob). This method
		# displays the cached version of the list.

		require_once 'attr_helper.inc.php';
		$attribute_set = $request->get_parameter('attribute_set');
		if ($attribute_set === null)
			throw new ParamMissing('attribute_set');
		else if ($attribute_set == 'listing')
		{
			$results = array(
				'attributes' => AttrHelper::get_attrdict()
			);
		}
		else if ($attribute_set == 'search')
		{
			$results = array(
				'attributes' => AttrHelper::get_searchdict()
			);
		}
		else
			throw new InvalidParam('attribute_set');

		return Okapi::formatted_response($request, $results);
	}
}
