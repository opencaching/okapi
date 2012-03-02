<?php

namespace okapi;

use Exception;
use okapi\Okapi;
use okapi\views\menu\OkapiMenu;

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
require_once($rootpath.'okapi/urls.php');

# OKAPI does not use sessions. The following statement will allow concurrent
# requests to be fired from browser. (BTW, it would be nice to prevent OC code
# from creating a session cookie while displaying OKAPI.)
if (session_id()) session_write_close();

class OkapiScriptEntryPointController
{
	public static function dispatch_request($uri)
	{
		Okapi::init_internals();
		
		# Chop off the ?args=... part.
		
		if (strpos($uri, '?') !== false)
			$uri = substr($uri, 0, strpos($uri, '?'));
		
		# Make sure we're in the right directory (.htaccess should make sure of that).
		
		if (strpos($uri, "/okapi/") !== 0)
			throw new Exception("'$uri' is outside of the /okapi/ path.");
		$uri = substr($uri, 7);
		
		# Checking for allowed patterns...
		
		try
		{
			foreach (OkapiUrls::$mapping as $pattern => $namespace)
			{
				$matches = null;
				if (preg_match("#$pattern#", $uri, $matches))
				{
					# Pattern matched! Moving on to the proper View...
					
					array_shift($matches);
					require_once "views/$namespace.php";
					$response = call_user_func_array(array('\\okapi\\views\\'.
						str_replace('/', '\\', $namespace).'\\View', 'call'), $matches);
					if ($response)
						$response->display();
					return;
				}
			}
		}
		catch (Http404 $e)
		{
			/* pass */
		}
		
		# None of the patterns matched OR method threw the Http404 exception.
		
		require_once "views/http404.php";
		$response = \okapi\views\http404\View::call();
		$response->display();
	}
}

Okapi::gettext_domain_init();
OkapiScriptEntryPointController::dispatch_request($_SERVER['REQUEST_URI']);
Okapi::gettext_domain_restore();
