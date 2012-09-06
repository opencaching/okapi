<?php

namespace okapi\services\caches\map\tile;

use Exception;
use okapi\Okapi;
use okapi\Settings;
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

use okapi\services\caches\map\TileTree;


require_once 'tiletree.inc.php';


class WebService
{
	private static $RENDERER_VERSION = 7;  # increment to expire the PNG cache
	private static $MIN_ZOOM_FOR_FOUND_ICON = 10;
	
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
		# Make sure the request is internal.
		
		if (!in_array($request->consumer->key, array('internal', 'facade')))
			throw new BadRequest("Your Consumer Key has not been allowed to access this method.");
		
		# zoom, x, y - required tile-specific parameters.
		
		$zoom = self::require_uint($request, 'z');
		if ($zoom > 21)
			throw new InvalidParam('z', "Maximum value for this parameter is 21.");
		$x = self::require_uint($request, 'x');
		$y = self::require_uint($request, 'y');
		if ($x >= 1<<$zoom)
			throw new InvalidParam('x', "Should be in 0..".((1<<$zoom) - 1).".");
		if ($y >= 1<<$zoom)
			throw new InvalidParam('y', "Should be in 0..".((1<<$zoom) - 1).".");
		
		# While checking the parameters, we will also decide, if the parameters
		# are "common enough" for PNG caching to kick in.
		
		$uncommon_score = 0;  # the lower the better
		
		if ($zoom > 12)
			$uncommon_score += ($zoom - 12);
		
		
		# status
		
		$filter_conds = array();
		$tmp = $request->get_parameter('status');
		if ($tmp == null) $tmp = "Available";
		$allowed_status_codes = array();
		foreach (explode("|", $tmp) as $name)
		{
			try
			{
				$allowed_status_codes[] = Okapi::cache_status_name2id($name);
			}
			catch (Exception $e)
			{
				throw new InvalidParam('status', "'$name' is not a valid cache status.");
			}
		}
		sort($allowed_status_codes);
		if (count($allowed_status_codes) == 0)
			throw new InvalidParam('status');
		if (count($allowed_status_codes) < 3)
			$filter_conds[] = "status in ('".implode("','", array_map('mysql_real_escape_string', $allowed_status_codes))."')";
		if ($allowed_status_codes == array(1) || $allowed_status_codes == array(1,2)) {
			# very common
		} elseif ($allowed_status_codes == array(1,2,3)) {
			$uncommon_score += 2;  # still common
		} else {
			$uncommon_score += 6;  # not so common
		}
		
		# type
		
