<?php

namespace okapi\services\lists\add_caches;

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

        // Like on the website, only caches the user can see go on a list: the ones OKAPI shows
        // (Available, Temporarily unavailable, Archived). Unknown codes and caches already on the
        // list are skipped; added_count says how many were added.
        $added = 0;
        foreach (ListsCommon::cache_ids($codes, true) as $cache_id) {
            $added += Db::execute("
                insert ignore into cache_list_items (cache_list_id, cache_id)
                values ('".Db::escape_string($list_id)."', '".Db::escape_string($cache_id)."')
            ");
        }

        $result = array('added_count' => $added);
        return Okapi::formatted_response($request, $result);
    }
}
