<?php

namespace okapi\services\dbsync\common;

use Exception;
use okapi\Okapi;
use okapi\Db;
use okapi\OkapiRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\BadRequest;
use okapi\DoesNotExist;
use okapi\OkapiInternalRequest;
use okapi\OkapiInternalConsumer;
use okapi\OkapiServiceRunner;
use okapi\Cache;

class SyncCommon
{
	private static $chunk_size = 100;
	private static $logged_cache_fields = 'code|name|names|location|type|status|url|owner|founds|notfounds|size|difficulty|terrain|rating|rating_votes|recommendations|req_passwd|description|descriptions|hint|hints|images|attrnames|trackables_count|trackables|alt_wpts|last_found|last_modified|date_created|date_hidden';
	
	private static $logged_log_entry_fields = 'uuid|cache_code|date|user|type|comment';
	
	/** Return current (maximum) changelog revision number. */
	public static function get_revision()
	{
		return Okapi::get_var('clog_revision', 0);
	}
	
	/**
	 * Compare two dictionaries. Return the $new dictionary with all unchanged
	 * keys removed. Only the changed ones will remain.
	 */
	private static function get_diff($old, $new)
	{
		if ($old === null)
			return $new;
		$changed_keys = array();
		foreach ($new as $key => $value)
		{
			if (!array_key_exists($key, $old))
				$changed_keys[] = $key;
			elseif ($old[$key] != $new[$key])
				$changed_keys[] = $key;
		}
		$changed = array();
		foreach ($changed_keys as $key)
			$changed[$key] = $new[$key];
		return $changed;
	}
	
	/** Check for modifications in the database and update the changelog table accordingly. */
	public static function update_clog_table($last_update)
	{
		$lock = Db::select_value("select get_lock('okapi_changelog_update', 10)");
		if (!$lock)
			throw new Exception("Could not obtain a lock");

		$now = Db::select_value("select unix_timestamp(now())");
		
		$modified_caches = Db::select_column("
			select wp_oc
			from caches
			where last_modified > from_unixtime('".mysql_real_escape_string($last_update)."');
		");
		foreach ($modified_caches as $cache_code)
		{
			$cache_key = 'clog_cache#'.$cache_code;
			try
			{
				$cache = OkapiServiceRunner::call('services/caches/geocache', new OkapiInternalRequest(
					new OkapiInternalConsumer(), null, array('cache_code' => $cache_code,
					'fields' => self::$logged_cache_fields)));
				$entry = array(
					'object_type' => 'geocache',
					'object_key' => array('code' => $cache_code),
					'change_type' => 'replace',
					'data' => self::get_diff(Cache::get($cache_key), $cache),
				);
			}
			catch (DoesNotExist $e)
			{
				$cache = null;
				$entry = array(
					'object_type' => 'geocache',
					'object_key' => array('code' => $cache_code),
					'change_type' => 'delete',
				);
			}
			Db::execute("
				insert into okapi_clog (data)
				values ('".mysql_real_escape_string(gzdeflate(serialize($entry)))."');
			");
			if ($cache)
				Cache::set($cache_key, $cache, 30 * 86400);
			else
				Cache::delete($cache_key);
		}
		$modified_log_entries = Db::select_column("
			select uuid
			from cache_logs
			where last_modified > from_unixtime('".mysql_real_escape_string($last_update)."');
		");
		foreach ($modified_log_entries as $log_uuid)
		{
			try
			{
				$log_entry = OkapiServiceRunner::call('services/logs/entry', new OkapiInternalRequest(
					new OkapiInternalConsumer(), null, array('log_uuid' => $log_uuid,
					'fields' => self::$logged_log_entry_fields)));
				$entry = array(
					'object_type' => 'log_entry',
					'object_key' => array('uuid' => $log_uuid),
					'change_type' => 'replace',
					'data' => $log_entry,
				);
			}
			catch (DoesNotExist $e)
			{
				$entry = array(
					'object_type' => 'geocache',
					'object_key' => array('uuid' => $log_uuid),
					'change_type' => 'delete',
				);
			}
			Db::execute("
				insert into okapi_clog (data)
				values ('".mysql_real_escape_string(gzdeflate(serialize($entry)))."');
			");
		}
		Okapi::set_var("last_clog_update", $now);
		$revision = Db::select_value("select max(id) from okapi_clog");
		Okapi::set_var("clog_revision", $revision);
		Db::select_value("select release_lock('okapi_changelog_update')");
	}
	
	/**
	 * Check if the 'since' parameter is up-do-date. If it is not, then it means
	 * that the user waited too long and he has to download the fulldump again.
	 */
	public static function check_since_param($since)
	{
		$first_id = Db::select_value("
			select id from okapi_clog where id > '".mysql_real_escape_string($since)."' limit 1
		");
		if ($first_id === null)
			return true; # okay, since points to the newest revision 
		if ($first_id == $since + 1)
			return true; # okay, revision $since + 1 is present
		
		# If we're here, then this means that $first_id > $since + 1.
		# Revision $since + 1 is already deleted, $since must be too old!
		
		return false;
	}
	
	/**
	 * Select best chunk for a given $since parameter. This function will try to select
	 * one chunk for different values of $since parameter, this is done in order to
	 * allow more optimal caching. Returns: list($from, $to). NOTICE: If $since is
	 * at the newest revision, then this will return list($since + 1, $since) - an
	 * empty chunk.
	 */
	public static function select_best_chunk($since)
	{
		$current_revision = self::get_revision();
		$last_chunk_cut = $current_revision - ($current_revision % self::$chunk_size);
		if ($since >= $last_chunk_cut)
		{
			# If, for example, we have a choice to give user 50 items he wants, or 80 items
			# which we probably already have in cache (and this includes the 50 which the
			# user wants), then we'll give him 80. If user wants less than half of what we
			# have (ex. 30), then we'll give him only his 30.
			
			if ($current_revision - $since > $since - $last_chunk_cut)
				return array($last_chunk_cut + 1, $current_revision);
			else
				return array($since + 1, $current_revision);
		}
		$prev_chunk_cut = $since - ($since % self::$chunk_size);
		return array($prev_chunk_cut + 1, $prev_chunk_cut + self::$chunk_size);
	}
	
	/**
	 * Return changelog chunk, starting at $from, ending as $to.
	 */
	public static function get_chunk($from, $to)
	{
		if ($to < $from)
			return array();
		if ($to - $from > self::$chunk_size)
			throw new Exception("You should not get chunksize bigger than ".self::$chunk_size." entries at one time.");
		
		# Check if we already have this chunk in cache.
		
		$cache_key = 'clog_chunk#'.$from.'-'.$to;
		$chunk = Cache::get($cache_key);
		if ($chunk === null)
		{
			$rs = Db::query("
				select id, data
				from okapi_clog
				where id between '".mysql_real_escape_string($from)."' and '".mysql_real_escape_string($to)."'
				order by id
			");
			$chunk = array();
			while ($row = mysql_fetch_assoc($rs))
				$chunk[] = unserialize(gzinflate($row['data']));
			
			# Cache timeout depends on the chunk starting and ending point. Chunks
			# which start and end on the boundries of chunk_size should be cached
			# longer (they can be accessed even after 10 days). Other chunks won't
			# be ever accessed after the next revision appears, so there is not point
			# in storing them that long.
			
			if (($from % self::$chunk_size === 0) && ($to % self::$chunk_size === 0))
				$timeout = 10 * 86400;
			else
				$timeout = 86400;
			Cache::set($cache_key, $chunk, $timeout);
		}
		
		return $chunk;
	}
}
