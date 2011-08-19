<?php

namespace okapi\services\users\user;

use okapi\BadRequest;

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
			'token'      => 'optional',
		);
	}

	public static function call(OkapiRequest $request)
	{
		$user_id = $request->get_parameter('user_id');
		if (!$user_id)
		{
			if ($request->token)
			{
				$user_id = $request->token->user_id;
			}
			else
			{
				throw new BadRequest("You must either: 1. supply the user_id argument, or "
					."2. sign your request with an Access Token.");
			}
		}
		$fields = $request->get_parameter('fields');
		
		# There's no need to validate the fields parameter as the 'users'
		# method does this (it will raise a proper exception on invalid values).
		
		$results = OkapiServiceRunner::call('services/users/users', new OkapiInternalRequest(
			$request->consumer, $request->token, array('user_ids' => $user_id,
			'fields' => $fields)));
		$result = $results[$user_id];
		if ($result == null)
			throw new InvalidParam('user_id', "There is no user by this ID.");
		return Okapi::formatted_response($request, $result);
	}
}
