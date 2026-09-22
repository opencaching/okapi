<?php

namespace okapi\services\lists\update;

use okapi\core\Db;
use okapi\core\Exception\BadRequest;
use okapi\core\Exception\InvalidParam;
use okapi\core\Okapi;
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

    public static function call(OkapiRequest $request)
    {
        if (Settings::get('OC_BRANCH') != 'oc.de')
            throw new BadRequest('This method is not supported in this OKAPI installation. See the has_lists field in services/apisrv/installation method.');

        $user_id = $request->token->user_id;

            $list_id          = $request->get_parameter('list_id');
            $list_name        = $request->get_parameter('list_name');
            $list_description = $request->get_parameter('list_description');
            $list_status      = $request->get_parameter('list_status');
            $list_watch       = $request->get_parameter('list_watch');
            $list_password    = $request->get_parameter('list_password');

            if (empty($list_id) || !is_numeric($list_id)) {
                throw new InvalidParam('list_id', 'list_id is mandatory and must be numeric.');
            }

            // Verify list ownership
            $count = Db::select_value("
                SELECT COUNT(*)
                FROM cache_lists
                WHERE id = '".Db::escape_string($list_id)."'
                  AND user_id = '".Db::escape_string($user_id)."'
            ");
            if ($count == 0) {
                throw new InvalidParam('list_id', 'The specified list does not exist or does not belong to you.');
            }

            if (empty($list_name) && empty($list_description) && ($list_status === null || $list_status === '') && ($list_watch === null || $list_watch === '') && ($list_password === null || $list_password === '')) {
                throw new InvalidParam('list_name, list_description, list_status, list_watch, list_password', 'At least one optional parameter is required.');
            }

            $update_parts = array();

            if (!empty($list_name)) {
                $update_parts[] = "name = '".Db::escape_string($list_name)."'";
            }

            if (!empty($list_description)) {
                $update_parts[] = "description = '".Db::escape_string($list_description)."'";
            }

            if ($list_status !== null && $list_status !== '') {
                $list_status = (int)$list_status;
                if (!in_array($list_status, [0, 2, 3])) {
                    throw new InvalidParam('list_status', 'list_status must be a valid value (0, 2, 3).');
                }
                $update_parts[] = "is_public = '".Db::escape_string($list_status)."'";

                // Handle list_password only if list_status is 0 (private)
                if ($list_status == 0) {
                    if (isset($list_password) && $list_password !== '') {
                        $update_parts[] = "password = '".Db::escape_string(substr($list_password, 0, 16))."'";
                    } else {
                        $update_parts[] = "password = NULL";
                    }
                }
            }

            if ($list_watch !== null && $list_watch !== '') {
                $list_watch = (int)$list_watch;
                $current_watch_state = (int) Db::select_value("
                    SELECT COUNT(*)
                    FROM cache_list_watches
                    WHERE cache_list_id = '".Db::escape_string($list_id)."'
                      AND user_id = '".Db::escape_string($user_id)."'
                ");

                if ($list_watch == 1 && $current_watch_state == 0) {
                    Db::query("
                        INSERT INTO cache_list_watches (cache_list_id, user_id)
                        VALUES ('".Db::escape_string($list_id)."', '".Db::escape_string($user_id)."')
                    ");
                } elseif ($list_watch == 0 && $current_watch_state > 0) {
                    Db::query("
                        DELETE FROM cache_list_watches
                        WHERE cache_list_id = '".Db::escape_string($list_id)."'
                          AND user_id = '".Db::escape_string($user_id)."'
                    ");
                }
            }

            if (!empty($update_parts)) {
                $update_query = "UPDATE cache_lists SET "
                    . implode(', ', $update_parts)
                    . " WHERE id = '".Db::escape_string($list_id)."'";
                Db::query($update_query);
            }

        $result = array(
            'success' => true,
            'message' => 'Cache list updated successfully.'
        );
        return Okapi::formatted_response($request, $result);
    }
}
