<?php

namespace okapi\views\apps\index;

use Exception;
use okapi\Okapi;
use okapi\OkapiHttpResponse;
use okapi\OkapiHttpRequest;
use okapi\OkapiRedirectResponse;

class View
{
	public static function call()
	{
		# Ensure a user is logged in.
	
		if ($GLOBALS['usr'] == false)
		{
			$after_login = "okapi/apps/";
			$login_url = $GLOBALS['absolute_server_URI']."login.php?target=".urlencode($after_login);
			return new OkapiRedirectResponse($login_url);
		}
		
		# Get the list of authorized apps.
		
		$rs = sql("
			select c.`key`, c.name, c.url
			from
				okapi_consumers c,
				okapi_authorizations a
			where
				a.user_id = '".mysql_real_escape_string($GLOBALS['usr']['userid'])."'
				and c.`key` = a.consumer_key
			order by c.name
		");
		$vars = array();
		$vars['site_name'] = Okapi::get_normalized_site_name();
		$vars['apps'] = array();
		while ($row = sql_fetch_assoc($rs))
			$vars['apps'][] = $row;
		mysql_free_result($rs);
		
		$response = new OkapiHttpResponse();
		$response->content_type = "text/html; charset=utf-8";
		ob_start();
		include 'index.tpl.php';
		$response->body = ob_get_clean();
		return $response;
	}
}
