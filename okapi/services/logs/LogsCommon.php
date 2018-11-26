<?php

/**
 * Common code for submitting and editing logs
 */

namespace okapi\services\logs;

use Exception;
use okapi\core\Db;
use okapi\core\Exception\CannotPublishException;
use okapi\core\Exception\InvalidParam;
use okapi\core\Exception\ParamMissing;
use okapi\core\Okapi;
use okapi\core\OkapiServiceRunner;
use okapi\core\Request\OkapiInternalRequest;
use okapi\lib\OCPLSignals;
use okapi\Settings;
use Utils\Text\UserInputFilter; // OCPL specific

/**
 * IMPORTANT: The "logging policy" logic - which logs are allowed under
 * which circumstances? - is redundantly implemented in
 * services/logs/capabilities/WebService.php. Take care to keep both
 * implementations synchronized! See capabilities/WebService.php for
 * more explanation.
 */

# TODO: Evaluate if we can make this a common base class of logging methods,
# instead of copying lots of data in and out.

class LogsCommon
{
    public static function process_log_uuid($request)
    {
        $log_uuid = $request->get_parameter('log_uuid');
        if (!$log_uuid)
            throw new ParamMissing('log_uuid');
        $log = OkapiServiceRunner::call(
            'services/logs/entry',
            new OkapiInternalRequest($request->consumer, null, array(
                'log_uuid' => $log_uuid,
                'fields' => 'cache_code|type|date|user|images|internal_id'
            ))
        );
        $log_internal = Db::select_row("
            select node, cache_id, user_id
            from cache_logs
            where uuid='".Db::escape_string($log_uuid)."'
        ");
        if ($log_internal['node'] != Settings::get('OC_NODE_ID')) {
            throw new Exception(
                "This site's database contains the log entry '$log_uuid' which has been"
                . " imported from another OC node. OKAPI is not prepared for that."
            );
        }
        $log['cache_internal_id'] = $log_internal['cache_id'];
        $log['user']['internal_id'] = $log_internal['user_id'];
        return $log;
    }

    public static function test_if_logtype_and_pw_match_cache($request, $cache)
    {
        $logtype = $request->get_parameter('logtype');

        if ($cache['type'] == 'Event')
        {
            if (in_array($logtype, array('Found it', "Didn't find it"))) {
                throw new CannotPublishException(_(
                    'This cache is an Event cache. You cannot "Find" it (but '.
                    'you can attend it, or comment on it)!'
                ));
            }
        }
        else  # type != event
        {
            if (in_array($logtype, array('Will attend', 'Attended'))) {
                throw new CannotPublishException(_(
                    'This cache is NOT an Event cache. You cannot "Attend" it '.
                    '(but you can find it, or comment on it)!'
                ));
            }
        }

        if (($logtype == 'Found it' || $logtype == 'Attended') && $cache['req_passwd'])
        {
            $valid_password = Db::select_value("
                select logpw
                from caches
                where cache_id = '".Db::escape_string($cache['internal_id'])."'
            ");
            $supplied_password = $request->get_parameter('password');
            if (!$supplied_password) {
                throw new CannotPublishException(_(
                    "This cache requires a password. You didn't provide one!"
                ));
            }
            if (strtolower($supplied_password) != strtolower($valid_password)) {
                throw new CannotPublishException(_("Invalid password!"));
            }
        }
    }

    public static function validate_comment($comment, $logtype)
    {
        if ($logtype == 'Comment' && strlen(trim($comment)) == 0) {
            throw new CannotPublishException(_(
                "Your have to supply some text for your comment."
            ));
        }
    }

    /*
     * Convert the supplied ISO 8601 log date/time to a UNIX timestamp and
     * validate it.
     *
     * We might also check for Found/DNF dates before date_hidden, but beware
     * of wrong hide dates! Sometimes GC listings are copied to OC without
     * the hide date, which then defaults to the OC listing date. Then owners
     * become inactive. Logges will want to copy old GC logs to OC and may
     * enter wrong log dates to bypass our validation.
     */

    public static function validate_when_and_convert_to_unixtime($when_iso8601, $logtype, $date_hidden)
    {
        $when = strtotime($when_iso8601);
        if ($when < 1) {
            throw new InvalidParam(
                'when', "'$when_iso8601' is not in a valid format or is not a valid date."
            );
        }
        if ($when > time() + 5*60) {
            throw new CannotPublishException(_(
                "You are trying to publish a log entry with a date in ".
                "future. Cache log entries are allowed to be published in ".
                "the past, but NOT in the future."
            ));
        }
        if ($logtype == 'Attended' && $when < strtotime($date_hidden)) {
            throw new CannotPublishException(_(
                'You cannot attend an event before it takes place. '.
                'Please check the log type and date.'
            ));
        }
        return $when;
    }

    /*
     * Prepare our comment to be inserted into the database. This may require
     * some reformatting which depends on the current OC installation.
     *
     * OC sites store all comments in HTML format, while the 'text_html' field
     * indicates their *original* format as delivered by the user. This
     * allows processing the 'text' field contents without caring about the
     * original format, while still being able to re-create the comment in
     * its original form.
     */

    public static function process_comment($comment, $comment_format)
    {
        if ($comment_format == 'plaintext')
        {
            # This encoding is identical to the plaintext processing in OCDE code.
            # It is ok also for OCPL, which no longer allows to enter plaintext
            # on the website.

            $formatted_comment = htmlspecialchars($comment, ENT_COMPAT);
            $formatted_comment = nl2br($formatted_comment);
            $formatted_comment = str_replace('  ', '&nbsp; ', $formatted_comment);
            $formatted_comment = str_replace('  ', '&nbsp; ', $formatted_comment);

            if (Settings::get('OC_BRANCH') == 'oc.de')
            {
                $value_for_text_html_field = 0;
            }
            else
            {
                # 'text_html' = 0 (false) is broken in OCPL code and has been
                # deprecated; OCPL code was changed to always set it to 1 (true).
                # For OKAPI, the value has been changed from 0 to 1 with commit
                # cb7d222, after an email discussion with Harrie Klomp. This is
                # an ID of the appropriate email thread:
                #
                # Message-ID: <22b643093838b151b300f969f699aa04@harrieklomp.be>
                #
                # Later changed to 2 due to https://github.com/opencaching/opencaching-pl/pull/1224.

                $value_for_text_html_field = 2;
            }
        }
        else
        {
            if ($comment_format == 'auto')
            {
                # 'Auto' is for backward compatibility. Before the "comment_format"
                # was introduced, OKAPI used a weird format in between (it allowed
                # HTML, but applied nl2br too).

                $formatted_comment = nl2br($comment);
            }
            else
            {
                $formatted_comment = $comment;
            }

            # For user-supplied HTML comments, OC sites require us to do
            # additional HTML purification prior to the insertion into the
            # database.

            # NOTICE: For both branches, we are including EXTERNAL OCDE
            # libraries here! This code does not belong to OKAPI!

            if (Settings::get('OC_BRANCH') == 'oc.de')
            {
                $opt['html_purifier'] = Settings::get('OCDE_HTML_PURIFIER_SETTINGS');

                $purifier = new \OcHTMLPurifier($opt);
                $formatted_comment = $purifier->purify($formatted_comment);

                $value_for_text_html_field = 1;
            }
            else
            {
                $formatted_comment = UserInputFilter::purifyHtmlString($formatted_comment);

                # see https://github.com/opencaching/opencaching-pl/pull/1224
                $value_for_text_html_field = 2;
            }
        }

        return [$formatted_comment, $value_for_text_html_field];
    }

    public static function test_if_find_allowed($new_logtype, $cache, $user, $old_logtype="")
    {
        # Check if already found it (and make sure the user is not the owner).

        if (Settings::get('OC_BRANCH') == 'oc.pl' &&
            $new_logtype != $old_logtype &&
            in_array($new_logtype, array('Found it', "Didn't find it", 'Attended', 'Will attend')) &&
            !($new_logtype == "Didn't find it" && $old_logtype == 'Found it') &&
            !($new_logtype == "Will attend" && $old_logtype == 'Attended')
        ) {
            # OCPL owners are allowed to attend their own events, but not to
            # search their own caches:

            if ($user['uuid'] == $cache['owner']['uuid'] &&
                !in_array($new_logtype, ['Attended', 'Will attend'])
            ) {
                throw new CannotPublishException(_(
                    "You are the owner of this cache. You may submit ".
                    "\"Comments\" and status logs only!"
                ));
            }

            # OCPL forbids logging 'Found it', "Didn't find", 'Will attend' and 'Attended'
            # for an already found/attended cache, while OCDE allows all kinds of duplicate
            # logs.

            if ($new_logtype == "Didn't find it")
                $matching_logtype = 'Found it';
            elseif ($new_logtype == 'Will attend')
                $matching_logtype = 'Attended';
            else
                $matching_logtype = $new_logtype;

            $has_already_found_it = Db::select_value("
                select 1
                from cache_logs
                where
                    user_id = '".Db::escape_string($user['internal_id'])."'
                    and cache_id = '".Db::escape_string($cache['internal_id'])."'
                    and type = '".Db::escape_string(Okapi::logtypename2id($matching_logtype))."'
                    and deleted = 0
                limit 1
                /* there should be maximum 1 of these logs, but who knows ... */
            ");
            if ($has_already_found_it) {
                throw new CannotPublishException(
                    $matching_logtype == 'Found it'
                    ? _("You have already submitted a \"Found it\" log entry once. ".
                        "Now you may submit \"Comments\" only!")
                    : _("You have already submitted an \"Attended\" log entry once. ".
                        "Now you may submit \"Comments\" only!")
                );
            }
        }
    }

    public static function check_if_user_can_add_recommendation($user_founds_needed)
    {
        # If only 1 more found is needed and this is the users' first 'Found it'
        # for the cache, then *this* 'Found it' allows to recommend it.
        # Note that OCDE allows multiple founds per cache, and all of them
        # count for the recommendations.

        $founds_needed = $user_founds_needed - 1;

        if ($founds_needed > 0) {
            throw new CannotPublishException(sprintf(ngettext(
                "You don't have any recommendations to give. Find one more cache first!",
                "You don't have any recommendations to give. Find %d more caches first!",
                $founds_needed
            ), $founds_needed));
        }
    }

    public static function update_cache_stats($cache_internal_id, $old_logtype, $new_logtype, $old_date, $when)
    {
        if (Settings::get('OC_BRANCH') == 'oc.de')
        {
            # OCDE handles cache stats updates using triggers. So, they are already
            # incremented properly.
        }
        else
        {
            # OCPL doesn't use triggers for this. We need to update manually.

            $deltaFound = 0;
            $deltaDNF = 0;
            $deltaComment = 0;

            if ($old_logtype == 'Found it' || $old_logtype == 'Attended')
                --$deltaFound;
            elseif ($old_logtype == "Didn't find it" || $old_logtype == 'Will attend')
                --$deltaDNF;
            elseif ($old_logtype == 'Comment')
                --$deltaComment;

            $is_foundlog = ($new_logtype == 'Found it' || $new_logtype == 'Attended');
            if ($is_foundlog)
                ++$deltaFound;
            elseif ($new_logtype == "Didn't find it" || $new_logtype == 'Will attend')
                ++$deltaDNF;
            elseif ($new_logtype == 'Comment')
                ++$deltaComment;

            # Other log types do not change the cache stats.

            if ($deltaFound > 0 || ($deltaFound == 0 && $is_foundlog && $when > strtotime($old_date))) {
                $set_lastfound_SQL = ",
                    last_found = greatest(
                        ifnull(last_found, '0000-00-00'),
                        from_unixtime('".Db::escape_string($when)."')
                    )
                ";
            } elseif ($deltaFound < 0 || ($is_foundlog && $when < strtotime($old_date))) {
                $last_found = Db::select_value("
                    select max(`date`)
                    from cache_logs
                    where type in (1,7) and cache_id = '".Db::escape_string($cache_internal_id)."' and deleted = 0
                ");
                $set_lastfound_SQL = ", last_found='".Db::escape_string($last_found)."'";
            } else {
                $set_lastfound_SQL = "";
            }

            Db::execute("
                update caches
                set
                    founds = greatest(0, '".Db::escape_string($deltaFound)."' + founds),
                    notfounds = greatest(0, '".Db::escape_string($deltaDNF)."' + notfounds),
                    notes = greatest(0, '".Db::escape_string($deltaComment)."' + notes)
                    ".$set_lastfound_SQL."
                where cache_id = '".Db::escape_string($cache_internal_id)."'
            ");
        }
    }

    public static function update_user_stats($user_internal_id, $old_logtype, $new_logtype)
    {
        if (Settings::get('OC_BRANCH') == 'oc.de')
        {
            # OCDE handles cache stats updates using triggers. So, they are already
            # incremented properly.
        }
        else
        {
            # OCPL doesn't have triggers for this. We need to update manually.

            $deltaFound = 0;
            $deltaDNF = 0;
            $deltaComment = 0;

            if ($old_logtype == 'Found it')
                --$deltaFound;
            elseif ($old_logtype == "Didn't find it")
                --$deltaDNF;
            elseif ($old_logtype == 'Comment')
                --$deltaComment;

            if ($new_logtype == 'Found it')
                ++$deltaFound;
            elseif ($new_logtype == "Didn't find it")
                ++$deltaDNF;
            elseif ($new_logtype == 'Comment')
                ++$deltaComment;

            # Other log types do not change the cache stats.

            Db::execute("
                update user
                set
                    founds_count = greatest(0, '".Db::escape_string($deltaFound)."' + founds_count),
                    notfounds_count = greatest(0, '".Db::escape_string($deltaDNF)."' + notfounds_count),
                    log_notes_count = greatest(0, '".Db::escape_string($deltaComment)."' + log_notes_count)
                where user_id = '".Db::escape_string($user_internal_id)."'
            ");
        }
    }

    public static function update_statistics_after_change($new_logtype, $when, $log)
    {
        if (!$when) {
            $when = strtotime($log['date']);
        }
        LogsCommon::update_cache_stats($log['cache_internal_id'], $log['type'], $new_logtype, $log['date'], $when);
        if ($new_logtype == $log['type']) {
            return;
        }
        LogsCommon::update_user_stats($log['user']['internal_id'], $log['type'], $new_logtype);

        # TO DO: update OCPL "Merit Badges" (issue #552)

        # Discard recommendation and rating, if log type changes from found/attended

        if ($log['type'] == 'Found it' || $log['type'] == 'Attended')
        {
            $user_and_cache_condition_SQL = "
                user_id='".Db::escape_string($log['user']['internal_id'])."'
                and cache_id='".Db::escape_string($log['cache_internal_id'])."'
            ";

            # OCDE allows multiple finds per cache. If one of those finds
            # disappears, the cache can still be recommended by the user.
            # We handle that in a most simple, non-optimized way (which btw
            # also graciously handles any illegitimate duplicate finds in
            # an OCPL database).

            $last_found = Db::select_value("
                select max(`date`)
                from cache_logs
                where ".$user_and_cache_condition_SQL."
                and type in (1,7)
                ".(Settings::get('OC_BRANCH') == 'oc.pl' ? "and deleted = 0" : "") . "
            ");
            if (!$last_found) {
                Db::execute("
                    delete from cache_rating
                    where ".$user_and_cache_condition_SQL."
                ");
            } elseif (Settings::get('OC_BRANCH') == 'oc.de') {
                Db::execute("
                    update cache_rating
                    set rating_date='".Db::escape_string($last_found)."'
                    where ".$user_and_cache_condition_SQL."
                ");
            }
            unset($condition_SQL);

            # If the user rated the cache, we need to remove that rating
            # and recalculate the cache's rating stats.

            LogsCommon::withdraw_rating($log['user']['internal_id'], $log['cache_internal_id']);
        }
    }

    public static function withdraw_rating($user_interal_id, $cache_internal_id)
    {
        if (Settings::get('OC_BRANCH') == 'oc.pl')
        {
            $user_and_cache_condition_SQL = "
                user_id='".Db::escape_string($user_interal_id)."'
                and cache_id='".Db::escape_string($cache_internal_id)."'
            ";
            $user_score = Db::select_value("
                select score
                from scores
                where ".$user_and_cache_condition_SQL."
            ");
            if ($user_score !== null)
            {
                Db::execute("
                    delete from scores
                    where ".$user_and_cache_condition_SQL."
                ");
                Db::execute("
                    update caches
                    set
                        score = (score*votes - ".Db::float_sql($user_score).") / greatest(1, votes - 1),
                        votes = greatest(0, votes - 1)
                    where cache_id='".Db::escape_string($cache_internal_id)."'
                ");
            }
        }
    }

    public static function save_recommendation($user_internal_id, $cache_internal_id, $when)
    {
        if (Db::field_exists('cache_rating', 'rating_date'))
        {
            Db::execute("
                insert into cache_rating (user_id, cache_id, rating_date)
                values (
                    '".Db::escape_string($user_internal_id)."',
                    '".Db::escape_string($cache_internal_id)."',
                    from_unixtime('".Db::escape_string($when)."')
                );
            ");
        }
        else
        {
            Db::execute("
                insert into cache_rating (user_id, cache_id)
                values (
                    '".Db::escape_string($user_internal_id)."',
                    '".Db::escape_string($cache_internal_id)."'
                );
            ");
        }
    }

    public static function ocpl_housekeeping(
        $request,
        $new_logtype,
        $old_logtype,
        $user_internal_id,
        $owner_uuid,
        $cache_internal_id
    ) {
        # For OCPL we need to delete stats-pictures which are changed by the log.
        # Otherwise, the legacy OCPL code won't detect that the picture needs to
        # be refreshed.

        # OCDE will detect the change on its own (though there is some buggy
        # statpic deletion code in OCDE code, which effectively does nothing due
        # to wrong file path. But that should be fixed in OCDE code, not worked
        # around here.)

        if ($new_logtype == $old_logtype || Settings::get('OC_BRANCH') != 'oc.pl')
            return;

        $update_user_ids = [];

        if ($new_logtype == 'Found it' || $new_logtype == 'Attended' ||
            $old_logtype == 'Found it' || $old_logtype == 'Attended')
        {
            $update_user_ids[] = $user_internal_id;

            # Also trigger an OCPL "merit badges" update.
            OCPLSignals::update_merit_badges($cache_internal_id, $user_internal_id);
        }

        # OCPL only counts active caches in the statpic. We need to do the same
        # thing for the owner's statpic, if the cache availability may have
        # changed.

        if ($owner_uuid !== null && array_intersect(
            [$new_logtype, $old_logtype],
            ['Ready to search', 'Temporarily unavailable', 'Archived']
        )) {
            $owner = OkapiServiceRunner::call(
                'services/users/user',
                new OkapiInternalRequest($request->consumer, null, array(
                    'user_uuid' => $owner_uuid,
                    'fields' => 'internal_id'
                ))
            );
            $update_user_ids[] = $owner['internal_id'];
        }

        foreach ($update_user_ids as $user_id)
        {
            $filepath = Okapi::get_var_dir().'/images/statpics/statpic'.$user_id.'.jpg';
            if (file_exists($filepath)) {
                unlink($filepath);
            }
        }
    }

}
