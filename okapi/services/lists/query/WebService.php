<?php

namespace okapi\services\lists\query;

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
            'success' => false   // if the installation doesn't support it
        );

        if (Settings::get('OC_BRANCH') == 'oc.de')
        {
            $user_id = $request->token->user_id;
            $rs = Db::query("
                SELECT
                    id,
                    name,
                    date_created,
                    last_modified,
                    last_added,
                    description,
                    is_public,
                    (
                        SELECT COUNT(*)
                        FROM cache_list_items
                        WHERE cache_list_id = cache_lists.id
                    ) AS caches_count,
                    (
                        SELECT COUNT(*)
                        FROM cache_list_watches
                        WHERE cache_list_id = cache_lists.id
                    ) AS watches_count
                FROM cache_lists
                WHERE user_id = '".Db::escape_string($user_id)."'
            ");

            $lists = [];
            while ($list = Db::fetch_assoc($rs))
            {
                $lists[] = $list;
            }

            $result = json_encode($lists, JSON_PRETTY_PRINT);
        }
        return Okapi::formatted_response($request, $result);
    }


    // ------------------------------------------------------------------

}
