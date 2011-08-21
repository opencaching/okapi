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
		
		# Redirect out of the services directory. This is only a shortcut,
		# just to keep all OAuth entry points in one place.
		
		return new OkapiRedirectResponse($GLOBALS['absolute_server_URI']."authorize.php".
			"?oauth_token=".$token_key);
	}
}
