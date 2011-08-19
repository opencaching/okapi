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
			'consumer'   => 'required',
			'token'      => 'ignored',
		);
	}
	
	public static $valid_field_names = array('id', 'username', 'profile_url');
	
	public static function call(OkapiRequest $request)
	{
		$user_ids = $request->get_parameter('user_ids');
		if (!$user_ids) throw new ParamMissing('user_ids');
		$user_ids = explode("|", $user_ids);
		if (count($user_ids) > 500)
			throw new InvalidParam('user_ids', "Maximum allowed number of referenced users ".
				"is 500. You provided ".count($user_ids)." user IDs.");
		$fields = $request->get_parameter('fields');
		if (!$fields)
			throw new ParamMissing('fields');
		$fields = explode("|", $fields);
		foreach ($fields as $field)
			if (!in_array($field, self::$valid_field_names))
				throw new InvalidParam('fields', "'$field' is not a valid field code.");
		$rs = sql("
			select user_id, username
			from user
			where user_id in ('".implode("','", array_map('mysql_real_escape_string', $user_ids))."')
		");
		$results = array();
		while ($row = sql_fetch_assoc($rs))
		{
			$entry = array();
			foreach ($fields as $field)
			{
				switch ($field)
				{
					case 'id': $entry['id'] = $row['user_id']; break;
					case 'username': $entry['username'] = $row['username']; break;
					case 'profile_url': $entry['profile_url'] = $GLOBALS['absolute_server_URI']."viewprofile.php?userid=".$row['user_id']; break;
					default: throw new Exception("Missing field case: ".$field);
				}
			}
			$results[$row['user_id']] = &$entry;
		}
		mysql_free_result($rs);
		
		# Check which user IDs were not found and mark them with null.
		foreach ($user_ids as $user_id)
			if (!isset($results[$user_id]))
				$results[$user_id] = null;
		
		return Okapi::formatted_response($request, $results);
	}
}
