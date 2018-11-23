<?php

namespace okapi\services\logs\submit;

use Exception;
use okapi\core\Db;
use okapi\core\Exception\BadRequest;
use okapi\core\Exception\CannotPublishException;
use okapi\core\Exception\InvalidParam;
use okapi\core\Exception\ParamMissing;
use okapi\core\Okapi;
use okapi\core\OkapiServiceRunner;
use okapi\core\Request\OkapiInternalRequest;
use okapi\core\Request\OkapiRequest;
use okapi\services\logs\LogsCommon;
use okapi\Settings;

class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 3
        );
    }

    /**
     * Publish a new log entry and return log entry uuid(s). Throws
     * CannotPublishException or BadRequest on errors.
     *
     * IMPORTANT: The "logging policy" logic - which logs are allowed under
     * which circumstances? - is redundantly implemented in
     * services/logs/capabilities/WebService.php. Take care to keep both
     * implementations synchronized! See capabilities/WebService.php for
     * more explanation.
     */

    private static function _call(OkapiRequest $request)
    {
        # Developers! Please notice the fundamental difference between throwing
        # CannotPublishException and the "standard" BadRequest/InvalidParam
        # exceptions. You're reading the "_call" method now (see below for
        # "call").

        $cache_code = $request->get_parameter('cache_code');
        if (!$cache_code) throw new ParamMissing('cache_code');

        $logtype = $request->get_parameter('logtype');
        if (!$logtype) throw new ParamMissing('logtype');
        if (!in_array($logtype, Okapi::get_submittable_logtype_names()))
            throw new InvalidParam('logtype', "'$logtype' in not a valid logtype code.");

        $comment = $request->get_parameter('comment');
        if (!$comment) $comment = "";

        $comment_format = $request->get_parameter('comment_format');
        if (!$comment_format) $comment_format = "auto";
        if (!in_array($comment_format, array('auto', 'html', 'plaintext')))
            throw new InvalidParam('comment_format', $comment_format);

        $on_duplicate = $request->get_parameter('on_duplicate');
        if (!$on_duplicate) { $on_duplicate = "silent_success"; }
        if (!in_array($on_duplicate, array(
            'silent_success', 'user_error', 'continue'
        ))) {
            throw new InvalidParam('on_duplicate', "Unknown option: '$on_duplicate'.");
        }

        $rating = $request->get_parameter('rating');
        if ($rating !== null && (!in_array($rating, array(1,2,3,4,5)))) {
            throw new InvalidParam(
                'rating', "If present, it must be an integer in the 1..5 scale."
            );
        }
        if ($rating && $logtype != 'Found it' && $logtype != 'Attended') {
            throw new BadRequest(
                "Rating is allowed only for 'Found it' and 'Attended' logtypes."
            );
        }
        if ($rating !== null && (Settings::get('OC_BRANCH') == 'oc.de'))
        {
            # We will remove the rating request and change the success message
            # (which will be returned IF the rest of the query will meet all the
            # requirements).

            self::$success_message .= " ".sprintf(_(
                "However, your cache rating was ignored, because %s does not ".
                "have a rating system."
            ), Okapi::get_normalized_site_name());
            $rating = null;
        }

        $recommend = $request->get_parameter('recommend');
        if (!$recommend) $recommend = 'false';
        if (!in_array($recommend, array('true', 'false')))
            throw new InvalidParam('recommend', "Unknown option: '$recommend'.");
        $recommend = ($recommend == 'true');
        if ($recommend && $logtype != 'Found it')
        {
            if ($logtype != 'Attended') {
                throw new BadRequest(
                    "Recommending is allowed only for 'Found it' and 'Attended' logs."
                );
            }
            else if (Settings::get('OC_BRANCH') == 'oc.pl') {

                # We will remove the recommendation request and change the success message
                # (which will be returned IF the rest of the query will meet all the
                # requirements).

                self::$success_message .= " ".sprintf(_(
                    "However, your cache recommendation was ignored, because ".
                    "%s does not allow recommending event caches."
                ), Okapi::get_normalized_site_name());
                $recommend = null;
            }
        }

        # We'll parse both 'needs_maintenance' and 'needs_maintenance2' here, but
        # we'll use only the $needs_maintenance2 variable afterwards.

        $needs_maintenance = $request->get_parameter('needs_maintenance');
        $needs_maintenance2 = $request->get_parameter('needs_maintenance2');
        if ($needs_maintenance && $needs_maintenance2) {
            throw new BadRequest(
                "You cannot use both of these parameters at the same time: ".
                "needs_maintenance and needs_maintenance2."
            );
        }
        if (!$needs_maintenance2) { $needs_maintenance2 = 'null'; }

        # Parse $needs_maintenance and get rid of it.

        if ($needs_maintenance) {
            if ($needs_maintenance == 'true') { $needs_maintenance2 = 'true'; }
            else if ($needs_maintenance == 'false') { $needs_maintenance2 = 'null'; }
            else {
                throw new InvalidParam(
                    'needs_maintenance', "Unknown option: '$needs_maintenance'."
                );
            }
        }
        unset($needs_maintenance);

        # At this point, $needs_maintenance2 is set exactly as the user intended
        # it to be set.

        if (!in_array($needs_maintenance2, array('null', 'true', 'false'))) {
            throw new InvalidParam(
                'needs_maintenance2', "Unknown option: '$needs_maintenance2'."
            );
        }

        if (
            $needs_maintenance2 == 'false'
            && Settings::get('OC_BRANCH') == 'oc.pl'
        ) {
            # If not supported, just ignore it.

            self::$success_message .= " ".sprintf(_(
                "However, your \"does not need maintenance\" flag was ignored, because ".
                "%s does not yet support this feature."
            ), Okapi::get_normalized_site_name());
            $needs_maintenance2 = 'null';
        }

        # Check if cache exists and retrieve cache internal ID (this will throw
        # a proper exception on invalid cache_code). Also, get the user object.

        $cache = OkapiServiceRunner::call(
            'services/caches/geocache',
            new OkapiInternalRequest($request->consumer, $request->token, array(
                'cache_code' => $cache_code,
                'fields' => 'internal_id|status|owner|type|date_hidden|req_passwd|is_recommended|my_rating'
            ))
        );
        $user = OkapiServiceRunner::call(
            'services/users/by_internal_id',
            new OkapiInternalRequest($request->consumer, $request->token, array(
                'internal_id' => $request->token->user_id,
                'fields' => 'uuid|internal_id|rcmd_founds_needed'
            ))
        );

        $when = $request->get_parameter('when');
        if ($when) {
            $when = LogsCommon::validate_when_and_convert_to_unixtime(
                $when, $logtype, $cache['date_hidden']
            );
        }
        else
            $when = time();

        # Various integrity checks & check password

        LogsCommon::test_if_logtype_and_pw_match_cache($request, $cache);
        LogsCommon::validate_comment($comment, $logtype);

        if ($recommend && $user['uuid'] == $cache['owner']['uuid'])
        {
            # This is needed for OCDE, which allows to find own caches
            # and to recommend events. Won't hurt to check it for all branches.

            self::$success_message .= " "._(
                "However, your cache recommendation was ignored, because ".
                "you may not recommend your own caches."
            );
            $recommend = null;
        }

        if (in_array($logtype, array('Ready to search', 'Temporarily unavailable', 'Archived')))
        {
            if ($user['uuid'] != $cache['owner']['uuid']) {
                throw new CannotPublishException(_(
                    "You are not the owner of this cache. Only the owner may log status changes."
                ));
            }

            if (Settings::get('OC_BRANCH') == 'oc.pl')
            {
                if (preg_replace('/^Ready to search$/', 'Available', $logtype) == $cache['status']) {
                    # OCPL does not allow to confirm an existing geocache status.
                    # We silently change the log to a comment.
                    $logtype = 'Comment';
                }
                elseif ($cache['status'] == 'Archived') {
                    throw new CannotPublishException(sprintf(_(
                        "%s does not allow to unarchive geocaches."
                    ), Okapi::get_normalized_site_name()));
                }
            }

            if ($logtype == 'Archived' && $needs_maintenance2 != 'null')
            {
                self::$success_message .= " "._(
                    "However, your maintenance status tag was ignored, because ".
                    "archived caches don't need maintenance."
                );
                $needs_maintenance2 = 'null';
            }
            elseif ($logtype == 'Ready to search')
            {
                # Geocaches must be in good shape before enabling them. At OCDE,
                # enabling implies setting "cache is ok" (does not need maintenance).

                if ($needs_maintenance2 == 'true')
                    throw new CannotPublishException(_(
                        "The geocache must be maintained before it is enabled."
                    ));
                elseif ($needs_maintenance2 == 'null' && Settings::get('OC_BRANCH') == 'oc.de')
                    $needs_maintenance2 = 'false';
            }
        }

        if ($logtype == "Didn't find it" && $needs_maintenance2 != 'null')
        {
            if ($needs_maintenance2 == 'false' || Settings::get('OC_BRANCH') == 'oc.de')
            {
                # OCDE doesn't allow to set NM with "Didn't find it", because users
                # often overestimate their ability to find caches. Cache may be fine.
                # (But the user may still submit an additional comment log with NM.)

                self::$success_message .= " "._(
                    "However, your maintenance status tag was ignored, because ".
                    "you didn't find the cache."
                );
            }
        }

        # Prepare our comment to be inserted into the database.

        list($formatted_comment, $value_for_text_html_field)
            = LogsCommon::process_comment($comment, $comment_format);
        unset($comment);
        unset($comment_format);

        # Prevent bug #367. Start the transaction and lock all the rows of this
        # (user, cache) pair. In theory, we want to lock even smaller number of
        # rows here (user, cache, type=1), but this wouldn't work, because there's
        # no index for this.
        #
        # https://stackoverflow.com/questions/17068686/

        Db::execute("start transaction");
        Db::select_column("
            select 1
            from cache_logs
            where
                user_id = '".Db::escape_string($request->token->user_id)."'
                and cache_id = '".Db::escape_string($cache['internal_id'])."'
            for update
        ");

        # Duplicate detection.

        if ($on_duplicate != 'continue')
        {
            # Attempt to find a log entry made by the same user, for the same cache, with
            # the same date, type, comment, etc. Note, that these are not ALL the fields
            # we could check, but should work ok in most cases. Also note, that we
            # DO NOT guarantee that duplicate detection will succeed. If it doesn't,
            # nothing bad happens (user will just post two similar log entries).
            # Keep this simple!

            $duplicate_uuid = Db::select_value("
                select uuid
                from cache_logs
                where
                    user_id = '".Db::escape_string($request->token->user_id)."'
                    and cache_id = '".Db::escape_string($cache['internal_id'])."'
                    and type = '".Db::escape_string(Okapi::logtypename2id($logtype))."'
                    and date = from_unixtime('".Db::escape_string($when)."')
                    and text = '".Db::escape_string($formatted_comment)."'
                    ".((Settings::get('OC_BRANCH') == 'oc.pl') ? "and deleted = 0" : "")."
                limit 1
            ");
            if ($duplicate_uuid != null)
            {
                if ($on_duplicate == 'silent_success')
                {
                    # Act as if the log has been submitted successfully.
                    return [$duplicate_uuid];
                }
                elseif ($on_duplicate == 'user_error')
                {
                    throw new CannotPublishException(_(
                        "You have already submitted a log entry with exactly ".
                        "the same contents."
                    ));
                }
            }
        }

        LogsCommon::test_if_find_allowed($logtype, $cache, $user);

        # Check if the user has already rated the cache. BTW: I don't get this one.
        # If we already know, that the cache was NOT found yet, then HOW could the
        # user submit a rating for it? Anyway, I will stick to the procedure
        # found in log.php. On the bright side, it's fail-safe.

        if ($rating && $cache['my_rating'] !== null) {
            throw new CannotPublishException(_(
                "You have already rated this cache once. Your rating ".
                "cannot be changed."
            ));
        }

        # If user wants to recommend...

        if ($recommend)
        {
            # Do the same "fail-safety" check as we did for the rating.

            if ($cache['is_recommended']) {
                throw new CannotPublishException(_(
                    "You have already recommended this cache once."
                ));
            }
            LogsCommon::check_if_user_can_add_recommendation($user['rcmd_founds_needed']);
        }

        # If user checked the "needs_maintenance(2)" flag for OCPL, we will shuffle things
        # a little...

        if (Settings::get('OC_BRANCH') == 'oc.pl' && $needs_maintenance2 == 'true')
        {
            # If we're here, then we also know that the "Needs maintenance" log
            # type is supported by this OC site. However, it's a separate log
            # type, so we might have to submit two log types together:

            if ($logtype == 'Comment')
            {
                # If user submits a "Comment", we'll just change its type to
                # "Needs maintenance". Only one log entry will be issued.

                $logtype = 'Needs maintenance';
                $second_logtype = null;
                $second_formatted_comment = null;
            }
            elseif ($logtype == 'Found it')
            {
                # If "Found it", then we'll issue two log entries: one "Found
                # it" with the original comment, and second one "Needs
                # maintenance" with empty comment.

                $second_logtype = 'Needs maintenance';
                $second_formatted_comment = "";
            }
            elseif ($logtype == "Didn't find it")
            {
                # If "Didn't find it", then we'll issue two log entries, but this time
                # we'll do this the other way around. The first "Didn't find it" entry
                # will have an empty comment. We will move the comment to the second
                # "Needs maintenance" log entry. (It's okay for this behavior to change
                # in the future, but it seems natural to me.)

                $second_logtype = 'Needs maintenance';
                $second_formatted_comment = $formatted_comment;
                $formatted_comment = "";
            }
            elseif (in_array($logtype, array(
                'Ready to search', 'Temporarily unavailable', 'Archived')))
            {
                # For status-changing logs, we'll issue two log entries, but this time
                # we put the "Needs maintenance" first with empty comment, then the
                # status-changing log with comment text.

                $second_logtype = $logtype;
                $second_formatted_comment = $formatted_comment;
                $logtype = 'Needs maintenance';
                $formatted_comment = "";
            }
            else if ($logtype == 'Will attend' || $logtype == 'Attended')
            {
                # OC branches which allow maintenance logs, still don't allow them on
                # event caches.

                throw new CannotPublishException(_(
                    "Event caches cannot \"need maintenance\"."
                ));
            }
            else {
                throw new Exception();
            }
        }
        else
        {
            # User didn't check the "Needs maintenance" flag OR "Needs maintenance"
            # log type isn't supported by this server.

            $second_logtype = null;
            $second_formatted_comment = null;
        }

        # Finally! Insert the rows into the log entries table. Update
        # cache stats and user stats.

        if (Settings::get('OC_BRANCH') == 'oc.de') {
            $value_for_text_htmledit_field = Db::select_value("
                select if(no_htmledit_flag, 0, 1)
                from user
                where user_id='".Db::escape_string($user['internal_id'])."'
            ");
        } else {
            $value_for_text_htmledit_field = 1;
        }

        $log_uuids = array(
            self::insert_log_row(
                $request->consumer->key, $cache['internal_id'], $user['internal_id'],
                $logtype, $when, $formatted_comment, $value_for_text_html_field,
                $value_for_text_htmledit_field, $needs_maintenance2
            )
        );
        LogsCommon::update_cache_stats($cache['internal_id'], null, $logtype, null, $when);
        LogsCommon::update_user_stats($user['internal_id'], null, $logtype);
        if ($second_logtype != null)
        {
            # Reminder: This will only be called for OCPL branch.

            $log_uuids[] = self::insert_log_row(
                $request->consumer->key, $cache['internal_id'], $user['internal_id'],
                $second_logtype, $when + 1, $second_formatted_comment,
                $value_for_text_html_field, $value_for_text_htmledit_field, 'null'

                # Yes, the second log is the "needs maintenance" one. But this code
                # is only called for OCPL, while the last parameter of insert_log_row()
                # is only evaulated for OCDE! The 'null' is a dummy here, and the
                # "needs maintenance" information is in $second_logtype.
            );
            LogsCommon::update_cache_stats($cache['internal_id'], null, $second_logtype, null, $when + 1);
            LogsCommon::update_user_stats($user['internal_id'], null, $second_logtype);
        }

        # Save the rating.

        if ($rating)
        {
            # This code will be called for OCPL branch only. Earlier, we made sure,
            # to set $rating to null, if we're running on OCDE.

            $db_score = Okapi::encode_geocache_rating($rating);
            Db::execute("
                update caches
                set
                    score = (
                        score*votes + '".Db::escape_string($db_score)."'
                    ) / (votes + 1),
                    votes = votes + 1
                where cache_id = '".Db::escape_string($cache['internal_id'])."'
            ");
            Db::execute("
                insert into scores (user_id, cache_id, score)
                values (
                    '".Db::escape_string($user['internal_id'])."',
                    '".Db::escape_string($cache['internal_id'])."',
                    '".Db::escape_string($db_score)."'
                );
            ");
        }

        # Save recommendation.

        if ($recommend)
            LogsCommon::save_recommendation($user['internal_id'], $cache['internal_id'], $when);

        # Finalize the transaction.

        Db::execute("commit");

        LogsCommon::ocpl_housekeeping(
            $request,
            $logtype,
            "",
            $user['internal_id'],
            $cache['owner']['uuid'],
            $cache['internal_id']
        );

        # Success. Return the uuids.

        return $log_uuids;
    }

    private static $success_message = null;
    public static function call(OkapiRequest $request)
    {
        # This is the "real" entry point. A wrapper for the _call method.

        $langpref = $request->get_parameter('langpref');
        if (!$langpref) $langpref = "en";
        $langprefs = explode("|", $langpref);

        # Error messages thrown via CannotPublishException exceptions should be localized.
        # They will be delivered for end user to display in his language.

        Okapi::gettext_domain_init($langprefs);
        try
        {
            # If appropriate, $success_message might be changed inside the _call.
            self::$success_message = _("Your cache log entry was posted successfully.");
            $log_uuids = self::_call($request);
            $result = array(
                'success' => true,
                'message' => self::$success_message,
                'log_uuid' => $log_uuids[0],
                'log_uuids' => $log_uuids
            );
            Okapi::gettext_domain_restore();
        }
        catch (CannotPublishException $e)
        {
            Okapi::gettext_domain_restore();
            $result = array(
                'success' => false,
                'message' => $e->getMessage(),
                'log_uuid' => null,
                'log_uuids' => array()
            );
        }
        catch (Exception $e)
        {
            Okapi::gettext_domain_restore();
            throw $e;
        }

        return Okapi::formatted_response($request, $result);
    }

    private static function insert_log_row(
        $consumer_key, $cache_internal_id, $user_internal_id, $logtype, $when,
        $formatted_comment, $text_html, $text_htmledit, $needs_maintenance2
    )
    {
        if (Settings::get('OC_BRANCH') == 'oc.de') {
            $needs_maintenance_field_SQL = ', needs_maintenance';
            if ($needs_maintenance2 == 'true') {
                $needs_maintenance_SQL = ',2';
            } else if ($needs_maintenance2 == 'false') {
                $needs_maintenance_SQL = ',1';
            } else {  // 'null'
                $needs_maintenance_SQL = ',0';
            }
        } else {
            $needs_maintenance_field_SQL = '';
            $needs_maintenance_SQL = '';
        }

        $log_uuid = Okapi::create_uuid();

        Db::execute("
            insert into cache_logs (
                uuid, cache_id, user_id, type, date, text, text_html, text_htmledit,
                last_modified, date_created, node".$needs_maintenance_field_SQL."
            ) values (
                '".Db::escape_string($log_uuid)."',
                '".Db::escape_string($cache_internal_id)."',
                '".Db::escape_string($user_internal_id)."',
                '".Db::escape_string(Okapi::logtypename2id($logtype))."',
                from_unixtime('".Db::escape_string($when)."'),
                '".Db::escape_string($formatted_comment)."',
                '".Db::escape_string($text_html)."',
                '".Db::escape_string($text_htmledit)."',
                now(),
                now(),
                '".Db::escape_string(Settings::get('OC_NODE_ID'))."'
                ".$needs_maintenance_SQL."
            );
        ");
        $log_internal_id = Db::last_insert_id();

        # Store additional information on consumer_key which has created this log entry.
        # (Maybe we'll want to display this somewhen later.)

        Db::execute("
            insert into okapi_submitted_objects (object_type, object_id, consumer_key)
            values (
                ".Okapi::OBJECT_TYPE_CACHE_LOG.",
                '".Db::escape_string($log_internal_id)."',
                '".Db::escape_string($consumer_key)."'
            );
        ");

        # update geocache status

        if (in_array($logtype, array('Ready to search', 'Temporarily unavailable', 'Archived')))
        {
            if (Settings::get('OC_BRANCH') == 'oc.de') {
                Db::execute("
                    set @STATUS_CHANGE_USER_ID = '".Db::escape_string($user_internal_id)."'"
                );
                $set_last_modified_SQL = "";
                # OCDE will update last_modified via trigger if status changes.
                }
            else
                $set_last_modified_SQL = ", last_modified = NOW()";

            $status_id = Okapi::cache_status_name2id(preg_replace('/^Ready to search$/', 'Available', $logtype));
            DB::execute("
                update caches
                set status = '".Db::escape_string($status_id)."'".$set_last_modified_SQL."
                where cache_id = '".Db::escape_string($cache_internal_id)."'
            ");
        }

        return $log_uuid;
    }
}
