<?php

namespace okapi\services\apisrv\installations;

use Exception;
use okapi\Okapi;
use okapi\Cache;
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
		# The list of installations is periodically refreshed by contacting
		# OKAPI repository. This method displays the cached version of it.
		
		$cachekey = 'apisrv/installations';
		$results = Cache::get($cachekey);
		if (!$results)
		{
			# Download the current list of OKAPI servers.
			
			$xml = file_get_contents("http://opencaching-api.googlecode.com/svn/trunk/etc/installations.xml");
			$doc = simplexml_load_string($xml);
			$results = array();
			$i_was_included = false;
			foreach ($doc->installation as $inst)
			{
				$site_url = (string)$inst[0]['site_url'];
				if ($inst[0]['okapi_base_url'])
					$okapi_base_url = (string)$inst[0]['okapi_base_url'];
				else
					$okapi_base_url = $site_url."okapi/";
				if ($inst[0]['site_name'])
					$site_name = (string)$inst[0]['site_name'];
				else
					$site_name = Okapi::get_normalized_site_name($site_url);
				$results[] = array(
					'site_url' => $site_url,
					'site_name' => $site_name,
					'okapi_base_url' => $okapi_base_url,
				);
				if ($site_url == $GLOBALS['absolute_server_URI'])
					$i_was_included = true;
			}
			
			# If running on a local development installation, then include the local
			# installation URL.
			
			if (!$i_was_included)
			{
				$results[] = array(
					'site_url' => $GLOBALS['absolute_server_URI'],
					'site_name' => "DEVELSITE",
					'okapi_base_url' => $GLOBALS['absolute_server_URI']."okapi/",
				);
				# Contact OKAPI developers in order to get added to the official sites list!
			}
			
			# Cache it for 1 hour.
			
			Cache::set($cachekey, $results, 3600);
		}

		return Okapi::formatted_response($request, $results);
	}
}
