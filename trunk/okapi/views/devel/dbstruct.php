<?php

namespace okapi\views\devel\dbstruct;

use Exception;
use okapi\Okapi;
use okapi\Cache;
use okapi\Db;
use okapi\OkapiRequest;
use okapi\OkapiRedirectResponse;
use okapi\OkapiHttpResponse;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\OkapiServiceRunner;
use okapi\OkapiInternalRequest;

class View
{
	public static function call()
	{
		# This is a hidden page for OKAPI developers. It will output a complete
		# structure of the database. This is useful for making OKAPI compatible
		# across different OC installations.
		
		$user = $GLOBALS['dbusername'];
		$password = $GLOBALS['dbpasswd'];
		$dbname = $GLOBALS['dbname'];
		$struct = shell_exec("mysqldump --no-data -u$user -p$password $dbname");
		
		# Remove the "AUTO_INCREMENT=..." values. They break the diffs.
		$struct = preg_replace("/ AUTO_INCREMENT=([0-9]+)/i", "", $struct);
		
		$response = new OkapiHttpResponse();
		$response->content_type = "text/plain; charset=utf-8";
		$response->body = $struct;
		return $response;
	}

}
