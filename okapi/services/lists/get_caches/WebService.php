<?php

namespace okapi\services\lists\get_caches;

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

        $user_id = $request->token->user_id;
        $listId = $request->get_parameter('list_id');

        if (empty($listId)) {
            throw new InvalidParam('list_id', 'list_id is mandatory and must not be empty.');
        }

        // Fetch cache_ids associated with the specified list
        $cacheIdsArray = Db::select_column("
            SELECT cache_id
            FROM cache_list_items
            WHERE cache_list_id = '$listId'
        ");

        $cacheCount = count($cacheIdsArray);

        // Fetch cache_codes based on cache_ids
        $cacheCodesArray = array();

        if (!empty($cacheIdsArray)) {
            $cacheIds = implode(',', $cacheIdsArray);
            $cacheCodesArray = Db::select_column(
                "SELECT wp_oc FROM caches WHERE cache_id IN ($cacheIds)"
            );
        }

        $result = array(
            'success' => true,
            'cache_codes' => implode('|', $cacheCodesArray),
            'cache_count' => $cacheCount
        );

        return Okapi::formatted_response($request, $result);
    }
}

