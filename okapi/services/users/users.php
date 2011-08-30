<?php

namespace okapi\services\users\users;

use Exception;
use okapi\Okapi;
use okapi\OkapiRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\services\caches\search\SearchAssistant;

class WebService
{
	public static function options()
	{
		return array(
			'min_auth_level' => 1
		);
	}
	
	public static $valid_field_names = array('uuid', 'username', 'profile_url', 'internal_id', 'is_admin');
	
	public static function call(OkapiRequest $request)
	{
		$user_uuids = $request->get_parameter('user_uuids');
		if (!$user_uuids) throw new ParamMissing('user_uuids');
		$user_uuids = explode("|", $user_uuids);
		if (count($user_uuids) > 500)
			throw new InvalidParam('user_uuids', "Maximum allowed number of referenced users ".
				"is 500. You provided ".count($user_uuids)." user IDs.");
		$fields = $request->get_parameter('fields');
		if (!$fields)
			throw new ParamMissing('fields');
		$fields = explode("|", $fields);
		foreach ($fields as $field)
			if (!in_array($field, self::$valid_field_names))
				throw new InvalidParam('fields', "'$field' is not a valid field code.");
		$rs = sql("
			select user_id, uuid, username, admin
			from user
			where uuid in ('".implode("','", array_map('mysql_real_escape_string', $user_uuids))."')
		");
		$results = array();
		while ($row = sql_fetch_assoc($rs))
		{
			$entry = array();
			foreach ($fields as $field)
			{
				switch ($field)
				{
					case 'uuid': $entry['uuid'] = $row['uuid']; break;
					case 'username': $entry['username'] = $row['username']; break;
					case 'profile_url': $entry['profile_url'] = $GLOBALS['absolute_server_URI']."viewprofile.php?userid=".$row['user_id']; break;
					case 'is_admin':
						if (!$request->token) $entry['is_admin'] = null;
						if ($request->token->user_id != $row['user_id']) $entry['is_admin'] = null;
						$entry['is_admin'] = $row['admin'] ? true : false;
						break;
					case 'internal_id': $entry['internal_id'] = $row['user_id']; break;
					default: throw new Exception("Missing field case: ".$field);
				}
			}
			$results[$row['uuid']] = $entry;
		}
		mysql_free_result($rs);
		
		# Check which user IDs were not found and mark them with null.
		foreach ($user_uuids as $user_uuid)
			if (!isset($results[$user_uuid]))
				$results[$user_uuid] = null;
		
		return Okapi::formatted_response($request, $results);
	}
}
