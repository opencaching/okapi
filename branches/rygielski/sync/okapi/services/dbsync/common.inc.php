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
	public static function update_clog_table()
	{
		$now = Db::select_value("select unix_timestamp(now())");
		
		# Skip the update, if it was already done during the last 60 seconds
		# OR if it is BEING done right now.
		
		$last_update = Okapi::get_var('last_clog_update', $now - 86400) + 0;
		if ($now - $last_update < 60)
			return;
		
		$lock = Db::select_value("select get_lock('okapi_changelog_update', 0)");
		if (!$lock)
			return;
		
		# Usually this will be fast. But, for example, if admin changes ALL the
		# caches, this will take forever. But we still want it to finish properly
		# without interruption.
		
		set_time_limit(0);
		ignore_user_abort(true); 
		
		require_once $GLOBALS['rootpath'].'okapi/service_runner.php';
		
		# Get the list of modified cache codes. Split it into groups of N cache codes.
		
		$cache_codes = Db::select_column("
			select wp_oc
			from caches
			where last_modified > from_unixtime('".mysql_real_escape_string($last_update)."');
		");
		$cache_code_groups = Okapi::make_groups($cache_codes, 50);
		unset($cache_codes);
		
		foreach ($cache_code_groups as $cache_codes)
		{
			# For each group, get the cached values of geocache-dictionaries from OKAPI cache.
			# These will be used as a base to compare the changes made to geocache objects.
			
			$cache_keys = array();
			foreach ($cache_codes as $cache_code)
				$cache_keys[] = 'clog_cache#'.$cache_code;
			$cached_values = Cache::get_many($cache_keys);
			Cache::delete_many($cache_keys);
			unset($cache_keys);
			
			# Get the current values for geocache-dictionaries. Compare them with the previous
			# ones and create changelog entries.
			
			$current_values = OkapiServiceRunner::call('services/caches/geocaches', new OkapiInternalRequest(
				new OkapiInternalConsumer(), null, array('cache_codes' => implode("|", $cache_codes),
				'fields' => self::$logged_cache_fields)));
			$entries = array();
			foreach ($current_values as $cache_code => $geocache)
			{
				if ($geocache !== null)
				{
					$entries[] = array(
						'object_type' => 'geocache',
						'object_key' => array('code' => $cache_code),
						'change_type' => 'replace',
						'data' => self::get_diff($cached_values['clog_cache#'.$cache_code], $geocache),
					);
					$cached_values['clog_cache#'.$cache_code] = $geocache;
				}
				else
				{
					$entries[] = array(
						'object_type' => 'geocache',
						'object_key' => array('code' => $cache_code),
						'change_type' => 'delete',
					);
					$cached_values['clog_cache#'.$cache_code] = null;
				}
			}
			
			# Save the changelog entries into the clog table.
			
			$data_values = array();
			foreach ($entries as $entry)
				$data_values[] = gzdeflate(serialize($entry));
			Db::execute("
				insert into okapi_clog (data)
				values ('".implode("'),('", array_map('mysql_real_escape_string', $data_values))."');
			");
			
			# Update the values kept in OKAPI cache.
			
			Cache::set_many($cached_values, 30 * 86400);
		}
		unset($current_values);
		unset($cached_values);
		unset($data_values);
		unset($entries);
		
		$log_uuids = Db::select_column("
			select uuid
			from cache_logs
			where last_modified > from_unixtime('".mysql_real_escape_string($last_update)."');
		");
		$log_uuid_groups = Okapi::make_groups($log_uuids, 100);
		unset($log_uuids);
		
		foreach ($log_uuid_groups as $log_uuids)
		{
			# Unlike geocaches, we don't keep cached copies of log entries. These do not
			# change much, and do not keep much space anyway.
			
			$logs = OkapiServiceRunner::call('services/logs/entries', new OkapiInternalRequest(
				new OkapiInternalConsumer(), null, array('log_uuids' => implode("|", $log_uuids),
				'fields' => self::$logged_log_entry_fields)));
			$entries = array();
			foreach ($logs as $log_uuid => $log)
			{
				if ($log !== null)
				{
					$entries[] = array(
						'object_type' => 'log_entry',
						'object_key' => array('uuid' => $log_uuid),
						'change_type' => 'replace',
						'data' => $log,
					);
				}
				else
				{
					$entries[] = array(
						'object_type' => 'geocache',
						'object_key' => array('uuid' => $log_uuid),
						'change_type' => 'delete',
					);
				}
			}

			# Save the changelog entries into the clog table.
			
			$data_values = array();
			foreach ($entries as $entry)
				$data_values[] = gzdeflate(serialize($entry));
			Db::execute("
				insert into okapi_clog (data)
				values ('".implode("'),('", array_map('mysql_real_escape_string', $data_values))."');
			");
		}
		
		# Update state variables and release DB lock.
		
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
