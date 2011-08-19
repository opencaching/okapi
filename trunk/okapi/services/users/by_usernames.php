<?php

namespace okapi\services\users\by_usernames;

use okapi\OkapiInternalRequest;

use okapi\OkapiServiceRunner;

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

	public static function call(OkapiRequest $request)
	{
		$usernames = $request->get_parameter('usernames');
		if (!$usernames) throw new ParamMissing('usernames');
		$usernames = explode("|", $usernames);
		if (count($usernames) > 500)
			throw new InvalidParam('usernames', "Maximum allowed number of referenced users ".
				"is 500. You provided ".count($usernames)." user IDs.");
		$fields = $request->get_parameter('fields');
		if (!$fields)
			throw new ParamMissing('fields');
		
		# There's no need to validate the fields parameter as the 'users'
		# method does this (it will raise a proper exception on invalid values).
		
		$rs = sql("
			select username, user_id
			from user
			where username in ('".implode("','", array_map('mysql_real_escape_string', $usernames))."')
		");
		$username2userid = array();
		while ($row = sql_fetch_assoc($rs))
		{
			$username2userid[$row['username']] = $row['user_id'];
		}
		mysql_free_result($rs);
		
		# Retrieve data on given user_ids.
		$id_results = OkapiServiceRunner::call('services/users/users', new OkapiInternalRequest(
			$request->consumer, $request->token, array('user_ids' => implode("|", array_values($username2userid)),
			'fields' => $fields)));
		
		# Map user_ids to usernames. Also check which usernames were not found
		# and mark them with null.
		$results = array();
		foreach ($usernames as $username)
		{
			if (!isset($username2userid[$username]))
				$results[$username] = null;
			else
				$results[$username] = $id_results[$username2userid[$username]];
		}
		
		return Okapi::formatted_response($request, $results);
	}
}
