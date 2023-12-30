<?php

namespace okapi\services\lists\remove_caches;

use okapi\core\Exception\InvalidParam;
use okapi\core\Exception\ParamMissing;
use okapi\core\Db;
use okapi\core\Okapi;
use okapi\core\OkapiServiceRunner;
use okapi\core\Request\OkapiInternalRequest;
use okapi\core\Request\OkapiRequest;
use okapi\services\logs\LogsCommon;
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
        
        // Delete cache_ids from the cache_list_items table
        $rs = Db::query("
            DELETE FROM cache_list_items
            WHERE cache_list_id = '$listId'
              AND cache_id IN (
                SELECT cache_id
                FROM caches
                WHERE wp_oc IN ('$escapedCacheCodes')
              )
        ");
        
        $removedCount = $rs->rowCount(); // Get the number of affected rows
        
        $result = array( 
            'success'       => true,
            'removed_count' => $removedCount
        );

        return Okapi::formatted_response($request, $result);
    }
}

