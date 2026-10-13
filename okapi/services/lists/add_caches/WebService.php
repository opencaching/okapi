<?php

namespace okapi\services\lists\add_caches;

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
            throw new BadRequest('This method is not supported in this OKAPI installation.');

        $user_id = $request->token->user_id;

        $list_id     = $request->get_parameter('list_id');
        $cache_codes = $request->get_parameter('cache_codes');

        if (empty($list_id)) {
            throw new InvalidParam('list_id', 'list_id is mandatory and must not be empty.');
        }

        if (empty($cache_codes)) {
            throw new InvalidParam('cache_codes', 'cache_codes is mandatory and must not be empty.');
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

        $cache_codes_array = array_unique(explode('|', $cache_codes));

        // Check the length
        if (count($cache_codes_array) > 500) {
            throw new InvalidParam('cache_codes', 'The number of cache codes exceeds the limit of 500.');
        }

        // Escape cache codes and build the SQL query
        $escaped_cache_codes = implode("','", array_map('\okapi\core\Db::escape_string', $cache_codes_array));

        // Fetch cache_ids from the caches table using INSERT IGNORE
        $rs = Db::query("
            INSERT IGNORE INTO cache_list_items (cache_list_id, cache_id)
            SELECT '".Db::escape_string($list_id)."', cache_id
            FROM caches
            WHERE wp_oc IN ('$escaped_cache_codes')
        ");

        $inserted_count = $rs->rowCount();

        $result = array(
            'success'     => true,
            'added_count' => $inserted_count
        );

        return Okapi::formatted_response($request, $result);
    }
}
