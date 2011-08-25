<?php

namespace okapi\services\oauth\authorize;

use okapi\Okapi;
use okapi\OkapiRedirectResponse;
use okapi\OkapiRequest;
use okapi\ParamMissing;

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
		$token_key = $request->get_parameter('oauth_token');
		if (!$token_key)
			throw new ParamMissing("oauth_token");
		
		# Redirect to the authorization page.
		
		return new OkapiRedirectResponse($GLOBALS['absolute_server_URI']."okapi/authorize".
			"?oauth_token=".$token_key);
	}
}
