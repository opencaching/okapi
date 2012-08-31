<?php

namespace okapi\services\caches\map\tile;

use Exception;
use okapi\Okapi;
use okapi\Cache;
use okapi\Db;
use okapi\OkapiRequest;
use okapi\OkapiHttpResponse;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\BadRequest;
use okapi\DoesNotExist;
use okapi\OkapiInternalRequest;
use okapi\OkapiInternalConsumer;
use okapi\OkapiServiceRunner;


class WebService
{
	public static function options()
	{
		return array(
			'min_auth_level' => 1
		);
	}
	
	private static function require_uint($request, $name, $min_value = 0)
	{
		$val = $request->get_parameter($name);
		if ($val === null)
			throw new ParamMissing($name);
		$ret = intval($val);
		if ($ret < 0 || ("$ret" !== $val))
			throw new InvalidParam($name, "Expecting non-negative integer.");
		return $ret;
	}
	
	public static function call(OkapiRequest $request)
	{
		$NOCACHE = false;  # Set to true when debugging.
		
		# Make sure the request is internal.
		
		if (!in_array($request->consumer->key, array('internal', 'facade')))
			throw new BadRequest("Your Consumer Key has not been allowed to access this method.");
		
		# Import required tile-specific parameters.
		
		$x = self::require_uint($request, 'x');
		$y = self::require_uint($request, 'y');
		$zoom = self::require_uint($request, 'z');

		# Import all other parameters. They will be passed on to the search/bbox
		# method.
		
		$params = array();
		foreach ($request->get_all_parameters_including_unknown() as $k => $v)
		{
			if (in_array($k, array('x', 'y', 'zoom', 'limit', 'offset', 'order_by')))
				continue;
			$params[$k] = $v;
		}
		$params['limit'] = "10000";
		$params['order_by'] = "code";
		
		# Convert x, y and zoom to proper bbox parameter for the search/bbox method.
		
		$rect = self::xyz_to_latlon_rect($x, $y, $zoom);
		$margin = ($zoom <= 12) ? 0.05 : 0.15;
		$s = $rect->y - $rect->height * $margin;
		$n = $rect->y + $rect->height * (1.0 + $margin);
		$w = $rect->x - $rect->width * $margin;
		$e = $rect->x + $rect->width * (1.0 + $margin);
		$params['bbox'] = "$s|$w|$n|$e";
		
		# Find caches.
		
		$internal_request = new OkapiInternalRequest($request->consumer, $request->token, $params);
		$internal_request->skip_limits = true;
		$response = OkapiServiceRunner::call("services/caches/search/bbox", $internal_request);
		$cache_codes = $response['results'];
		
		# We will often ask for the same set of caches here. The moment I write this,
		# "geocaches" method doesn't cache data at all (to make sure it's always fresh).
		# However, in this case, we want the result cached. We will cache it ourselves.
		
		$cache_key = "tc".$zoom."/".md5(implode("|", $cache_codes));
		$caches = $NOCACHE ? null : Cache::get($cache_key);
		if ($caches === null)
		{
			$internal_request = new OkapiInternalRequest($request->consumer, $request->token, array(
				'cache_codes' => implode('|', $cache_codes),
				'fields' => 'code|name|location|type|status|rating|recommendations|founds|is_found'
			));
			$internal_request->skip_limits = true;
			$caches = OkapiServiceRunner::call("services/caches/geocaches", $internal_request);

			# Filter caches based on proximity to other groups of caches.
			# We want to avoid putting multiple markers in one place. This
			# method is slow, we want its results cached.
		
			$caches = self::choose_caches($x, $y, $zoom, $caches);
			
			# We want to cache lower zoom levels longer. We don't want to cache
			# highest zoom levels at all (they should be fast enough to generate,
			# and we don't want to clutter the cache table).
			
			if ($zoom <= 5) $timeout = 7 * 86400;
			elseif ($zoom == 6) $timeout = 3 * 86400;
			elseif (($zoom >= 7) && ($zoom <= 9)) $timeout = 86400;
			elseif ($zoom == 10) $timeout = 12 * 3600;
			elseif ($zoom == 11) $timeout = 6 * 3600;
			elseif ($zoom == 12) $timeout = 3 * 3600;
			elseif (($zoom >= 13) && ($zoom <= 16)) $timeout = 3600;
			else $timeout = 0;
			if ($timeout > 0)
				Cache::set($cache_key, $caches, $timeout);
		}
		
		# Every image has its unique hash. We will compute this hash before
		# we draw the image. This will allow us to retrieve the image from
		# cache if we had already drawn such image.
		
		$response = new OkapiHttpResponse();
		$response->content_type = "image/png";
		$cache_key = self::compute_hash($x, $y, $zoom, $caches);
		$response->body = $NOCACHE ? null : Cache::get($cache_key);
		if ($response->body === null)
		{
			$im = imagecreatetruecolor(256, 256);
			imagealphablending($im, false); 
			$transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
			imagefilledrectangle($im, 0, 0, 256, 256, $transparent);
			imagealphablending($im, true);
			foreach ($caches as $cache)
				self::draw_cache($im, $x, $y, $zoom, $cache);
			ob_start();
			imagesavealpha($im, true);
			imagepng($im);
			imagedestroy($im);
			$response->body = ob_get_clean();
			Cache::set($cache_key, $response->body, 14*86400);
		}

		return $response;
	}
	
