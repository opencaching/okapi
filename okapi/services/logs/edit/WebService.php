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
     */
    private static function _call(OkapiRequest $request)
    {
        # Developers! Please notice the fundamental difference between throwing
        # CannotPublishException and the "standard" BadRequest/InvalidParam
        # exceptions. You're reading the "_call" method now (see below for
        # "call").

        # handle log_uuid

        $log = LogsCommon::process_log_uuid($request);
        if ($log['user']['internal_id'] != $request->token->user_id)
            throw new BadRequest("Only own log entries may be edited.");

        # handle logtype and password

        $logtype = $request->get_parameter('logtype');
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
        } elseif ($logtype != $log['type']) {
            $cache = OkapiServiceRunner::call(
                'services/caches/geocache',
                new OkapiInternalRequest($request->consumer, null, array(
                    'cache_code' => $log['cache_code'],
                    'fields' => 'internal_id|type|req_passwd|owner'
                ))
            );
            LogsCommon::test_if_logtype_and_pw_match_cache($request, $cache);
        }

        # handle comment

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

        # handle 'when'

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

        # Do final validations and store data.
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
        } elseif ($request->get_parameter('logtype') === null) {
            throw new BadRequest(
                "At least one parameter with new log data must be supplied."
            );
        }

        # Finalize the transaction.

        LogsCommon::update_statistics_after_change($logtype, $when, $log);
        Db::execute("commit");
        LogsCommon::update_statpic($logtype, $log['type'], $log['user']['internal_id']);
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

        Okapi::update_user_activity($request);
        return Okapi::formatted_response($request, $result);
    }
}
