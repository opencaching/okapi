<?php

namespace okapi\views\replication;

use okapi\OkapiHttpRequest;

use Exception;
use okapi\Okapi;
use okapi\OkapiRequest;
use okapi\OkapiHttpResponse;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\OkapiServiceRunner;
use okapi\OkapiInternalRequest;
use okapi\views\menu\OkapiMenu;

class View
{
	public static function call()
	{
		require_once $GLOBALS['rootpath'].'okapi/views/menu.inc.php';
		
		$vars = array(
			'menu' => OkapiMenu::get_menu_html("replication.html"),
			'okapi_base_url' => $GLOBALS['absolute_server_URI']."okapi/",
			'site_url' => $GLOBALS['absolute_server_URI'],
			'site_name' => Okapi::get_normalized_site_name(),
			'installations' => OkapiMenu::get_installations(),
			'okapi_rev' => Okapi::$revision,
		);
		
		$response = new OkapiHttpResponse();
		$response->content_type = "text/html; charset=utf-8";
		ob_start();
		include 'replication.tpl.php';
		$response->body = ob_get_clean();
		return $response;
	}
}
