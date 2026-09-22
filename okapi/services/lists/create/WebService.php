<?php

namespace okapi\services\lists\create;

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

            $list_name        = $request->get_parameter('list_name');
            $list_description = $request->get_parameter('list_description');
            $list_status      = $request->get_parameter('list_status');
            $is_watched       = $request->get_parameter('is_watched');
            $list_password    = $request->get_parameter('list_password');

            if (empty($list_name)) {
                throw new InvalidParam('list_name', 'list_name is mandatory and must not be empty.');
            }

            $insert_fields = array(
                'name' => Db::escape_string($list_name),
                'user_id' => Db::escape_string($user_id)
            );

            if (!empty($list_description)) {
                $insert_fields['description'] = Db::escape_string($list_description);
            }

            if ($list_status !== null && $list_status !== '') {
                $list_status = (int)$list_status;
                if (!in_array($list_status, [0, 2, 3])) {
                    throw new InvalidParam('list_status', 'list_status must be a valid value (0, 2, 3).');
                }
                $insert_fields['is_public'] = $list_status;

                // Handle list_password only if list_status is 0 (private)
                if ($list_status == 0) {
                    if (isset($list_password) && $list_password !== '') {
                        $insert_fields['password'] = Db::escape_string(substr($list_password, 0, 16));
                    }
                }
            }

            $columns = implode(', ', array_keys($insert_fields));
            $values = "'" . implode("', '", $insert_fields) . "'";

            $insert_query = "INSERT INTO cache_lists ($columns) VALUES ($values)";
            Db::query($insert_query);

            $list_id = Db::last_insert_id();

            // Handle is_watched
            if ($is_watched !== null && $is_watched !== '') {
                $is_watched = (int)$is_watched;
                if (!in_array($is_watched, [0, 1])) {
                    throw new InvalidParam('is_watched', 'is_watched must be a valid value (0, 1).');
                }

                Db::query("
                    INSERT INTO cache_list_watches (cache_list_id, user_id, is_watched)
                    VALUES ('".Db::escape_string($list_id)."', '".Db::escape_string($user_id)."', '".Db::escape_string($is_watched)."')
                ");
            }

        $result = array(
            'success' => true,
            'message' => 'Cache list created successfully.',
            'list_id' => $list_id
        );
        return Okapi::formatted_response($request, $result);
    }
}
