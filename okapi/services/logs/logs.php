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
		$cache_wpt = $request->get_parameter('cache_wpt');
		if (!$cache_wpt) throw new ParamMissing('cache_wpt');
		
		# Check if wpt exists and retrieve cache ID (this will throw
		# a proper exception on invalid wpt).
		$cache = OkapiServiceRunner::call('services/caches/geocache', new OkapiInternalRequest(
			$request->consumer, null, array('cache_wpt' => $cache_wpt, 'fields' => 'id')));
		
		# Cache exists. Retrieving logs.
			
		$rs = sql("
			select cl.id, cl.type, unix_timestamp(cl.date) as date, cl.text,
				u.user_id, u.username
			from cache_logs cl, user u
			where
				cl.cache_id = '".mysql_real_escape_string($cache['id'])."'
				and cl.deleted = 0
				and cl.user_id = u.user_id
			order by cl.id desc
		");
		$results = array();
		while ($row = sql_fetch_assoc($rs))
		{
			$results[] = array(
				'id' => $row['id'],
				'date' => date('c', $row['date']),
				'user' => array('user_id' => $row['user_id'], 'username' => $row['username']),
				'type' => Okapi::logtypeid2name($row['type']),
				'comment' => $row['text']
			);
		}
		
		return Okapi::formatted_response($request, $results);
	}
}
