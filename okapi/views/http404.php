<?php

namespace okapi\views\http404;

use Exception;
use okapi\OkapiHttpResponse;
use okapi\views\menu\OkapiMenu;

class View
{
	public static function call()
	{
		require_once 'menu.inc.php';
		
		$vars = array(
			'menu' => OkapiMenu::get_menu_html(),
		);
		
		$response = new OkapiHttpResponse();
		$response->status = 404;
		$response->content_type = "text/html; charset=utf-8";
		ob_start();
		include 'http404.tpl.php';
		$response->body = ob_get_clean();
		return $response;
	}
}
