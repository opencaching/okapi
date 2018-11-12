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

/**
 * This method redundantly implements parts of logs/submit and logs/edit logic.
 * It's all logic that decides if a CannotPublishException is thrown.
 * This means:
 *
 *     1. To keep things simple and performant, we intentionally do
 *        somthing here which is deprecated: Implement redundant logic.
 *  
 *     2. With every change to CannotPublishException logic, developers
 *        MUST check if logs/capabilities needs an update.
 * 
 *     3. If developers fail to do so, it will not break anything. Either
 *        a new OKAPI feature may not be immediatly available to all apps,
 *        until we fix it here. Or a CannotPublishException may be thrown,
 *        informing users that a feature is not available. Therefore (1)
 *        seems acceptable.
 **/ 

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
                    'fields' => 'type|status|owner|is_found|my_rating|is_recommended'
                ))
            );
        }
        catch (InvalidParam $e)
        {
            throw new InvalidParam(
                'reference_item',
                "There is neither a geocache nor a log UUID '".$reference_item."'."
            );
        }

        # prepare some common variables

        $user = OkapiServiceRunner::call(
            'services/users/by_internal_id',
            new OkapiInternalRequest($request->consumer, $request->token, array(
                'internal_id' => $request->token->user_id,
                'fields' => 'uuid|rcmds_left|rcmd_founds_needed'
            ))
        );
        $ocpl = (Settings::get('OC_BRANCH') == 'oc.pl');
        $is_owner = ($cache['owner']['uuid'] == $user['uuid']);
        $is_logger = $submit || ($log['user']['uuid'] == $user['uuid']);
        $event = ($cache['type'] == 'Event');
        $status_logtypes = ['Available', 'Temporarily unavailable', 'Archived'];

        # calculate available logtypes

        if (!$is_logger)
        {
            $result['log_types'] = [];
        }
        elseif ($edit && in_array($log['type'], $status_logtypes))
        {
            # Changing from status log types is not implemented in OKAPI.

            $result['log_types'] = [$log['type']];
        }
        else
        {
            $disabled_logtypes = [];

            # Some log types are available only for certain cache types.

            if ($event) {
                $disabled_logtypes[] = 'Found it';
                $disabled_logtypes[] = "Didn't find it";
            } else {
                $disabled_logtypes[] = 'Attended';
                $disabled_logtypes[] = 'Will attend';
            }

            # So far OKAPI only implements cache status changes by the owner.
            # Changing to status log types is not implemented in OKAPI.

            if ($edit || !$is_owner) {
                $disabled_logtypes = array_merge($disabled_logtypes, $status_logtypes);
            }

            # There are additional restrictions at OCPL sites.

            if ($ocpl)
            {
                # OCPL owners may attend their own events, but not search their own caches.
                # Also, they cannot log multiple founds or attendances.

                if ($is_owner || $is_found) {
                    $disabled_logtypes[] = 'Found it';
                    $disabled_logtypes[] = "Didn't find it";
                }

                # An OCPL cache status cannot be repeated / confirmed.

                if (in_array($cache['status'], $status_logtypes)) {
                    $disabled_logtypes[] = $cache['status'];
                }

                # OCPL owners cannot unarchive their caches

                if ($is_owner && $cache['status'] == 'Archived') {
                    $disabled_logtypes[] = 'Temporarily unavailable';
                    $disabled_logtypes[] = 'Available';
                }
            }

            # The old logtype can always be retained.

            if ($edit) {
                $disabled_logtypes = array_diff($disabled_logtypes, [$log['type']]);
            }

            $result['log_types'] = array_diff(
                Okapi::get_submittable_logtype_names(),
                $disabled_logtypes
            );
        }

        # calculate other results
        #
        # When these properties are added to services/logs/edit,
        # the $submit operands must be replaced by $is_logger.

        $result['can_rate'] =
            $submit &&
            $ocpl &&
            !$is_owner &&
            ($cache['my_rating'] == null);

        $can_recommend = (
            $submit &&
            !$is_owner &&
            !$cache['is_recommended'] &&
            !($ocpl && $event)
        );
        if (!$can_recommend) {
            $result['can_recommend'] = 'false';
            $result['rcmd_founds_needed'] = null;
        } elseif ($user['rcmds_left'] <= 0 && $user['rcmds_left'] !== null) {
            $result['can_recommend'] = 'need_more_finds';
            $result['rcmd_founds_needed'] = $user['rcmd_founds_needed'];
        } else {
            $result['can_recommend'] = 'true';
            $result['rcmd_founds_needed'] = 0;
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
