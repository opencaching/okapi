<?php

namespace okapi\services\logs\submit;

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
			'min_auth_level' => 3
		);
	}
	public static function call(OkapiRequest $request)
	{
		$cache_code = $request->get_parameter('cache_code');
		if (!$cache_code) throw new ParamMissing('cache_code');
		$logtype = $request->get_parameter('logtype');
		if (!$logtype) throw new ParamMissing('logtype');
		if (!in_array($logtype, array('found', 'not_found', 'comment')))
			throw new InvalidParam('logtype', "'$logtype' in not a valid logtype code.");
		$logtype_id = Okapi::logtypename2id($logtype);
		if (!$comment) throw new ParamMissing('comment');
		
		# Check if code exists and retrieve cache ID (this will throw
		# a proper exception on invalid code).
		
		$cache = OkapiServiceRunner::call('services/caches/geocache', new OkapiInternalRequest(
			$request->consumer, null, array('cache_code' => $cache_code, 'fields' => 'id')));
		
		# Add a log entry.
		
		sql("
			insert into cache_logs (cache_id, user_id, type, date, text)
			values (
				'".mysql_real_escape_string($cache['id'])."',
				'".mysql_real_escape_string($request->token->user_id)."',
				'".mysql_real_escape_string($logtype_id)."',
				now(),
				'".mysql_real_escape_string($comment)."'
			);
		");
		$result = array(
			'success' => true,
			'message' => "You're cache log entry was posted successfully.",
			'log_id' => sql_insert_id(),
		);
		return Okapi::formatted_response($request, $result);
	}
}
