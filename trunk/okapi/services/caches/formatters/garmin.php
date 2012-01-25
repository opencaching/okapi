<?php

namespace okapi\services\caches\formatters\garmin;

use okapi\Okapi;
use okapi\OkapiRequest;
use okapi\OkapiHttpResponse;
use okapi\OkapiInternalRequest;
use okapi\OkapiServiceRunner;
use okapi\BadRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\services\caches\search\SearchAssistant;

use \ZipArchive;

class WebService
{
	public static function options()
	{
		return array(
			'min_auth_level' => 1
		);
	}
	
	public static function call(OkapiRequest $request)
	{
		$cache_codes = $request->get_parameter('cache_codes');
		if (!$cache_codes) throw new ParamMissing('cache_codes');
		$langpref = $request->get_parameter('langpref');
		if (!$langpref) $langpref = "en";
		
		# Start creating ZIP archive.
		
		$tempfilename = sys_get_temp_dir()."/garmin".time().rand(100000,999999).".zip";
		$zip = new ZipArchive();
		if ($zip->open($tempfilename, ZIPARCHIVE::CREATE) !== true)
			throw new Exception("ZipArchive class could not create temp file $tempfilename. Check permissions!");
		
		# Create basic structure
		
		$zip->addEmptyDir("Garmin");
		$zip->addEmptyDir("Garmin/GPX");
		$zip->addEmptyDir("Garmin/GeocachePhotos");
		
		# Include a GPX file compatible with Garmin devices. It should include all
		# Geocaching.com (groundspeak:) and Opencaching.com (ox:) extensions. It won't
		# include references to images (these will be added as separate files later).
		
		$zip->addFromString("Garmin/GPX/opencaching.gpx",
			OkapiServiceRunner::call('services/caches/formatters/gpx', new OkapiInternalRequest(
			$request->consumer, $request->token, array('cache_codes' => $cache_codes,
			'langpref' => $langpref, 'ns_ground' => 'true', 'ns_ox' => 'true',
			'images' => 'ox:all', 'attrs' => 'ox:tags', 'latest_logs' => 'true',
			'lpc' => 'all')))->body);

		# Then, include all the images.
		
		# Note: Oliver Dietz replied that (theoretically) images with local set to 0 could not
		# be accessed locally. But probably all the files have local set to 1 anyway.
		
		$caches = OkapiServiceRunner::call('services/caches/geocaches', new OkapiInternalRequest(
			$request->consumer, $request->token, array('cache_codes' => $cache_codes,
			'langpref' => $langpref, 'fields' => "images")));
		if (count($caches) > 50)
			throw new InvalidParam('cache_codes', "The maximum number of caches allowed to be downloaded with this method is 50.");
		foreach ($caches as $cache_code => $dict)
		{
			$images = $dict['images'];
			if (count($images) == 0)
				continue;
			$dir = "Garmin/GeocachePhotos/".$cache_code[strlen($cache_code) - 1];
			$zip->addEmptyDir($dir);
			$dir .= "/".$cache_code[strlen($cache_code) - 2];
			$zip->addEmptyDir($dir);
			$dir .= "/".$cache_code;
			$zip->addEmptyDir($dir);
			foreach ($images as $no => $img)
			{
				if ($img['is_spoiler']) {
					$zip->addEmptyDir($dir."/Spoilers");
					$zippath = $dir."/Spoilers/".$img['unique_caption'].".jpg";
				} else {
					$zippath = $dir."/".$img['unique_caption'].".jpg";
				}
				
				# The safest way would be to use the URL, but this would be painfully slow!
				# That's why I am trying to access files directly. This was tested on OCPL server only.
				
				$syspath = $GLOBALS['picdir']."/".$img['uuid'].".jpg";
				if (!file_exists($syspath))
					continue;
				$file = file_get_contents($syspath);
				if ($file)
					$zip->addFromString($zippath, $file);
			}
		}
		
		$zip->close();
		
		$response = new OkapiHttpResponse();
		$response->content_type = "application/zip";
		$response->content_disposition = 'Content-Disposition: attachment; filename="results.zip"';
		#$response->content_type = "plain/text";
		#$response->content_disposition = 'Content-Disposition: inline';
		$response->body = file_get_contents($tempfilename);
		unlink($tempfilename);
		return $response;
	}
}
