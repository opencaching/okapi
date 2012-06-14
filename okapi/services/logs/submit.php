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
use okapi\OkapiAccessToken;
use okapi\Settings;
use okapi\services\caches\search\SearchAssistant;
use okapi\BadRequest;


/** 
 * This exception is thrown by WebService::_call method, when error is detected in
 * user-supplied data. It is not a BadRequest exception - it does not imply that
 * the Consumer did anything wrong (it's the user who did). This exception shouldn't
 * be used outside of this file.
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
		# Notice, that this is "_call" method, not the usual "call" (see below
		# for "call").
		
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
			if ($when > time() + 5*60)
				throw new CannotPublishException(_("You are trying to publish a log entry with a date in future. ".
					"Cache log entries are allowed to be published in the past, but NOT in the future."));
		}
		else
			$when = time();
		$on_duplicate = $request->get_parameter('on_duplicate');
		if (!$on_duplicate) $on_duplicate = "silent_success";
		if (!in_array($on_duplicate, array('silent_success', 'user_error', 'continue')))
			throw new InvalidParam('on_duplicate', "Unknown option: '$on_duplicate'.");
		$rating = $request->get_parameter('rating');
		if ($rating !== null && (!in_array($rating, array(1,2,3,4,5))))
			throw new InvalidParam('rating', "If present, it must be an integer in the 1..5 scale.");
		if ($rating && $logtype != 'Found it')
			throw new BadRequest("Rating is allowed only for 'Found it' logtypes.");
		if ($rating !== null && (Settings::get('OC_BRANCH') == 'oc.de'))
		{
			# We will remove the rating request and change the success message
			# (which will be returned IF the rest of the query will meet all the
			# requirements).
			
			self::$success_message = _("Your cache log entry was posted successfully.").
				" ".sprintf(_("However, your cache rating was ignored, because %s does not have a rating system."),
				Okapi::get_normalized_site_name());
			$rating = null;
		}
		
		# Check if cache exists and retrieve cache internal ID (this will throw
		# a proper exception on invalid cache_code). Also, get the user object.
		
		$cache = OkapiServiceRunner::call('services/caches/geocache', new OkapiInternalRequest(
			$request->consumer, null, array('cache_code' => $cache_code,
			'fields' => 'internal_id|status|owner|type|req_passwd')));
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
				throw new CannotPublishException(_("This cache is archived. Only admins and the owner are allowed to add a log entry."));
			}
		}
		if ($cache['type'] == 'Event' && $logtype != 'Comment')
			throw new CannotPublishException(_('This cache is an Event cache. You cannot "Find it"! (But - you may "Comment" on it.)'));
		if ($logtype == 'Comment' && strlen(trim($comment)) == 0)
			throw new CannotPublishException(_("Your have to supply some text for your comment."));
		
		# Password check.
		
		if ($logtype == 'Found it' && $cache['req_passwd'])
		{
			$valid_password = Db::select_value("
				select logpw
				from caches
				where cache_id = '".mysql_real_escape_string($cache['internal_id'])."'
			");
			$supplied_password = $request->get_parameter('password');
			if (!$supplied_password)
				throw new CannotPublishException(_("This cache requires a password. You didn't provide one!"));
			if (strtolower($supplied_password) != strtolower($valid_password))
				throw new CannotPublishException(_("Invalid password!"));
		}
		
		# Duplicate detection.
		
		if ($on_duplicate != 'continue')
		{
			# Attempt to find a log entry made by the same user, for the cache, with
			# the same date, type, comment, etc. (Note, that these are not ALL the fields
			# we could check, but should be good enough.)
			
			$duplicate_uuid = Db::select_value("
				select uuid
				from cache_logs
				where
					user_id = '".mysql_real_escape_string($request->token->user_id)."'
					and cache_id = '".mysql_real_escape_string($cache['internal_id'])."'
					and type = '".mysql_real_escape_string($logtype_id)."'
					and date = from_unixtime('".mysql_real_escape_string($when)."')
					and text = '".mysql_real_escape_string(htmlspecialchars($comment, ENT_QUOTES))."'
				limit 1
			");
			if ($duplicate_uuid != null)
			{
				if ($on_duplicate == 'silent_success')
				{
					# Act as if the log has been submitted successfully.
					return $duplicate_uuid;
				}
				elseif ($on_duplicate == 'user_error')
				{
					throw new CannotPublishException(_("You have already submitted a log entry with exactly the same contents."));
				}
			}
		}
		
		# Check if already found it.
		
		if ($logtype == 'Found it')
		{
			$has_already_found_it = Db::select_value("
				select 1
				from cache_logs
				where
					user_id = '".mysql_real_escape_string($user['internal_id'])."'
					and cache_id = '".mysql_real_escape_string($cache['internal_id'])."'
					and type = '".mysql_real_escape_string(Okapi::logtypename2id("Found it"))."'
					and ".((Settings::get('OC_BRANCH') == 'oc.pl') ? "deleted = 0" : "true")."
			");
			if ($has_already_found_it)
				throw new CannotPublishException(_("You have already submitted a \"Found it\" log entry once. Now you may submit \"Comments\" only!"));
		}
		
		# Check if the user has already rated the cache. BTW: I don't get this one.
		# If we already know, that the cache was NOT found yet, then HOW could the
		# user submit a rating for it? Anyway, I will stick to the procedure
		# found in log.php.
		
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
				throw new CannotPublishException(_("You have already rated this cache once. Your rating cannot be changed."));
			if ($user['uuid'] == $cache['owner']['uuid'])
				throw new CannotPublishException(_("You are the owner of this cache. You cannot rate it."));
		}
		
		# Very weird part (as usual). OCPL stores HTML-lized comments in the database
		# (it doesn' really matter if 'text_html' field is set to FALSE). OKAPI must
		# save it in HTML either way. However, escaping plain-text doesn't work.
		# If we put "&lt;b&gt;" in, it still gets converted to "<b>" before display!
		# NONE of this process is documented. There doesn't seem to be ANY way to force
		# OCPL to treat a string as either plain-text nor HTML. It's always something
		# in between! In other words, we cannot guarantee how it gets displayed in
		# the end. Since text_html=0 doesn't add <br/>s on its own, we can only add
		# proper <br/>s and hope it's okay.
		
		$PSEUDOENCODED_comment = htmlspecialchars($comment, ENT_QUOTES);
		$PSEUDOENCODED_comment = nl2br($PSEUDOENCODED_comment);
		
		# Finally! Add the log entry.
		
		$log_uuid = create_uuid();
		Db::execute("
			insert into cache_logs (uuid, cache_id, user_id, type, date, text, last_modified, date_created, node)
			values (
				'".mysql_real_escape_string($log_uuid)."',
				'".mysql_real_escape_string($cache['internal_id'])."',
				'".mysql_real_escape_string($request->token->user_id)."',
				'".mysql_real_escape_string($logtype_id)."',
				from_unixtime('".mysql_real_escape_string($when)."'),
				'".mysql_real_escape_string($PSEUDOENCODED_comment)."',
				now(),
				now(),
				'".mysql_real_escape_string($GLOBALS['oc_nodeid'])."'
			);
		");
		$log_internal_id = Db::last_insert_id();
		
		# Store additional information on consumer_key which have created this log entry.
		# (Maybe we will want to display this somewhere later.)
		
		Db::execute("
			insert into okapi_cache_logs (log_id, consumer_key)
			values (
				'".mysql_real_escape_string($log_internal_id)."',
				'".mysql_real_escape_string($request->consumer->key)."'
			);
		");
		
		# Store the rating.
		
		if ($rating)
		{
			# This code will be called for OCPL branch only. Earlier, we made sure,
			# to set $rating to null, if we're running on OCDE.
			
			# OCPL has a little strange way of storing cumulative rating. Instead
			# of storing the sum of all ratings, OCPL stores the computed average
			# and update it using multiple floating-point operations. Moreover,
			# the "score" field in the database is on the -3..3 scale (NOT 1..5),
			# and the translation made at retrieval time is DIFFERENT than the
			# one made here (both of them are non-linear). Also, once submitted,
			# the rating can never be changed. It surely feels quite inconsistent,
			# but presumably has some deep logic into it. See also here (Polish):
			# http://wiki.opencaching.pl/index.php/Oceny_skrzynek
			
			switch ($rating)
			{
				case 1: $db_score = -2.0; break;
				case 2: $db_score = -0.5; break;
				case 3: $db_score = 0.7; break;
				case 4: $db_score = 1.7; break;
				case 5: $db_score = 3.0; break;
				default: throw new Exception();
			}
			Db::execute("
				update caches
				set
					score = (score*votes + '".mysql_real_escape_string($db_score)."')/(votes + 1),
					votes = votes + 1
				where cache_id = '".mysql_real_escape_string($cache['internal_id'])."'
			");
			Db::execute("
				insert into scores (user_id, cache_id, score)
				values (
					'".mysql_real_escape_string($user['internal_id'])."',
					'".mysql_real_escape_string($cache['internal_id'])."',
					'".mysql_real_escape_string($db_score)."'
				);
			");
		}
		
		# Update cache stats.
		
		if (Settings::get('OC_BRANCH') == 'oc.de')
		{
			# OCDE handles cache stats updates using triggers.
		}
		else
		{
			# OCPL doesn't use triggers for this. We need to update manually.
			
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
		}
		
		# Update user stats.
		
		if (Settings::get('OC_BRANCH') == 'oc.de')
		{
			# OCDE handles cache stats updates using triggers.
		}
		else
		{
			# OCPL doesn't have triggers for this. We need to update manually.
			
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
		}
		
		# Call OC's event handler. (BTW, event handlers seem a good idea, but why
		# "updating stats" needs to be implemented here, instead of inside such handler?)
		
		require_once($GLOBALS['rootpath'].'lib/eventhandler.inc.php');
		event_new_log($cache['internal_id'], $user['internal_id']);
		
		# Success. Return the uuid.
		
		return $log_uuid;
	}
	
	private static $success_message = null;
	public static function call(OkapiRequest $request)
	{
		$langpref = $request->get_parameter('langpref');
		if (!$langpref) $langpref = "en";
		
		# Error messages thrown via CannotPublishException exceptions should be localized.
		# They will be delivered for end user to display in his language.
		
		Okapi::gettext_domain_init(explode("|", $langpref));
		try
		{
			# If appropriate, $success_message might be changed inside the _call.
			self::$success_message = _("Your cache log entry was posted successfully.");
			$log_uuid = self::_call($request);
			$result = array(
				'success' => true,
				'message' => self::$success_message,
				'log_uuid' => $log_uuid
			);
			Okapi::gettext_domain_restore();
		}
		catch (CannotPublishException $e)
		{
			Okapi::gettext_domain_restore();
			$result = array(
				'success' => false,
				'message' => $e->getMessage(),
				'log_uuid' => null
			);
		}

		return Okapi::formatted_response($request, $result);
	}
}
