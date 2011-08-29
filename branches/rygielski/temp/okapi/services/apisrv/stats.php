<?php

namespace okapi\services\apisrv\stats;

use Exception;
use okapi\Okapi;
use okapi\OkapiRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\OkapiServiceRunner;
use okapi\OkapiInternalRequest;

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
		$result = array();
		
		# The 'totalstats.inc.php' file seems to be written by some kind of a
		# cron script. I don't even have it in my test installation. Anyway,
		# I'll try to include it and get this data out of it... (BTW, wouldn't
		# it be MUCH easier to use memcached, or even any other cache backend,
		# rather than producing these "cache files"?)
		try
		{
			$total_stats_filename = $GLOBALS['dynstylepath']."totalstats.inc.php";
			if (!file_exists($total_stats_filename))
				throw new Exception();
			include_once $total_stats_filename;
			$result['cache_count'] = $GLOBALS['vars']['total_hiddens'] + 0;
			$result['user_count'] = $GLOBALS['vars']['users'] + 0;
		} catch (Exception $e) {
			$result['cache_count'] = 0;
			$result['user_count'] = 0;
		}
		
		return Okapi::formatted_response($request, $result);
	}
}
