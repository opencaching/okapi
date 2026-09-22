<?php

namespace okapi\services\lists\delete;

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
            throw new BadRequest('This method is not supported in this OKAPI installation. See the has_lists field in services/apisrv/installation method.');

        $user_id = $request->token->user_id;

            $list_id = $request->get_parameter('list_id');

            if (empty($list_id) || !is_numeric($list_id)) {
                throw new InvalidParam('list_id', 'list_id is mandatory and must be numeric.');
            }

            // Check if the list exists and belongs to the user
            $count = Db::select_value("
                SELECT COUNT(*)
                FROM cache_lists
                WHERE id = '".Db::escape_string($list_id)."'
                  AND user_id = '".Db::escape_string($user_id)."'
            ");
            if ($count == 0) {
                throw new InvalidParam('list_id', 'The specified list does not exist.');
            }

            // Delete child records before parent to avoid FK constraint issues
            Db::query("DELETE FROM cache_list_items WHERE cache_list_id = '".Db::escape_string($list_id)."'");
            Db::query("DELETE FROM cache_list_watches WHERE cache_list_id = '".Db::escape_string($list_id)."'");
            Db::query("DELETE FROM cache_lists WHERE id = '".Db::escape_string($list_id)."'");

        $result = array(
            'success' => true,
            'message' => 'Cache list deleted successfully.'
        );
        return Okapi::formatted_response($request, $result);
    }
}
