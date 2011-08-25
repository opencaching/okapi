<?php

namespace okapi\views\authorized;

use Exception;
use okapi\Okapi;
use okapi\OkapiHttpResponse;
use okapi\OkapiHttpRequest;
use okapi\OkapiRedirectResponse;

class View
{
	public static function call()
	{
		$token_key = isset($_GET['oauth_token']) ? $_GET['oauth_token'] : '';
		$verifier = isset($_GET['oauth_verifier']) ? $_GET['oauth_verifier'] : '';
		
		$rs = sql("
			select
				c.`key` as consumer_key,
				c.name as consumer_name,
				c.url as consumer_url,
				t.verifier
			from
				okapi_consumers c,
				okapi_tokens t
			where
				t.`key` = '".mysql_real_escape_string($token_key)."'
				and t.consumer_key = c.`key`
		");
		$token = sql_fetch_assoc($rs);
		mysql_free_result($rs);

		if (!$token)
		{
			# Probably Request Token has expired or it is already used. We'll
			# just redirect to the OpenCaching main page.
			return new OkapiRedirectResponse($GLOBALS['absolute_server_URI']);
		}
		
		$vars = array(
			'token' => $token,
			'verifier' => $verifier,
			'site_name' => Okapi::get_normalized_site_name()
		);
		$response = new OkapiHttpResponse();
		$response->content_type = "text/html; charset=utf-8";
		ob_start();
		include 'authorized.tpl.php';
		$response->body = ob_get_clean();
		return $response;
	}
}
