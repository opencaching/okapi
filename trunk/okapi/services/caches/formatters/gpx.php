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
		'Traditional' => 'Traditional Cache',
		'Multi' => 'Multi-Cache',
		'Quiz' => 'Multi-Cache',
		'Event' => 'Event Cache',
		'Virtual' => 'Virtual Cache',
		'Webcam' => 'Webcam Cache',
		'Moving' => 'Multi-Cache',
		'Own' => 'Unknown Cache',
		'Other' => 'Unknown Cache'
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
		$vars = array();
		
		# Validating arguments. We will also assign some of them to the
		# $vars variable which we will use later in the GPS template.
		
		$cache_codes = $request->get_parameter('cache_codes');
		if (!$cache_codes) throw new ParamMissing('cache_codes');
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
		$images = $request->get_parameter('images');
		if (!$images) $images = 'descrefs:nonspoilers';
		if (!in_array($images, array('none', 'descrefs:nonspoilers', 'descrefs:all')))
			throw new InvalidParam('images', "'$images'");
		$vars['images'] = $images;
		$attrs = $request->get_parameter('attrs');
		if (!$attrs) $attrs = 'desc:text';
		if (!in_array($attrs, array('none', 'desc:text')))
			throw new InvalidParam('attrs', "'$attrs'");
		$vars['attrs'] = $attrs;
		
		# We can get all the data we need from the services/caches/geocaches method.
		# We don't need to do any additional queries here.
		
		$fields = 'code|name|location|date_created|url|type|status|size'.
			'|difficulty|terrain|description|hint|rating|owner|url|internal_id';
		if ($vars['images'] != 'none')
			$fields .= "|images";
		if ($vars['attrs'] != 'none')
			$fields .= "|attrnames";
		if ($vars['latest_logs'])
			$fields .= "|latest_logs";
		
		$vars['caches'] = OkapiServiceRunner::call('services/caches/geocaches', new OkapiInternalRequest(
			$request->consumer, $request->token, array('cache_codes' => $cache_codes,
			'langpref' => $langpref, 'fields' => $fields)));
		$vars['installation'] = OkapiServiceRunner::call('services/apisrv/installation', new OkapiInternalRequest(
			null, null, array()));
		$vars['cache_GPX_types'] = self::$cache_GPX_types;
		$vars['cache_GPX_sizes'] = self::$cache_GPX_sizes;
		
		$response = new OkapiHttpResponse();
		$response->content_type = "text/xml; charset=utf-8";
		$response->content_disposition = 'Content-Disposition: attachment; filename="results.gpx"';
		ob_start();
		include 'gpxfile.tpl.php';
		$response->body = ob_get_clean();
		return $response;
	}
}