		if ($tmp = $request->get_parameter('type'))
		{
			$operator = "in";
			if ($tmp[0] == '-')
			{
				$tmp = substr($tmp, 1);
				$operator = "not in";
			}
			$types = array();
			foreach (explode("|", $tmp) as $name)
			{
				try
				{
					$id = Okapi::cache_type_name2id($name);
					$types[] = $id;
				}
				catch (Exception $e)
				{
					throw new InvalidParam('type', "'$name' is not a valid cache type.");
				}
			}
			sort($types);
			
			# Check if all cache types were selected. Since we're running
			# on various OC installations, we don't know which caches types
			# are "all" here. We have to check.
			
			$all = Cache::get('all_cache_types');
			if ($all === null)
			{
				$all = Db::select_column("
					select distinct type
					from caches
					where status in (1,2,3)
				");
				Cache::set('all_cache_types', $all, 86400);
			}
			$ok = true;
			foreach ($all as $type)
				if (!in_array($type, $types))
				{
					$ok = false;
					break;
				}
					
			if ($ok && ($operator == "in"))
			{
				# Do nothing. All cache types will be included. This is common.
			}
			else
			{
				$filter_conds[] = "type $operator ('".implode("','", array_map('mysql_real_escape_string', $types))."')";
				$uncommon_score += 5;
			}
		}
		
		# User-specific geocaches (cached together).
		
		$cache_key = "tileuser/".$request->token->user_id;
		$user = Cache::get($cache_key);
		if ($user === null)
		{
			$user = array();
			
			# Ignored caches.
			
			$rs = Db::query("
				select cache_id
				from cache_ignore
				where user_id = '".mysql_real_escape_string($request->token->user_id)."'
			");
			$user['ignored'] = array();
			while (list($cache_id) = mysql_fetch_row($rs))
				$user['ignored'][$cache_id] = true;
			
			# Found caches.
			
			$rs = Db::query("
				select distinct cache_id
				from cache_logs
				where
					user_id = '".mysql_real_escape_string($request->token->user_id)."'
					and type = 1
					and ".((Settings::get('OC_BRANCH') == 'oc.pl') ? "deleted = 0" : "true")."
			");
			$user['found'] = array();
			while (list($cache_id) = mysql_fetch_row($rs))
				$user['found'][$cache_id] = true;

			# Owned caches.
			
			$rs = Db::query("
				select distinct cache_id
				from caches
				where user_id = '".mysql_real_escape_string($request->token->user_id)."'
			");
			$user['owned'] = array();
			while (list($cache_id) = mysql_fetch_row($rs))
				$user['owned'][$cache_id] = true;
			
			Cache::set($cache_key, $user, 30);
		}

		# exclude_ignored
		
		$tmp = $request->get_parameter('exclude_ignored');
		if ($tmp === null) $tmp = "false";
		if (!in_array($tmp, array('true', 'false'), true))
			throw new InvalidParam('exclude_ignored', "'$tmp'");
		if ($tmp == 'true')
		{
			$excluded_dict = $user['ignored'];
		} else {
			$excluded_dict = array();
		}
		
		# exclude_my_own
		
		if ($tmp = $request->get_parameter('exclude_my_own'))
		{
			if (!in_array($tmp, array('true', 'false'), 1))
				throw new InvalidParam('exclude_my_own', "'$tmp'");
			if (($tmp == 'true') && (count($user['owned']) > 0))
			{
				foreach ($user['owned'] as $cache_id => $v)
					$excluded_dict[$cache_id] = true;
			}
		}
		
		# found_status
		
		if ($tmp = $request->get_parameter('found_status'))
		{
			if (!in_array($tmp, array('found_only', 'notfound_only', 'either')))
				throw new InvalidParam('found_status', "'$tmp'");
			if ($tmp == 'either') {
				# Do nothing.
			} elseif ($tmp == 'notfound_only') {
				# Easy.
				foreach ($user['found'] as $cache_id => $v)
					$excluded_dict[$cache_id] = true;
				$uncommon_score += 1;
			} else {
				# Found only. This will slow down queries somewhat. But it is rare.
				$filter_conds[] = "cache_id in ('".implode("','", array_map('mysql_real_escape_string', array_keys($user['found'])))."')";
				$uncommon_score += 8;
			}
		}
		
		# with_trackables_only
		
		if ($tmp = $request->get_parameter('with_trackables_only'))
		{
			if (!in_array($tmp, array('true', 'false'), 1))
				throw new InvalidParam('with_trackables_only', "'$tmp'");
			if ($tmp == 'true')
			{
				$filter_conds[] = "flags & ".TileTree::$FLAG_HAS_TRACKABLES;
				$uncommon_score += 5;
			}
		}
		
		# not_yet_found_only
		
		if ($tmp = $request->get_parameter('not_yet_found_only'))  # ftf hunter
		{
			if (!in_array($tmp, array('true', 'false'), 1))
				throw new InvalidParam('not_yet_found_only', "'$tmp'");
			if ($tmp == 'true')
			{
				$filter_conds[] = "flags & ".TileTree::$FLAG_NOT_YET_FOUND;
				$uncommon_score += 3;
			}
		}
		
		# rating
		
		if ($tmp = $request->get_parameter('rating'))
		{
			if (!preg_match("/^[1-5]-[1-5](\|X)?$/", $tmp))
				throw new InvalidParam($param_name, "'$tmp'");
			list($min, $max) = explode("-", $tmp);
			if (strpos($max, "|X") !== false)
			{
				$max = $max[0];
				$allow_null = true;
			} else {
				$allow_null = false;
			}
			if ($min > $max)
				throw new InvalidParam($param_name, "'$tmp'");
			if (($min == 1) && ($max == 5) && $allow_null) {
				/* no extra condition necessary */
			} else {
				$filter_conds[] = "(rating between $min and $max)".
					($allow_null ? " or rating is null" : "");
				if ($max < 5)
					$uncommon_score += 6;
				else
					$uncommon_score += 2;
			}
		}
		
		# Get caches within the tile (+ those around the borders). All filtering
		# options need to be applied here. If the user chose very common
		# filter_conds and is zoomed-out (large number of caches), then do
		# a cache-search first.
		
		$rs = TileTree::query_fast($zoom, $x, $y, $filter_conds);
			
		# Filter out caches in $excluded_dict. You may wonder why there was no
		# "cache_id not in (...)" condition. This was done on purpose. In the most
		# common case, only a small fraction of $excluded_dict will be present
		# in that tile. We think it will be faster to filtered them out AFTER
		# the query.

		$rows = array();
		while ($row = mysql_fetch_row($rs))
		{
			if (isset($excluded_dict[$row[0]]))
				continue;
			
			# Also, we will add a special "found" flag, to indicate that this cache
			# needs to be drawn as found.

			if (isset($user['found'][$row[0]]))
			{
				$row[6] |= TileTree::$FLAG_FOUND;  # $row[6] is "flags"
				if ($zoom >= self::$MIN_ZOOM_FOR_FOUND_ICON)
					$uncommon_score += 5;  # Note: we're inside the loop!
			}

			$rows[] = $row;
		}
		
		# If the result is empty, force read from cache.
		
		if (count($rows) == 0)
			$uncommon_score = 0;

		# Draw the image.
		
		$response = new OkapiHttpResponse();
		$response->content_type = "image/png";
		
		# We will hold some most popular PNGs in the cache, ready to be served.
		
		$time_started = microtime(true);
		if ($uncommon_score <= 10)
		{
			$cache_key = "tilepng/".md5(self::$RENDERER_VERSION."/$zoom/$x/$y/".serialize($rows));
			$response->body = Cache::get($cache_key);
		}
		if ($response->body === null)
		{
			$im = imagecreatetruecolor(256, 256);
			imagealphablending($im, false); 
			$transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
			imagefilledrectangle($im, 0, 0, 256, 256, $transparent);
			imagealphablending($im, true);
			foreach ($rows as $row)
				self::draw_cache($im, $zoom, $row);
			# Debug: Uncomment to see the $uncommon_score.
			# imagettftext($im, 25, 0, 80, 140, imagecolorallocatealpha($im, 0, 0, 0, 30), $GLOBALS['rootpath'].'util.sec/bt.ttf', "Score $uncommon_score");
			ob_start();
			imagesavealpha($im, true);
			imagepng($im);
			imagedestroy($im);
			$response->body = ob_get_clean();
			
			if ($uncommon_score <= 3)
				Cache::set($cache_key, $response->body, 86400);
			
			$runtime = microtime(true) - $time_started;
			OkapiServiceRunner::save_stats_extra("tile/drawpng", null, $runtime);
		} else {
			$runtime = microtime(true) - $time_started;
			OkapiServiceRunner::save_stats_extra("tile/drawpng-from-cache", null, $runtime);
		}

		return $response;
	}
	
	private static $images = array();
	private static function get_image($key)
	{
		if (!isset(self::$images[$key]))
			self::$images[$key] = imagecreatefrompng(
				$GLOBALS['rootpath']."okapi/static/tilemap/$key.png");
		return self::$images[$key];
	}
	
	private static function draw_cache($im, $zoom, &$cache_struct)
	{
		list($cache_id, $px, $py, $status, $type, $rating, $flags, $count) = $cache_struct;
		switch ($type) {
			case 2: $key = 'traditional'; break;
			case 3: $key = 'multi'; break;
			case 6: $key = 'event'; break;
			case 7: $key = 'quiz'; break;
			case 4: $key = 'virtual'; break;
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
		
		# Put the marker. If cache covers more caches, then put two markers instead of one.
		
		if ($count > 1)
		{
			imagecopy($im, $marker, $px - $center_x + 3, $py - $center_y - 2, 0, 0, $width, $height);
			imagecopy($im, $marker, $px - $center_x, $py - $center_y, 0, 0, $width, $height);
		}
		else
		{
			# For lower zoom levels, put X only (without the marker).
			
			if ($zoom >= 13 || ($status == 1))
				imagecopy($im, $marker, $px - $center_x, $py - $center_y, 0, 0, $width, $height);
		}

		# If the cache is unavailable, mark it with X. 

		if (($status != 1) && (($count == 1) || ($zoom >= 13)))
		{
			$icon = self::get_image("status_unavailable");
			if ($zoom < 13) {
				imagecopy($im, $icon, $px - ($center_x - $markercenter_x) - 6,
					$py - ($center_y - $markercenter_y) - 8, 0, 0, 16, 16);
			} else {
				imagecopy($im, $icon, $px - 1, $py - $center_y - 4, 0, 0, 16, 16);
			}
		}
		
		# Put the rating smile. :)
		
		if (($status == 1) && ($zoom >= 13))
		{
			if ($rating >= 4.2)
			{
				if ($flags & TileTree::$FLAG_STAR) {
					$icon = self::get_image("rating_grin");
					imagecopy($im, $icon, $px - 7 - 6, $py - $center_y - 8, 0, 0, 16, 16);
					$icon = self::get_image("rating_star");
					imagecopy($im, $icon, $px - 7 + 6, $py - $center_y - 8, 0, 0, 16, 16);
				} else {
					$icon = self::get_image("rating_grin");
					imagecopy($im, $icon, $px - 7, $py - $center_y - 8, 0, 0, 16, 16);
				}
			}
			elseif ($rating >= 3.6) {
				$icon = self::get_image("rating_smile");
				imagecopy($im, $icon, $px - 7, $py - $center_y - 8, 0, 0, 16, 16);
			}
		}
		
		# Mark found caches with V.
		
		if ($zoom >= self::$MIN_ZOOM_FOR_FOUND_ICON && ($flags & TileTree::$FLAG_FOUND))
		{
			$icon = self::get_image("found");
			if ($zoom >= 13) {
				imagecopy($im, $icon, $px - 2, $py - $center_y - 3, 0, 0, 16, 16);
			} else {
				imagecopy($im, $icon, $px - ($center_x - $markercenter_x) - 7,
					$py - ($center_y - $markercenter_y) - 9, 0, 0, 16, 16);
			}
		}
	}
}

