<?php

namespace okapi\services\lists\add_caches;

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

        $listId     = $request->get_parameter('list_id');
        $cacheCodes = $request->get_parameter('cache_codes');

        if (empty($listId)) {
            throw new InvalidParam('list_id', 'list_id is mandatory and must not be empty.');
        }

        if (empty($cacheCodes)) {
            throw new InvalidParam('cache_codes', 'cache_codes is mandatory and must not be empty.');
        }

        $cacheCodesArray = array_unique(explode('|', $cacheCodes));

        // Check the length
        if (count($cacheCodesArray) > 500) {
            throw new InvalidParam('cache_codes', 'The number of cache codes exceeds the limit of 500.');
        }

        // Escape cache codes and build the SQL query
        $escapedCacheCodes = implode("','", array_map('\okapi\core\Db::escape_string', $cacheCodesArray));

        // Fetch cache_ids from the caches table using INSERT IGNORE
        $rs = Db::query("
            INSERT IGNORE INTO cache_list_items (cache_list_id, cache_id)
            SELECT '$listId', cache_id
            FROM caches
            WHERE wp_oc IN ('$escapedCacheCodes')
        ");

        $insertedCount = $rs->rowCount(); // Get the number of affected rows

        $result = array(
            'success'     => true,
            'added_count' => $insertedCount
        );

        return Okapi::formatted_response($request, $result);
    }
}

