<?php

namespace okapi\services\logs\userlogs;

use Exception;
use okapi\Okapi;
use okapi\Db;
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
		$user_uuid = $request->get_parameter('user_uuid');
		if (!$user_uuid) throw new ParamMissing('user_uuid');
		
		# Check if user exists and retrieve user's ID (this will throw
		# a proper exception on invalid UUID).
		$user = OkapiServiceRunner::call('services/users/user', new OkapiInternalRequest(
			$request->consumer, null, array('user_uuid' => $user_uuid, 'fields' => 'internal_id')));
		
		# User exists. Retrieving logs.
			
		$rs = Db::query("
			select cl.id, cl.uuid, cl.type, unix_timestamp(cl.date) as date, cl.text,
				c.wp_oc as cache_code
			from cache_logs cl, caches c
			where
				cl.user_id = '".mysql_real_escape_string($user['internal_id'])."'
				and cl.deleted = 0
				and cl.cache_id = c.cache_id
			order by cl.date desc
		");
		$results = array();
		while ($row = mysql_fetch_assoc($rs))
		{
			$results[] = array(
				'uuid' => $row['uuid'],
				'date' => date('c', $row['date']),
				'cache_code' => $row['cache_code'],
				'type' => Okapi::logtypeid2name($row['type']),
				'comment' => $row['text']
			);
		}
		
		return Okapi::formatted_response($request, $results);
	}
}
