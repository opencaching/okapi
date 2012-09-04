<?php

namespace okapi\services\caches\map;

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
use okapi\OkapiLock;

use okapi\services\caches\map\TileTree;


require_once 'tiletree.inc.php';


class ReplicateListener
{
	public static function receive($changelog)
	{
		# This will be called every time new items arrive from replicate module's
		# changelog. The format of $changelog is described in the replicate module
		# (NOT the entire response, just the "changelog" key).
		
		$lock = OkapiLock::get("tile-computation-0-0-0");  # lock access to zoom 0
		$lock->acquire();
		foreach ($changelog as $c)
		{
			if ($c['object_type'] == 'geocache')
			{
				if ($c['change_type'] == 'replace')
					self::handle_geocache_replace($c);
				else
					self::handle_geocache_delete($c);
			}
		}
		$lock->release();
	}
	
	public static function reset()
	{
		# This will be called when there are "too many" entries in the changelog
		# and the replicate module thinks it better to just reset the entire TileTree.
		
		$lock = OkapiLock::get("tile-computation-0-0-0");  # lock access to zoom 0
		$lock->acquire();
		Db::execute("delete from okapi_tile_status");
		Db::execute("delete from okapi_tile_caches");
		$lock->release();
	}
	
	private static function handle_geocache_replace($c)
	{
		# Check if any relevant geocache attributes have changed.
		# We will pick up "our" copy of the cache from zero-zoom level.
		
		try {
			$cache = OkapiServiceRunner::call("services/caches/geocache", new OkapiInternalRequest(new OkapiInternalConsumer(), null, array(
				'cache_code' => $c['object_key']['code'],
				'fields' => 'internal_id|code|name|location|type|status|rating|recommendations|founds|trackables_count'
			)));
		} catch (InvalidParam $e) {
			# Unprobable, but possible. Ignore changelog entry.
			return;
		}
		
		$theirs = TileTree::generate_short_row($cache);
		$ours = mysql_fetch_row(Db::query("
			select cache_id, z21x, z21y, status, type, rating, flags
			from okapi_tile_caches
			where
				z=0
				and cache_id = '".mysql_real_escape_string($cache['internal_id'])."'
		"));
		if (!$ours)
		{
			# Aaah, a new geocache! How nice... ;)
			
			$mode = 'force_refresh';
		}
		elseif (($ours[1] != $theirs[1]) || ($ours[2] != $theirs[2]))  # z21x & z21y fields
		{
			# Location changed.
			
			$mode = 'force_refresh';
		}
		else
		{
			$mode = 'update_references';
		}
		
		if ($mode == 'force_refresh')
		{
			# Update cache at zero level. Remove all other levels (force refresh).
			
			Db::execute("
				replace into okapi_tile_caches (
					z, x, y, cache_id, z21x, z21y, status, type, rating, flags
				) values (
					0, 0, 0,
					'".mysql_real_escape_string($theirs[0])."',
					'".mysql_real_escape_string($theirs[1])."',
					'".mysql_real_escape_string($theirs[2])."',
					'".mysql_real_escape_string($theirs[3])."',
					'".mysql_real_escape_string($theirs[4])."',
					".(($theirs[5] === null) ? "null" : $theirs[5]).",
					'".mysql_real_escape_string($theirs[6])."'
				);
			");
			Db::execute("delete from okapi_tile_status where z > 0");
			Db::execute("delete from okapi_tile_caches where z > 0");
		}
		elseif ($mode == 'update_references')
		{
			if ($ours == $theirs)
			{
				# No need to update anything. This is quite common (i.e. when the
				# cache was simply found, not changed).
			}
			else
			{
				# Update all references (for all levels).
				Db::execute("
					update okapi_tile_caches
					set
						status = '".mysql_real_escape_string($theirs[3])."',
						type = '".mysql_real_escape_string($theirs[4])."',
						rating = ".(($theirs[5] === null) ? "null" : $theirs[5]).",
						flags = '".mysql_real_escape_string($theirs[6])."'
					where
						cache_id = '".mysql_real_escape_string($theirs[0])."'
				");
			}
		}
		else throw new Exception();  # No such case.
	}
	
	private static function handle_geocache_delete($c)
	{
		# Delete cache at zero level. Remove all other levels (force refresh).
		
		$cache_id = Db::select_value("
			select cache_id
			from caches
			where wp_oc='".mysql_real_escape_string($c['object_key']['code'])."'
		");
		Db::execute("
			delete from okapi_tile_caches
			where
				z = 0
				and cache_id = '".mysql_real_escape_string($cache_id)."';
		");
		Db::execute("delete from okapi_tile_status where z > 0");
		Db::execute("delete from okapi_tile_caches where z > 0");
	}
}