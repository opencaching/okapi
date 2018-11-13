<?php

namespace okapi\services\logs\capabilities;

use okapi\core\Db;
use okapi\core\Exception\InvalidParam;
use okapi\core\Exception\ParamMissing;
use okapi\core\Okapi;
use okapi\core\OkapiServiceRunner;
use okapi\core\Request\OkapiInternalRequest;
use okapi\core\Request\OkapiRequest;
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
     * This method redundantly implements parts of logs/submit and logs/edit logic.
     * It's mostly logic that decides if a CannotPublishException is thrown;
     * a few cases are about BadRequest (they are annotated below).
     *
     * This means:
     *
     *     1. To keep the code simple and readable, we intentionally do something
     *        here which is deprecated: Implement redundant logic.
     *
     *     2. With every change to the allowed log paramters logic, OKAPI 
     *        developers MUST check if logs/capabilities needs an update.
     *
     *     3. If developers fail to do so in 'CannotPublishException' cases,
     *        it will not break anything. Either a new OKAPI feature may not be
     *        immediatly available to all apps, until we fix it here. Or
     *        a CannotPublishException may be thrown, informing users that a
     *        feature is not available.
     *
     *     4. If we miss a 'BadRequest' case, we introduce a inconsistency
     *        between the API specs and services/logs/capabilities. This could
     *        result in buggy clients. So all the 'BadRequests' in logs/submit,
     *        logs/edit and LogsCommons need special attention.
     **/

    public static function call(OkapiRequest $request)
    {
        $result = array();

        # evaluate reference_item

        $reference_item = $request->get_parameter('reference_item');
        if (!$reference_item) throw new ParamMissing('reference_item');
        $submit = (strlen($reference_item) < 20);
        $edit = !$submit;

        try
        {
            if ($submit) {
                $cache_code = $reference_item;
            } else {
                $log = OkapiServiceRunner::call(
                    'services/logs/entry',
                    new OkapiInternalRequest($request->consumer, $request->token, array(
                        'log_uuid' => $reference_item,
                        'fields' => 'cache_code|user|type'
                    ))
                );
                $cache_code = $log['cache_code'];
            }
            $cache = OkapiServiceRunner::call(
                'services/caches/geocache',
                new OkapiInternalRequest($request->consumer, $request->token, array(
                    'cache_code' => $cache_code,
                    'fields' => 'type|status|owner|is_found|is_recommended|my_rating'
                ))
            );
        }
        catch (InvalidParam $e)
        {
            throw new InvalidParam(
                'reference_item',
                "There is no accessible geocache or log '".$reference_item."'."
            );
        }

        # prepare some common variables

        $user = OkapiServiceRunner::call(
            'services/users/by_internal_id',
            new OkapiInternalRequest($request->consumer, $request->token, array(
                'internal_id' => $request->token->user_id,
                'fields' => 'uuid|rcmd_founds_needed'
            ))
        );
        $ocpl = (Settings::get('OC_BRANCH') == 'oc.pl');
        $is_owner = ($cache['owner']['uuid'] == $user['uuid']);
        $is_logger = $submit || ($log['user']['uuid'] == $user['uuid']);
        $event = ($cache['type'] == 'Event');

        # calculate available logtypes

        if (!$is_logger)
        {
            # The user must not edit other user's logs.
            $result['log_types'] = [];
        }
        elseif ($edit && in_array($log['type'], ['Ready to search', 'Temporarily unavailable', 'Archived']))
        {
            # Changing a status-logtype is not implemented in OKAPI.
            $result['log_types'] = [$log['type']];
        }
        else
        {
            $disabled_logtypes = [];

            # Some log types are available only for certain cache types.

            if ($event) {
                $disabled_logtypes['Found it'] = true;
                $disabled_logtypes["Didn't find it"] = true;
            } else {
                $disabled_logtypes['Attended'] = true;
                $disabled_logtypes['Will attend'] = true;
            }

            # So far OKAPI only implements cache status changes by the owner.
            # Changing to status log types also is not implemented in OKAPI.

            if ($edit || !$is_owner) {   # will throw BadRequest
                $disabled_logtypes['Ready to search'] = true;
                $disabled_logtypes['Temporarily unavailable'] = true;
                $disabled_logtypes['Archived'] = true;
            }

            # There are additional restrictions at OCPL sites.

            if ($ocpl)
            {
                # OCPL users cannot log multiple founds/attendances for the same cache.

                if ($cache['is_found']) {
                    $disabled_logtypes['Found it'] = true;
                    $disabled_logtypes["Didn't find it"] = true;
                    $disabled_logtypes['Attended'] = true;
                    $disabled_logtypes['Will attend'] = true;
                }

                # OCPL owners may attend their own events, but not search their own caches.

                if ($is_owner) {
                    $disabled_logtypes['Found it'] = true;
                    $disabled_logtypes["Didn't find it"] = true;
                }

                # An OCPL cache status cannot be repeated / confirmed.

                if ($cache['status'] == 'Available') {
                    $disabled_logtypes['Ready to search'] = true;
                } elseif (in_array($cache['status'], ['Temporarily unavailable', 'Archived'])) {
                    $disabled_logtypes[$cache['status']] = true;
                }

                # OCPL owners cannot unarchive or publish their caches.
                # (Nonpublic cache status are not implemented yet in OKAPI.)

                if ($is_owner && !in_array($cache['status'], ['Available', 'Temporarily unavailable'])) {
                    $disabled_logtypes['Temporarily unavailable'] = true;
                    $disabled_logtypes['Ready to search'] = true;
                }
            }

            # There may be old logs which were allowed before a logging policy
            # changed. services/logs/edit allows to confirm or even 'downgrade'
            # the type of those logs, for best user experience.

            if ($edit) {
                unset($disabled_logtypes[$log['type']]);
                if ($log['type'] == 'Found it')
                    unset($disabled_logtypes["Didn't find it"]);
                if ($log['type'] == 'Attended')
                    unset($disabled_logtypes['Will attend']);
            }

            $result['submittable_logtypes'] = array_values(array_diff(
                Okapi::get_submittable_logtype_names(),
                array_keys($disabled_logtypes)
            ));
        }

        # calculate the other fields

        # Note: When these properties are added to services/logs/edit, the
        # $submit operands must be replaced by $is_logger (= only own logs
        # may be edited).

        $result['can_rate'] =
            $submit &&
            $ocpl &&
            !$is_owner &&
            ($cache['my_rating'] == null);   # Re-rating may be added to OKAPI, see issue 563. 

        $can_recommend = (
            $submit &&
            !$is_owner &&
            !$cache['is_recommended'] &&
            !($ocpl && $event)
        );
        if (!$can_recommend) {
            $result['can_recommend'] = 'false';
            $result['rcmd_founds_needed'] = null;
        } else {
            # If the user did not yet find this cache, then for adding a
            # recommendation the user must either submit a 'Found it' log, or
            # make (edit) a 'Found it' from another type of log. So the user
            # adds another found, which decreases the number of additional
            # founds needed for recommending:

            $founds_needed = $user['rcmd_founds_needed'];
            if (!$cache['is_found']) --$founds_needed;

            if ($founds_needed > 0) {
                $result['can_recommend'] = 'need_more_founds';
                $result['rcmd_founds_needed'] = $founds_needed;
            } else {
                $result['can_recommend'] = 'true';
                $result['rcmd_founds_needed'] = 0;
            }
        }

        $result['can_set_needs_maintenance'] =
            $submit &&
            !($ocpl && $event);

        $result['can_reset_needs_maintenance'] =
            $submit &&
            !$ocpl;

        # Done. Return the results.

        return Okapi::formatted_response($request, $result);
    }
}
