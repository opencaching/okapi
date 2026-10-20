<?php

namespace okapi\services\lists\get_caches;

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
        $list_id = $request->get_parameter('list_id');

        if (empty($list_id)) {
            throw new InvalidParam('list_id', 'list_id is mandatory and must not be empty.');
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

        // Fetch cache_ids associated with the specified list
        $cache_ids_array = Db::select_column("
            SELECT cache_id
            FROM cache_list_items
            WHERE cache_list_id = '".Db::escape_string($list_id)."'
        ");

        $cache_count = count($cache_ids_array);

        // Fetch cache_codes based on cache_ids
        $cache_codes_array = array();

        if (!empty($cache_ids_array)) {
            $escaped_ids = implode(',', array_map('intval', $cache_ids_array));
            $cache_codes_array = Db::select_column(
                "SELECT wp_oc FROM caches WHERE cache_id IN ($escaped_ids)"
            );
        }

        $result = array(
            'success' => true,
            'cache_codes' => implode('|', $cache_codes_array),
            'cache_count' => $cache_count
        );

        return Okapi::formatted_response($request, $result);
    }
}