	private static function compute_hash($x, $y, $zoom, $caches)
	{
		$version = 7;
		$data = array($version, $x, $y, $zoom);

		# Currently, image depends only on cache locations.
		
		foreach ($caches as $cache)
			$data[] = $cache['location'];
		return "tile/".md5(implode("|", $data));
	}
	
	private static function choose_caches($x, $y, $zoom, $caches)
	{
		# Divide our rect into squares of size 4x4. We will put AT MOST
		# one cache in each square. If N caches fit into the square, only
		# the *last* one from list will occupy it.
		
		$squares = array();
		foreach ($caches as $cache)
		{
			list($lat, $lon) = explode('|', $cache['location']);
			$pt = self::latlon_to_tile_xy($x, $y, $zoom, $lat, $lon);
			$key = floor($pt['x'] / 4) * 64 + floor($pt['y'] / 4);
			if (isset($squares[$key]))
				$cache['covers_more'] = true;
			$squares[$key] = $cache;
		}
		return array_values($squares);
	}

	private static $images = array();
	private static function get_image($key)
	{
		if (!isset(self::$images[$key]))
			self::$images[$key] = imagecreatefrompng(
				$GLOBALS['rootpath']."okapi/static/tilemap/$key.png");
		return self::$images[$key];
	}
	
