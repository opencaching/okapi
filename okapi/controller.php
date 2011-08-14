<?php

namespace okapi;

use Exception;

#
# All HTTP requests within the /okapi/ path are redirected through this
# controller. From here we'll pass them to the right entry point (or
# display an appropriate error message).
#
# To learn more about OKAPI, see core.php.
# 

$rootpath = '../';
require_once($rootpath.'okapi/core.php');
OkapiErrorHandler::$treat_notices_as_errors = true;

class OkapiScriptEntryPointController
{
	public static function dispatch_request($uri)
	{
		# Chop off the ?args=... part. (These parameters are parsed later.)
		if (strpos($uri, '?') !== false)
			$uri = substr($uri, 0, strpos($uri, '?'));
		
		# Make sure we're in the right directory (.htaccess should make sure of that).
		if (strpos($uri, "/okapi/") !== 0)
			throw new Exception("'$uri' is outside of the /okapi/ path.");
		
		# Checking for allowed patterns...
		
		if (strpos($uri, "/okapi/services/") !== false && (substr($uri, -5) != '.html')
			&& substr($uri, -1) != "/")
		{
			# Services URL which does not end with ".html" nor with "/" - must
			# be a service call. If method does not exist, the 404 notice will
			# be displayed in plain text.
			
			$service_name = substr($uri, strlen("/okapi/"));
			$okapi_response = self::dispatch_service_call($service_name);
			$okapi_response->display();
		}
		else
		{
			# Let's check if there is a documentation handler for that page.
			
			require_once 'doc_viewer.php';
			$path = substr($uri, strlen("/okapi/"));
			if (OkapiDocViewer::is_valid_doc($path))
			{
				OkapiDocViewer::display_doc($path);
			}
			else
			{
				# URI does not fit any of the allowed patterns. We'll display
				# a HTML-formatted 404 page.
				
				OkapiDocViewer::display_404();
			}
		}
	}
	
	public static function dispatch_service_call($service_name)
	{
		require_once 'service_runner.php';

		if (!OkapiServiceRunner::exists($service_name))
			throw new BadRequest("Method '$service_name' does not exist.");
		$options = OkapiServiceRunner::options($service_name);
		$request = new OkapiHttpRequest($options);
		return OkapiServiceRunner::call($service_name, $request);
	}
}

OkapiScriptEntryPointController::dispatch_request($_SERVER['REQUEST_URI']);
