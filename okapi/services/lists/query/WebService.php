<?php

namespace okapi\services\lists\query;

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
        $include_caches = $request->get_parameter('include_caches');
        $include_caches = ($include_caches === null) ? false : ListsCommon::parse_bool('include_caches', $include_caches);

        $lists = ListsCommon::get_lists($request->token->user_id, null, $include_caches);
        return Okapi::formatted_response($request, $lists);
    }
}
