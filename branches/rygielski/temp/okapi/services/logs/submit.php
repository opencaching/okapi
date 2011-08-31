<?php

namespace okapi\services\logs\submit;

use Exception;
use okapi\Okapi;
use okapi\Db;
use okapi\OkapiRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\OkapiInternalRequest;
use okapi\OkapiServiceRunner;
use okapi\services\caches\search\SearchAssistant;
use okapi\BadRequest;


/** 
 * This exception is thrown by WebService::_call method, when there was an user-error
 * publishing the log entry. It is VERY different from other BadRequest exceptions:
 * It does not imply that the Consumer did anything wrong.
 */
class CannotPublishException extends Exception {}

class WebService
{
	public static function options()
	{
		return array(
			'min_auth_level' => 3
		);
	} 
	
	/** 
	 * Publish a new log entry and return log entry uuid. Throws
	 * CannotPublishException or BadRequest on errors.
	 */
	private static function _call(OkapiRequest $request)
	{
		# Developers! Please notice the fundamental difference between throwing
		# CannotPublishException and standard BadRequest/InvalidParam exceptions!
		
		$cache_code = $request->get_parameter('cache_code');
		if (!$cache_code) throw new ParamMissing('cache_code');
		$logtype = $request->get_parameter('logtype');
		if (!$logtype) throw new ParamMissing('logtype');
		if (!in_array($logtype, array('Found it', "Didn't find it", 'Comment')))
			throw new InvalidParam('logtype', "'$logtype' in not a valid logtype code.");
		$logtype_id = Okapi::logtypename2id($logtype);
		$comment = $request->get_parameter('comment');
		if (!$comment) $comment = "";
		$tmp = $request->get_parameter('when');
		if ($tmp)
		{
			$when = strtotime($tmp);
			if (!$when)
				throw new InvalidParam('when', "'$tmp' is not in a valid format or is not a valid date.");
			if ($when > time())
				throw new CannotPublishException("You are trying to publish a log entry with a date in future. ".
					"Cache log entries are allowed to be published in the past, but NOT in the future.");
		}
		else
			$when = time();
		$rating = $request->get_parameter('rating');
		if ($rating !== null && (!in_array($rating, array(1,2,3,4,5))))
			throw new InvalidParam('rating', "If present, it must be an integer between 1 and 5.");
		
		# Check if cache exists and retrieve cache internal ID (this will throw
		# a proper exception on invalid cache_code). Also, get the user object.
		
		$cache = OkapiServiceRunner::call('services/caches/geocache', new OkapiInternalRequest(
			$request->consumer, null, array('cache_code' => $cache_code,
			'fields' => 'internal_id|status|owner|type')));
		$user = OkapiServiceRunner::call('services/users/by_internal_id', new OkapiInternalRequest(
			$request->consumer, $request->token, array('internal_id' => $request->token->user_id,
			'fields' => 'is_admin|uuid|internal_id')));
		
		# Various integrity checks.
		
		if (!in_array($cache['status'], array("Available", "Temporarily unavailable")))
		{
			# Only admins and cache owners may publish comments for Archived caches.
			if ($user['is_admin'] || ($user['uuid'] == $cache['owner']['uuid'])) {
				/* pass */
			} else {
				throw new CannotPublishException("This cache is archived. Only admins and the owner are allowed to add a log entry.");
			}
		}
		if ($cache['type'] == 'Event' && $logtype != 'Comment')
			throw new CannotPublishException('This cache is an Event cache. You cannot "Find it"! (But - you may "Comment" on it.)');
		if ($rating && $logtype != 'Found it')
			throw new BadRequest("Rating is allowed only for 'Found it' logtypes.");
		if ($logtype == 'Found it')
		{
			$has_already_found_it = Db::select_value("
				select 1
				from cache_logs
				where
					user_id = '".mysql_real_escape_string($user['internal_id'])."'
					and cache_id = '".mysql_real_escape_string($cache['internal_id'])."'
					and type = '".mysql_real_escape_string(Okapi::logtypename2id("Found it"))."'
					and deleted = 0
			");
			if ($has_already_found_it)
				throw new CannotPublishException("You have already submitted a \"Found it\" log entry once. Now you may submit \"Comments\" only!");
		}
		if ($rating)
		{
			$has_already_rated = Db::select_value("
				select 1
				from scores
				where
					user_id = '".mysql_real_escape_string($user['internal_id'])."'
					and cache_id = '".mysql_real_escape_string($cache['internal_id'])."'
			");
			if ($has_already_rated)
				throw new CannotPublishException("You have already rated this cache once. Your rating cannot be changed.");
		}
		if ($logtype == 'Comment' && strlen(trim($comment)) == 0)
			throw new CannotPublishException("Your have to supply some text for your comment.");
			
		# Add the log entry.
		
		$log_uuid = create_uuid();
		# Can't use "sql" here because it fails. WRTODO: get rid od "sql" and "sqlValue".
		Db::execute("
			insert into cache_logs (uuid, cache_id, user_id, type, date, date_created, text, node)
			values (
				'".mysql_real_escape_string($log_uuid)."',
				'".mysql_real_escape_string($cache['internal_id'])."',
				'".mysql_real_escape_string($request->token->user_id)."',
				'".mysql_real_escape_string($logtype_id)."',
				from_unixtime('".mysql_real_escape_string($when)."'),
				now(),
				'".mysql_real_escape_string(htmlspecialchars($comment, ENT_QUOTES))."',
				'".mysql_real_escape_string($GLOBALS['oc_nodeid'])."'
			);
		");
		
		# WRTODO: Add rating.
		
		# Update cache stats.
		
		if ($logtype == 'Found it')
		{
			Db::execute("
				update caches
				set
					founds = founds + 1,
					last_found = from_unixtime('".mysql_real_escape_string($when)."')
				where cache_id = '".mysql_real_escape_string($cache['internal_id'])."'
			");
		}
		elseif ($logtype == "Didn't find it")
		{
			Db::execute("
				update caches
				set notfounds = notfounds + 1
				where cache_id = '".mysql_real_escape_string($cache['internal_id'])."'
			");
		}
		elseif ($logtype == 'Comment')
		{
			Db::execute("
				update caches
				set notes = notes + 1
				where cache_id = '".mysql_real_escape_string($cache['internal_id'])."'
			");
		}
		else
		{
			throw new Exception("Missing logtype '$logtype' in an if..elseif chain.");
		}
		
		# Update user stats.
		
		switch ($logtype)
		{
			case 'Found it': $field_to_increment = 'founds_count'; break;
			case "Didn't find it": $field_to_increment = 'notfounds_count'; break;
			case 'Comment': $field_to_increment = 'log_notes_count'; break;
			default: throw new Exception("Missing logtype '$logtype' in a switch..case statetment.");
		}
		Db::execute("
			update user
			set $field_to_increment = $field_to_increment + 1
			where user_id = '".mysql_real_escape_string($user['internal_id'])."'
		");
		
		# Call a proper outside event handler.
		
		require_once($GLOBALS['rootpath'].'lib/eventhandler.inc.php');
		event_new_log($cache['internal_id'], $user['internal_id']);
		
		# Return the uuid.
		
		return $log_uuid;
	}
	
	public static function call(OkapiRequest $request)
	{
		try
		{
			$log_uuid = self::_call($request);
			$result = array(
				'success' => true,
				'message' => "Your cache log entry was posted successfully.",
				'log_uuid' => $log_uuid
			);
		}
		catch (CannotPublishException $e)
		{
			$result = array(
				'success' => false,
				'message' => $e->getMessage(),
				'log_uuid' => null
			);
		}

		return Okapi::formatted_response($request, $result);
	}
}
