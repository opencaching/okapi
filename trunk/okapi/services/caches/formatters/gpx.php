<?php

namespace okapi\services\caches\formatters\gpx;

use okapi\Okapi;
use okapi\OkapiRequest;
use okapi\OkapiHttpResponse;
use okapi\OkapiInternalRequest;
use okapi\OkapiServiceRunner;
use okapi\BadRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\services\caches\search\SearchAssistant;

class WebService
{
	public static function options()
	{
		return array(
			'min_auth_level' => 1
		);
	}

	/** Maps OKAPI cache type codes to Geocaching.com GPX cache types. */
	public static $cache_GPX_types = array(
		'traditional' => 'Traditional Cache',
		'multi' => 'Multi-Cache',
		'quiz' => 'Multi-cache',
		'event' => 'Event Cache',
		'virtual' => 'Virtual Cache',
		'webcam' => 'Webcam Cache',
		'moving' => 'Multi-cache',
		'own' => 'Unknown Cache',
		'other' => 'Unknown Cache'
	);
	
	/** Maps OpenCaching cache sizes Geocaching.com size codes. */
	public static $cache_GPX_sizes = array(
		1 => 'Micro',
		2 => 'Micro',
		3 => 'Small',
		4 => 'Regular',
		5 => 'Large',
		null => 'Other'
	);
	
	/** Maps OKAPI log type codes to "universal" log type names. */
	public static $GPX_log_types = array(
		'found' => "Found it",
		'not_found' => "Didn't find it",
		'comment' => "Comment"
	);

	public static function call(OkapiRequest $request)
	{
		$vars = array();
		
		# Validating arguments. We will also assign some of them to the
		# $vars variable which we will use later in the GPS template.
		
		$cache_wpts = $request->get_parameter('cache_wpts');
		if (!$cache_wpts) throw new ParamMissing('cache_wpts');
		$langpref = $request->get_parameter('langpref');
		if (!$langpref) $langpref = "en";
		foreach (array('ns_ground', 'ns_gsak', 'ns_ox', 'latest_logs') as $param)
		{
			$val = $request->get_parameter($param);
			if (!$val) $val = "false";
			elseif (!in_array($val, array("true", "false")))
				throw new InvalidParam($param);
			$vars[$param] = ($val == "true");
		}
		if ($vars['latest_logs'] && (!$vars['ns_ground']))
			throw new BadRequest("In order for 'latest_logs' to work you have to also include 'ns_ground' extensions.");
		$alt_wpts = $request->get_parameter('alt_wpts');
		if (!$alt_wpts) $alt_wpts = "false";
		if ($alt_wpts != "false")
			throw new InvalidParam('alt_wpts', "NOT YET IMPLEMENTED. Please add a comment in our issue tracker.");
		
		# We can get all the data we need from the services/caches/geocaches method.
		# We don't need to do any additional queries here.
		
		$fields = 'wpt|name|location|date_created|url|type|status|size'.
			'|difficulty|terrain|description|hint|rating|owner|url';
		if ($vars['latest_logs'])
			$fields .= "|latest_logs";
		$vars['caches'] = OkapiServiceRunner::call('services/caches/geocaches', new OkapiInternalRequest(
			$request->consumer, $request->token, array('cache_wpts' => $cache_wpts,
			'langpref' => $langpref, 'fields' => $fields)));
		$vars['installation'] = OkapiServiceRunner::call('services/apisrv/installation', new OkapiInternalRequest(
			null, null, array()));
		$vars['cache_GPX_types'] = self::$cache_GPX_types;
		$vars['cache_GPX_sizes'] = self::$cache_GPX_sizes;
		$vars['GPX_log_types'] = self::$GPX_log_types;
		
		$response = new OkapiHttpResponse();
		$response->content_type = "text/xml; charset=utf-8";
		$response->content_disposition = 'Content-Disposition: attachment; filename="results.gpx"';
		ob_start();
		include 'gpxfile.tpl.php';
		$response->body = ob_get_clean();
		return $response;
	}
}
