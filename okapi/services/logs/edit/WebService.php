<?php

namespace okapi\services\logs\edit;

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
     * Publish a new log entry and return log entry uuid. Throws
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

        # log_uuid

        $log = LogsCommon::process_log_uuid($request);
        if ($log['user']['internal_id'] != $request->token->user_id)
            throw new BadRequest("Only own log entries may be edited.");

        # logtype and password

        $logtype = $logtype_param = $request->get_parameter('logtype');
        if ($logtype === null)
            $logtype = $log['type'];
        elseif (!in_array($logtype, array(
            'Found it', "Didn't find it", 'Comment', 'Will attend', 'Attended'
        ))) {
            throw new InvalidParam('logtype', "'$logtype' in not a valid logtype code.");
        } elseif (!in_array($log['type'], array(
            'Found it', "Didn't find it", 'Comment', 'Will attend', 'Attended'
        ))) {
            throw new CannotPublishException("Cannot change the type of this log");
        }
        $cache = OkapiServiceRunner::call(
            'services/caches/geocache',
            new OkapiInternalRequest($request->consumer, $request->token, array(
                'cache_code' => $log['cache_code'],
                'fields' => 'internal_id|type|req_passwd|owner|is_recommended|my_rating'
            ))
        );
        if ($logtype != $log['type']) {
            LogsCommon::test_if_logtype_and_pw_match_cache($request, $cache);
        }

        # comment

        $comment = $request->get_parameter('comment');
        if ($comment !== null)
        {
            LogsCommon::validate_comment($comment, $logtype);

            $comment_format = $request->get_parameter('comment_format');
            if ($comment_format === null)
                throw new ParamMissing('comment_format');
            if (!in_array($comment_format, ['html', 'plaintext']))
                throw new InvalidParam('comment_format', $comment_format);
            list($formatted_comment, $value_for_text_html_field)
                = LogsCommon::process_comment($comment, $comment_format);
        } else {
            $formatted_comment = null;
        }
        unset($comment);
        unset($comment_format);

        # 'when'

        $when = $request->get_parameter('when');
        if ($when !== null)
        {
            $cache_tmp = OkapiServiceRunner::call(
                'services/caches/geocache',
                new OkapiInternalRequest($request->consumer, null, array(
                    'cache_code' => $log['cache_code'],
                    'fields' => 'date_hidden'
                ))
            );
            $when = LogsCommon::validate_when_and_convert_to_unixtime(
                $when, $logtype, $cache_tmp['date_hidden']
            );
            unset($cache_tmp);
        }

        # recommend

        $recommend = $recommend_param = $request->get_parameter('recommend');
        if ($recommend === null)
            $recommend = 'unchanged';
        elseif (!in_array($recommend, ['true', 'false', 'unchanged']))
            throw new InvalidParam('recommend');

        if ($recommend == 'true' && !$cache['is_recommended'])
        {
            # The logic of recommend=true when editing differs intentionally
            # from submitting: We allow to confirm an existing recommendation,
            # instead of throwing a CannotPublishException(). This makes the
            # services/logs/edit options consistent: All allow to confirm an
            # existing value.

            if (!in_array($logtype, ['Found it', 'Attended'])) {
                throw new BadRequest(
                    "Recommending is allowed only for 'Found it' and 'Attended' logs."
                );
            }
            if ($cache['type'] == 'Event' && Settings::get('OC_BRANCH') == 'oc.pl') {
                throw new CannotPublishException(sprintf(_(
                    "%s does not allow recommending event caches."
                ), Okapi::get_normalized_site_name()));
            }
            if ($log['user']['uuid'] == $cache['owner']['uuid']) {
                throw new CannotPublishException(_("You may not recommend your own caches."));
            }
            $user = OkapiServiceRunner::call(
                'services/users/user',
                new OkapiInternalRequest($request->consumer, null, array(
                    'user_uuid' => $log['user']['uuid'],
                    'fields' => 'rcmd_founds_needed'
                ))
            );
            LogsCommon::check_if_user_can_add_recommendation($user['rcmd_founds_needed']);
            unset($user);
        }
        elseif ($recommend == 'false' && $cache['is_recommended'])
        {
            # For forward compatibility, we enforce the log type also for revocation.
            if (!in_array($log['type'], ['Found it', 'Attended'])) {
                throw new BadRequest(
                    "Must be a 'Found it' or 'Attended' log entry to withdraw recommendation."
                );
            }
        }
        else
        {
            $recommend = 'unchanged';
        }

        # rating

        $rating = $rating_param = $request->get_parameter('rating');
        if ($rating === null)
            $rating = 'unchanged';
        elseif (!in_array($rating, ['false', 'unchanged']))
            throw new InvalidParam('rating');

        if ($rating == 'false' && $cache['my_rating'] == null)   # also catches OCDE
            $rating = 'unchanged';

        # For forward compatibility, we enforce the log type also for revocation.
        if ($rating != 'unchanged' && !in_array($log['type'], ['Found it', 'Attended'])) {
            throw new BadRequest(
                "Must be a 'Found it' or 'Attended' log entry to withdraw rating."
            );
        }

        # Now do final validations and store data.
        # See comment on transaction in services/logs/submit code.

        Db::execute("start transaction");
        $set_SQL = [];

        if ($logtype != $log['type']) {
            LogsCommon::test_if_find_allowed($logtype, $cache, $log['user'], $log['type']);
            $set_SQL[] =
                "type = '".Db::escape_string(Okapi::logtypename2id($logtype))."'";
        }
        if ($formatted_comment !== null) {
            $set_SQL[] =
                "text = '".Db::escape_string($formatted_comment)."', ".
                "text_html = '".Db::escape_string($value_for_text_html_field)."'";
        }
        if ($when !== null) {
            $set_SQL[] =
                "date = from_unixtime('".Db::escape_string($when)."')";
        }
        if ($recommend != 'unchanged') {
            if ($recommend == 'true') {
                LogsCommon::save_recommendation(
                    $log['user']['internal_id'],
                    $log['cache_internal_id'],
                    $when !== null ? $when : strtotime($log['date'])
                );
            } else {
                Db::execute("
                    delete from cache_rating
                    where user_id='".Db::escape_string($log['user']['internal_id'])."'
                    and cache_id='".Db::escape_string($log['cache_internal_id'])."'
                ");
            }
            $set_SQL[] = 'node = node';   # dummy, triggers OCPL last_modified update
        }
        if ($rating == 'false') {
            # Note that update_statistics_after_change() will redundantly call
            # withdraw_rating(), if the user changes log type from "Found it" or
            # "Attended" to something else. But it's failsafe this way - e.g.
            # imagine an OC site that combines ratings with multiple found logs
            # per cache ...

            LogsCommon::withdraw_rating($log['user']['internal_id'], $log['cache_internal_id']);

            # As ratings are private and thus neither displayed alongside logs
            # nor included in OKAPI replication, cache_logs.last_modified is
            # not updated.
        }

        if ($set_SQL) {
            if (Settings::get('OC_BRANCH') == 'oc.pl') {
                $set_SQL[] = "last_modified = NOW()";
                # OCDE handles last_modified via trigger
            }
            Db::execute("
                update cache_logs
                set ".implode(", ", $set_SQL)."
                where id='".Db::escape_string($log['internal_id'])."'
            ");
        }
        elseif ($logtype_param === null && $recommend_param === null && $rating_param === null) {
            throw new BadRequest(
                "At least one parameter with submitted log data must be supplied."
            );
        }

        # Finalize the transaction.

        LogsCommon::update_statistics_after_change($logtype, $when, $log);
        Db::execute("commit");
        LogsCommon::update_statpics(
            $request,
            $logtype,
            $log['type'],
            $log['user']['internal_id'],
            $cache['owner']['uuid']
        );
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
            self::$success_message = _("Your log entry has been updated successfully.");
            self::_call($request);
            $result = array(
                'success' => true,
                'message' => self::$success_message,
            );
            Okapi::gettext_domain_restore();
        }
        catch (CannotPublishException $e)
        {
            Okapi::gettext_domain_restore();
            $result = array(
                'success' => false,
                'message' => $e->getMessage(),
            );
        }
        catch (Exception $e)
        {
            Okapi::gettext_domain_restore();
            throw $e;
        }

        return Okapi::formatted_response($request, $result);
    }
}
