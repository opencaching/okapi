<?php

namespace okapi\services\lists\remove_caches;

use okapi\core\Db;
use okapi\core\Okapi;
use okapi\core\Request\OkapiRequest;
use okapi\services\lists\ListsCommon;

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
        ListsCommon::require_lists();
        $list_id = ListsCommon::own_list_id($request->get_parameter('list_id'), $request->token->user_id);
        $codes = ListsCommon::parse_codes($request->get_parameter('cache_codes'));

        // Codes that are not on the list are skipped; removed_count says how many were removed.
        $ids = ListsCommon::cache_ids($codes, false);
        $removed = 0;
        if (count($ids) > 0) {
            $removed = Db::execute("
                delete from cache_list_items
                where cache_list_id = '".Db::escape_string($list_id)."'
                  and cache_id in ('".implode("','", array_map('intval', $ids))."')
            ");
        }

        $result = array('removed_count' => (int)$removed);
        return Okapi::formatted_response($request, $result);
    }
}
