<?php

namespace okapi\services\lists\get_caches;

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

        $codes = Db::select_column("
            select c.wp_oc
            from cache_list_items cli
            join caches c on c.cache_id = cli.cache_id
            where cli.cache_list_id = '".Db::escape_string($list_id)."'
            order by c.wp_oc
        ");

        $result = array(
            'cache_codes' => $codes,
            'cache_count' => count($codes),
        );
        return Okapi::formatted_response($request, $result);
    }
}
