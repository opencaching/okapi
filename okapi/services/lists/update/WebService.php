<?php

namespace okapi\services\lists\update;

use okapi\core\Exception\InvalidParam;
use okapi\core\Db;
use okapi\core\Okapi;
use okapi\core\OkapiServiceRunner;
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
        $result = array(
            'success' => false
        );

        if (Settings::get('OC_BRANCH') == 'oc.de')
        {
            $user_id = $request->token->user_id;

            $listId          = $request->get_parameter('list_id');
            $listName        = $request->get_parameter('list_name');
            $listDescription = $request->get_parameter('list_description');
            $listStatus      = $request->get_parameter('list_status');
            $listWatch       = $request->get_parameter('list_watch');
            $listPassword    = $request->get_parameter('list_password');

            if (empty($listId) || !is_numeric($listId)) {
                throw new InvalidParam('list_id', 'list_id is mandatory and must be numeric.');
            }

            if (empty($listName) && empty($listDescription) && ($listStatus === null || $listStatus === '') && ($listWatch === null || $listWatch === '') && ($listPassword === null || $listPassword === '')) {
                throw new InvalidParam('list_name, list_description, list_status, list_watch, list_password', 'At least one optional parameter is required.');
            }

            $updateFields = array();

            if (!empty($listName)) {
                $updateFields['name'] = Db::escape_string($listName);
            }

            if (!empty($listDescription)) {
                $updateFields['description'] = Db::escape_string($listDescription);
            }

            if ($listStatus !== null && $listStatus !== '') {
                $listStatus = (int)$listStatus;
                if (!in_array($listStatus, [0, 2, 3])) {
                    throw new InvalidParam('list_status', 'list_status must be a valid value (0, 2, 3).');
                }
                $updateFields['is_public'] = $listStatus;

                // Handle list_password only if list_status is 0 (private)
                if ($listStatus == 0) {
                    if (isset($listPassword) && $listPassword !== '') {
                        $updateFields['password'] = substr(Db::escape_string($listPassword), 0, 16);
                    } else {
                        $updateFields['password'] = null; // Remove the password
                    }
                }
            }

            if ($listWatch !== null && $listWatch !== '') {
                $listWatch = (int)$listWatch;
                $currentWatchState = (int) Db::query("
                    SELECT COUNT(*)
                    FROM cache_list_watches
                    WHERE cache_list_id = '" . Db::escape_string($listId) . "'
                    AND user_id = '" . Db::escape_string($user_id) . "'
                ")->fetchColumn();

                if ($listWatch == 1 && $currentWatchState == 0) {
                    // Watched and not in cache_list_watches, insert
                    Db::query("
                        INSERT INTO cache_list_watches (cache_list_id, user_id)
                        VALUES ('" . Db::escape_string($listId) . "', '" . Db::escape_string($user_id) . "')
                    ");
                } elseif ($listWatch == 0 && $currentWatchState > 0) {
                    // Unwatched and in cache_list_watches, delete
                    Db::query("
                        DELETE FROM cache_list_watches
                        WHERE cache_list_id = '" . Db::escape_string($listId) . "'
                        AND user_id = '" . Db::escape_string($user_id) . "'
                    ");
                }
            }

            if (!empty($updateFields)) {
                $updateQuery = "UPDATE cache_lists SET ";
                $updateQuery .= implode(', ', array_map(function ($field, $value) {
                    return "$field = '$value'";
                }, array_keys($updateFields), $updateFields));
                $updateQuery .= " WHERE id = '" . Db::escape_string($listId) . "'";

                Db::query($updateQuery);
            }

            $result = array(
                'success' => true,
                'message' => 'Cache list updated successfully.'
            );
        }
        return Okapi::formatted_response($request, $result);
    }
}

