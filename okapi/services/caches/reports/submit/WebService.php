<?php

namespace okapi\services\caches\reports\submit;

use okapi\Okapi;
use okapi\Db;
use okapi\Request\OkapiRequest;
use okapi\Request\OkapiInternalRequest;
use okapi\Exception\ParamMissing;
use okapi\Exception\InvalidParam;
use okapi\OkapiServiceRunner;
use okapi\Settings;
use okapi\services\caches\reports\CacheReports;


class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 3,
        );
    }

    public static function call(OkapiRequest $request)
    {
        # verify parameters

        $cache_code = $request->get_parameter('cache_code');
        if ($cache_code == null)
            throw new ParamMissing('cache_code');
        $geocache = OkapiServiceRunner::call(
            'services/caches/geocache',
            new OkapiInternalRequest(
                $request->consumer,
                $request->token,
                array('cache_code' => $cache_code, 'fields' => 'internal_id')
            )
        );

        $recipient = $request->get_parameter('recipient');
        if ($recipient === null)
            throw new ParamMissing('recipient');
        if ($recipient != 'OC Team')
            throw new InvalidParam('recipient', "Must be 'OC Team'.");

        $reason = $request->get_parameter('reason');
        if ($reason === null)
            throw new ParamMissing('reason');
        $reason_internal_id = CacheReports::getReasonInternalId($reason);
        if ($reason_internal_id === null)
            throw new InvalidParam('reason', "Unknown reason '".$reason."' (at this OC site).");

        $comment = $request->get_parameter('comment');
        if ($comment === null)
            throw new ParamMissing('comment');

        # Messages returned through the 'message' result field are intended
        # for the user and need to be localized.
        $langpref = $request->get_parameter('langpref');
        if (!$langpref) $langpref = "en";
        $langprefs = explode("|", $langpref);
        Okapi::gettext_domain_init($langprefs);

        if (strlen($comment) < 3) {
            # The shortest useful comment may be German "weg" (gone).
            $success = false;
            $message = _("Please explain your geocache report.");
        } elseif (Settings::get('OC_BRANCH') == 'oc.pl' && strlen($comment) > 4096) {
            $success = false;
            $message = _("Please keep it short. Your text must not be longer than 4000 characters.");
        } else {
            $success = true;
            $message = _("Your geocache report has been submitted.");
        }

        Okapi::gettext_domain_restore();

        # submit the report

        if (!$success) {
            $report_uuid = null;
            $queue_position = null;
        } else {
            $report_uuid = Okapi::create_uuid();

            if (Settings::get('OC_BRANCH') == 'oc.de')
                $insert_fields = "uuid, cacheid, userid, reason, note, date_created";
            else
                $insert_fields = "uuid, cache_id, user_id, type, text, submit_date";

            Db::query("
                insert into ".CacheReports::getReportsTableName()." (".$insert_fields.") values (
                    '".Db::escape_string($report_uuid)."',
                    '".Db::escape_string($geocache['internal_id'])."',
                    '".Db::escape_string($request->token->user_id)."',
                    '".Db::escape_string($reason_internal_id)."',
                    '".Db::escape_string($comment)."',
                    NOW()
                )
            ");
            $queue_position = Db::select_value("
                select count(*)
                from ".CacheReports::getReportsTableName()."
                where status<>".CacheReports::getClosedReportStatusId()."
            ");
        }

        # return result

        $result = array(
            'success' => $success,
            'message' => $message,
            'report_uuid' => $report_uuid,
            'queue_position' => $queue_position
        );

        return Okapi::formatted_response($request, $result);
    }
}
