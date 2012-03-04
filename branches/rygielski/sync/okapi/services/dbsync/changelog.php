<?php

namespace okapi\services\dbsync\changelog;

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

class WebService
{
	public static $logged_cache_fields = array('code', 'name', 'names', 'location', 'type',
		'status', 'url', 'owner', 'founds', 'notfounds', 'size', 'difficulty', 'terrain',
		'rating', 'rating_votes', 'recommendations', 'req_passwd', 'description', 'descriptions',
		'hint', 'hints', 'images', 'attrnames', 'trackables_count', 'trackables', 'alt_wpts',
		'last_found', 'last_modified', 'date_created', 'date_hidden');
	
	public static $logged_log_entry_fields = array('uuid', 'cache_code', 'date', 'user',
		'type', 'comment');
	
	public static function options()
	{
		return array(
			'min_auth_level' => 1
		);
	}
	
	public static function call(OkapiRequest $request)
	{
		if (is_array(self::$logged_cache_fields))
			self::$logged_cache_fields = implode("|", self::$logged_cache_fields);
		if (is_array(self::$logged_log_entry_fields))
			self::$logged_log_entry_fields = implode("|", self::$logged_log_entry_fields);
		
		$since = $request->get_parameter('since');
		if ($since === null) throw new ParamMissing('since');
		if ((int)$since != $since) throw new InvalidParam('since');
		
		# First we have to update the changelog. We do this every minute or so.
		
		$last_update = Okapi::get_var('last_clog_update', time() - 86400) + 0;
		if (time() - $last_update > 60)
		{
			$now = Db::select_value("select unix_timestamp(now())");
			$lock = Db::select_value("select get_lock('okapi_changelog_update', 10)");
			if (!$lock)
				throw new Exception("Could not obtain a lock");
			
			$modified_caches = Db::select_column("
				select wp_oc
				from caches
				where last_modified > from_unixtime('".mysql_real_escape_string($last_update)."');
			");
			foreach ($modified_caches as $cache_code)
			{
				try
				{
					$cache = OkapiServiceRunner::call('services/caches/geocache', new OkapiInternalRequest(
						new OkapiInternalConsumer(), null, array('cache_code' => $cache_code,
						'fields' => self::$logged_cache_fields)));
					$entry = array(
						'object_type' => 'geocache',
						'object_key' => array('code' => $cache_code),
						'change_type' => 'replace',
						'data' => $cache,
					);
				}
				catch (DoesNotExist $e)
				{
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
			Db::select_value("select release_lock('okapi_changelog_update')");
		}
		
		# Changelog is up-to-date. Let's check the $since parameter.
		
		$first_id = Db::select_value("
			select id from okapi_clog where id > '".mysql_real_escape_string($since)."' limit 1
		");
		if (($first_id !== null) && ($first_id > $since + 1))
			throw new BadRequest("This request has expired - the 'since' parameter is too old. You must sync your database more frequently.");
		
		# Select and format the results.
		
		$chunk_size = 100;
		$rs = Db::query("
			select id, data
			from okapi_clog
			where id > '".mysql_real_escape_string($since)."'
			order by id
			limit ".($chunk_size + 1)."
		");
		$changelog = array();
		$revision = $since;
		$counter = 0;
		$more = false;
		while ($row = mysql_fetch_assoc($rs))
		{
			$revision = $row['id'];
			$changelog[] = unserialize(gzinflate($row['data']));
			$counter++;
			if ($counter == $chunk_size)
			{
				# skip the last element, this means that there are more elemenents than $chunk_size
				$more = true;
				break;
			}
		}
		$result = array(
			'changelog' => &$changelog,
			'revision' => $revision,
			'more' => $more,
		);
		
		return Okapi::formatted_response($request, $result);
	}
}