	private static function draw_cache($im, $x, $y, $zoom, $cache)
	{
		switch ($cache['type']) {
			case 'Traditional': $key = 'traditional'; break;
			case 'Multi': $key = 'multi'; break;
			case 'Event': $key = 'event'; break;
			case 'Quiz': $key = 'quiz'; break;
			case 'Virtual': $key = 'virtual'; break;
			default: $key = 'other'; break;
		}
		if ($zoom <= 9)
		{
			$marker = self::get_image("tiny_$key");
			$width = 10;
			$height = 10;
			$center_x = 5;
			$center_y = 6;
			$markercenter_x = 5;
			$markercenter_y = 6;
		}
		elseif ($zoom <= 12)
		{
			$marker = self::get_image("medium_$key");
			$width = 14;
			$height = 14;
			$center_x = 7;
			$center_y = 8;
			$markercenter_x = 7;
			$markercenter_y = 8;
		}
		else
		{
			$marker = self::get_image("large_$key");
			$width = 40;
			$height = 32;
			$center_x = 12;
			$center_y = 26;
			$markercenter_x = 12;
			$markercenter_y = 12;
		}
		
		# Convert lat,lon to x,y.
		
		list($lat, $lon) = explode('|', $cache['location']);
		$pt = self::latlon_to_tile_xy($x, $y, $zoom, $lat, $lon);
		
		# If cache covers more caches, then put two markers instead of one.
		
		if (isset($cache['covers_more']))
			imagecopy($im, $marker, $pt['x'] - $center_x + 3, $pt['y'] - $center_y - 2, 0, 0, $width, $height);
		
		# Put the marker.
		
		imagecopy($im, $marker, $pt['x'] - $center_x, $pt['y'] - $center_y, 0, 0, $width, $height);
		
		# Mark unavailable caches with an X.
		
		if ($zoom >= 10 && ($cache['status'] != "Available"))
		{
			$icon = self::get_image("status_unavailable");
			imagecopy($im, $icon, $pt['x'] - ($center_x - $markercenter_x) - 7,
				$pt['y'] - ($center_y - $markercenter_y) - 8, 0, 0, 16, 16);
		}
		
		# Put the rating smile. :)
		
		if ($zoom >= 13)
		{
			if ($cache['rating'] >= 4.2)
			{
				if (($cache['founds'] > 6) && (($cache['recommendations'] / $cache['founds']) > 0.3)) {
					$icon = self::get_image("grin");
					imagecopy($im, $icon, $pt['x'] - 7 - 6, $pt['y'] - $center_y - 8, 0, 0, 16, 16);
					$icon = self::get_image("rating_star");
					imagecopy($im, $icon, $pt['x'] - 7 + 6, $pt['y'] - $center_y - 8, 0, 0, 16, 16);
				} else {
					$icon = self::get_image("rating_grin");
					imagecopy($im, $icon, $pt['x'] - 7, $pt['y'] - $center_y - 8, 0, 0, 16, 16);
				}
			}
			elseif ($cache['rating'] >= 3.6) {
				$icon = self::get_image("rating_smile");
				imagecopy($im, $icon, $pt['x'] - 7, $pt['y'] - $center_y - 8, 0, 0, 16, 16);
			}
		}
		
		# Mark found caches with V.
		
		if ($zoom >= 10 && $cache['is_found'])
		{
			$icon = self::get_image("found");
			if ($zoom >= 13) {
				imagecopy($im, $icon, $pt['x'] - 2, $pt['y'] - $center_y - 3, 0, 0, 16, 16);
			} else {
				imagecopy($im, $icon, $pt['x'] - ($center_x - $markercenter_x) - 7,
					$pt['y'] - ($center_y - $markercenter_y) - 9, 0, 0, 16, 16);
			}
		}
	}

	private static function latlon_to_tile_xy($nx, $ny, $zoom, $lat, $lon)
	{
		$offset = 256 << ($zoom-1);
		$x = round($offset + ($offset * $lon / 180));
		$y = round($offset - $offset/pi() * log((1 + sin($lat * pi() / 180)) / (1 - sin($lat * pi() / 180))) / 2);
		return array(
			'x' => $x - 256*$nx,
			'y' => $y - 256*$ny,
		);
	}

	private static function xyz_to_latlon_rect($x, $y, $zoom)
	{
		$debug    = false;
		$lon      = -180; // x
		$lonWidth = 360; // width 360
		
		$lat       = -1;
		$latHeight = 2;
		
		$tilesAtThisZoom = 1 << ($zoom);
		$lonWidth        = 360.0 / $tilesAtThisZoom;
		$lon             = -180 + ($x * $lonWidth);
		$latHeight       = 2.0 / $tilesAtThisZoom;
		$lat             = (($tilesAtThisZoom / 2 - $y - 1) * $latHeight);
		
		if ($debug) {
			echo ("(uniform) lat:$lat latHt:$latHeight<br />");
		}
		// convert lat and latHeight to degrees in a transverse mercator projection
		// note that in fact the coordinates go from about -85 to +85 not -90 to 90!
		$latHeight += $lat;
		$latHeight = (2 * atan(exp(PI() * $latHeight))) - (PI() / 2);
		$latHeight *= (180 / PI());
		
		$lat = (2 * atan(exp(PI() * $lat))) - (PI() / 2);
		$lat *= (180 / PI());
		
		
		if ($debug) {
			echo ("pre subtract lat: $lat latHeight $latHeight<br />");
		}
		$latHeight -= $lat;
		if ($debug) {
			echo ("lat: $lat latHeight $latHeight<br />");
		}
		
		if ($lonWidth < 0) {
			$lon      = $lon + $lonWidth;
			$lonWidth = -$lonWidth;
		}
		
		if ($latHeight < 0) {
			$lat       = $lat + $latHeight;
			$latHeight = -$latHeight;
		}
		
		
		$rect         = new Rectangle();
		$rect->x      = $lon;
		$rect->y      = $lat;
		$rect->height = $latHeight;
		$rect->width  = $lonWidth;
		
		return $rect;
	}
}

class Rectangle
{
	var $x, $y;
	var $width, $height;
}
