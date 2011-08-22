<?php

namespace okapi\services\caches\formatters\gpx;

use okapi\OkapiHttpResponse;

use okapi\OkapiInternalRequest;

use okapi\OkapiServiceRunner;

use okapi\Okapi;
use okapi\OkapiRequest;
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

	public static function call(OkapiRequest $request)
	{
		$cache_wpts = $request->get_parameter('cache_wpts');
		if (!$cache_wpts) throw new ParamMissing('cache_wpts');
		$langpref = $request->get_parameter('langpref');
		if (!$langpref) $langpref = "en";
		$vars = array();
		foreach (array('ns_ground', 'ns_gsak', 'ns_ox') as $param)
		{
			$val = $request->get_parameter($param);
			if (!$val) $val = "false";
			elseif (!in_array($val, array("true", "false")))
				throw new InvalidParam($param);
			$vars[$param] = ($val == "true");
		}
		$alt_wpts = $request->get_parameter('alt_wpts');
		if (!$alt_wpts) $alt_wpts = "false";
		if ($alt_wpts != "false")
			throw new InvalidParam('alt_wpts', "NOT YET IMPLEMENTED. Please add a comment in our issue tracker.");
		
		$vars['caches'] = OkapiServiceRunner::call('services/caches/geocaches', new OkapiInternalRequest(
			$request->consumer, $request->token, array('cache_wpts' => $cache_wpts,
			'langpref' => $langpref,
			'fields' => 'wpt|name|location|date_created|url|type|status|size'.
				'|difficulty|terrain|description|hint|rating')));
		$vars['installation'] = OkapiServiceRunner::call('services/apisrv/installation', new OkapiInternalRequest(
			null, null, array()));
		$vars['cache_GPX_types'] = self::$cache_GPX_types;
		$vars['cache_GPX_sizes'] = self::$cache_GPX_sizes;
		
		$response = new OkapiHttpResponse();
		$response->content_type = "text/xml; charset=utf-8";
		// $response->content_disposition = 'Content-Disposition: inline; filename="results.gpx"';
		ob_start();
		include 'gpxfile.tpl.php';
		$response->body = ob_get_clean();
		return $response;
	}
}
