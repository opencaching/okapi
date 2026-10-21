<?php

namespace okapi\services\lists\delete;

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

        // The caches themselves are not touched.
        Db::execute("delete from cache_list_items where cache_list_id = '".Db::escape_string($list_id)."'");
        Db::execute("delete from cache_list_watches where cache_list_id = '".Db::escape_string($list_id)."'");
        Db::execute("delete from cache_lists where id = '".Db::escape_string($list_id)."'");

        $result = array('list_id' => $list_id);
        return Okapi::formatted_response($request, $result);
    }
}
