<?php

namespace okapi\services\lists\delete;

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

            $listId = $request->get_parameter('list_id');

            if (empty($listId) || !is_numeric($listId)) {
                throw new InvalidParam('list_id', 'list_id is mandatory and must be numeric.');
            }

            // Check if the list exists
            $countQuery = Db::query("SELECT COUNT(*) AS count FROM cache_lists WHERE id = '$listId' AND user_id = '$user_id'");
            $listExists = Db::fetch_assoc($countQuery)['count'];
            if ($listExists == 0) {
                throw new InvalidParam('list_id', 'The specified list does not exist.');
            }

            // Proceed with the deletion process
            Db::query("DELETE FROM cache_lists WHERE id = '$listId'");
            Db::query("DELETE FROM cache_list_watches WHERE cache_list_id = '$listId'");
            Db::query("DELETE FROM cache_list_items WHERE cache_list_id   = '$listId'");

            $result = array(
                'success' => true,
                'message' => 'Cache list deleted successfully.'
            );
        }
        return Okapi::formatted_response($request, $result);
    }
}

