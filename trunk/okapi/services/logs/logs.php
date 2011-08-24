<?php

namespace okapi\services\logs\logs;

use Exception;
use okapi\Okapi;
use okapi\OkapiRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\OkapiInternalRequest;
use okapi\OkapiServiceRunner;
use okapi\services\caches\search\SearchAssistant;

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
		$cache_code = $request->get_parameter('cache_code');
		if (!$cache_code) throw new ParamMissing('cache_code');
		
		# Check if code exists and retrieve cache ID (this will throw
		# a proper exception on invalid code).
		$cache = OkapiServiceRunner::call('services/caches/geocache', new OkapiInternalRequest(
			$request->consumer, null, array('cache_code' => $cache_code, 'fields' => 'internal_id')));
		
		# Cache exists. Retrieving logs.
			
		$rs = sql("
			select cl.id, cl.uuid, cl.type, unix_timestamp(cl.date) as date, cl.text,
				u.uuid as user_uuid, u.username, u.user_id
			from cache_logs cl, user u
			where
				cl.cache_id = '".mysql_real_escape_string($cache['internal_id'])."'
				and cl.deleted = 0
				and cl.user_id = u.user_id
			order by cl.id desc
		");
		$results = array();
		while ($row = sql_fetch_assoc($rs))
		{
			$results[] = array(
				'uuid' => $row['uuid'],
				'date' => date('c', $row['date']),
				'user' => array(
					'uuid' => $row['user_uuid'],
					'username' => $row['username'],
					'profile_url' => $GLOBALS['absolute_server_URI']."viewprofile.php?userid=".$row['user_id'],
				),
				'type' => Okapi::logtypeid2name($row['type']),
				'comment' => $row['text']
			);
		}
		
		return Okapi::formatted_response($request, $results);
	}
}
