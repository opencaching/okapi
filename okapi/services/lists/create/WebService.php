<?php

namespace okapi\services\lists\create;

use okapi\core\Db;
use okapi\core\Okapi;
use okapi\core\Request\OkapiRequest;
use okapi\Settings;
use okapi\core\Exception\InvalidParam;

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
        $result = array(
            'success' => false
        );

        if (Settings::get('OC_BRANCH') == 'oc.de')
        {
            $user_id = $request->token->user_id;

            $listName        = $request->get_parameter('list_name');
            $listDescription = $request->get_parameter('list_description');
            $listStatus      = $request->get_parameter('list_status');
            $isWatched       = $request->get_parameter('is_watched');
            $listPassword    = $request->get_parameter('list_password');

            if (empty($listName)) {
                throw new InvalidParam('list_name', 'list_name is mandatory and must not be empty.');
            }

            $insertFields = array(
                'name' => Db::escape_string($listName),
                'user_id' => Db::escape_string($user_id)
            );

            if (!empty($listDescription)) {
                $insertFields['description'] = Db::escape_string($listDescription);
            }

            if ($listStatus !== null && $listStatus !== '') {
                $listStatus = (int)$listStatus;
                if (!in_array($listStatus, [0, 2, 3])) {
                    throw new InvalidParam('list_status', 'list_status must be a valid value (0, 2, 3).');
                }
                $insertFields['is_public'] = $listStatus;

                // Handle list_password only if list_status is 0 (private)
                if ($listStatus == 0) {
                    if (isset($listPassword) && $listPassword !== '') {
                        $insertFields['password'] = substr(Db::escape_string($listPassword), 0, 16);
                    }
                }
            }

            $columns = implode(', ', array_keys($insertFields));
            $values = "'" . implode("', '", $insertFields) . "'";

            $insertQuery = "INSERT INTO cache_lists ($columns) VALUES ($values)";
            Db::query($insertQuery);

            $listId = Db::last_insert_id();

            // Handle is_watched
            if ($isWatched !== null && $isWatched !== '') {
                $isWatched = (int)$isWatched;
                if (!in_array($isWatched, [0, 1])) {
                    throw new InvalidParam('is_watched', 'is_watched must be a valid value (0, 1).');
                }

                // Insert a new record
                Db::query("INSERT INTO cache_list_watches (cache_list_id, user_id, is_watched) VALUES (LAST_INSERT_ID(), '$user_id', $isWatched)");
            }

            $result = array(
                'success' => true,
                'message' => 'Cache list created successfully.',
                'list_id' => $listId
            );
        }
        return Okapi::formatted_response($request, $result);
    }
}

