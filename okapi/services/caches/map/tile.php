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
		if (count($allowed_status_codes) == 0)
			throw new InvalidParam('status');
		if (count($allowed_status_codes) < 3)
			$filter_conds[] = "status in ('".implode("','", array_map('mysql_real_escape_string', $allowed_status_codes))."')";
		
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
			$filter_conds[] = "type $operator ('".implode("','", array_map('mysql_real_escape_string', $types))."')";
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
			} else {
				# Found only. This will slow down queries somewhat. But it is rare.
				$filter_conds[] = "cache_id in ('".implode("','", array_map('mysql_real_escape_string', array_keys($user['found'])))."')";
			}
		}
		
		# with_trackables_only
		
		if ($tmp = $request->get_parameter('with_trackables_only'))
		{
			if (!in_array($tmp, array('true', 'false'), 1))
				throw new InvalidParam('with_trackables_only', "'$tmp'");
			$filter_conds[] = "flags & ".TileTree::$FLAG_HAS_TRACKABLES;
		}
		
		# not_yet_found_only
		
		if ($tmp = $request->get_parameter('not_yet_found_only'))
		{
			if (!in_array($tmp, array('true', 'false'), 1))
				throw new InvalidParam('not_yet_found_only', "'$tmp'");
			$filter_conds[] = "flags & ".TileTree::$FLAG_NOT_YET_FOUND;
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
			}
		}
		
		# Get caches within the tile (+ those around the borders). All filtering
		# options need to be applied here.
		
		$rs = TileTree::query_fast($zoom, $x, $y, $filter_conds);
		
		# Draw the image. WRTODO: Selective PNG cache.
		
		$response = new OkapiHttpResponse();
		$response->content_type = "image/png";
		$im = imagecreatetruecolor(256, 256);
		imagealphablending($im, false); 
		$transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
		imagefilledrectangle($im, 0, 0, 256, 256, $transparent);
		imagealphablending($im, true);
		while ($row = mysql_fetch_row($rs))
			self::draw_cache($im, $zoom, $row, $user['found'], $excluded_dict);
		ob_start();
		imagesavealpha($im, true);
		imagepng($im);
		imagedestroy($im);
		$response->body = ob_get_clean();

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
	
	private static function draw_cache($im, $zoom, &$cache_struct, &$found_cache_dict, &$excluded_dict)
	{
		list($cache_id, $px, $py, $status, $type, $rating, $flags, $count) = $cache_struct;
		if (isset($excluded_dict[$cache_id]))
			return;
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
		
		if ($zoom >= 10 && (isset($found_cache_dict[$cache_id])))
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

