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

        $log_uuid = $request->get_parameter('log_uuid');
        if (!$log_uuid) throw new ParamMissing('log_uuid');
        $log = OkapiServiceRunner::call(
            'services/logs/entry',
            new OkapiInternalRequest($request->consumer, null, array(
                'log_uuid' => $log_uuid,
                'fields' => 'cache_code|type|user|internal_id'
            ))
        );
        $log_author = OkapiServiceRunner::call(
            'services/users/user',
            new OkapiInternalRequest($request->consumer, null, array(
                'user_uuid' => $log['user']['uuid'],
                'fields' => 'internal_id|uuid'
            ))
        );
        if ($request->token->user_id != $log_author['internal_id'])
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
                    'fields' => 'type|req_passwd|internal_id|owner'
                ))
            );
            LogsCommon::validate_logtype_and_pw($request, $cache);
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
            LogsCommon::test_if_find_allowed($logtype, $cache, $log_author, $log['type']);
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
        } else if ($request->get_parameter('logtype') === null) {
            throw new BadRequest(
                "At least one parameter with new log data must be supplied."
            );
        }

        if ($logtype != $log['type'])
        {
            LogsCommon::update_cache_stats($cache['internal_id'], $log['type'], $logtype);
            LogsCommon::update_user_stats($request->token->user_id, $log['type'], $logtype);

            # Discard recommendation and rating, if log type changes from found/attended

            if ($log['type'] == 'Found it' || $log['type'] == 'Attended')
            {
                $user_and_cache_condition_SQL = "
                    user_id='".Db::escape_string($request->token->user_id)."'
                    and cache_id='".Db::escape_string($cache['internal_id'])."'
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

                if (Settings::get('OC_BRANCH') == 'oc.pl')
                {
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
                                score = (score*votes - '".Db::escape_string($user_score)."') / greatest(1, votes - 1),
                                votes = greatest(0, votes - 1)
                            where cache_id='".Db::escape_string($cache['internal_id'])."'
                        ");
                    }
                }
            }
        }

        Db::execute("commit");
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
            $log_uuids = self::_call($request);
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
