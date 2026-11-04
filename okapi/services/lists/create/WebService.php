<?php

namespace okapi\services\lists\create;

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
        $user_id = $request->token->user_id;

        $status = $request->get_parameter('list_status');
        $status = ($status === null) ? 0 : ListsCommon::parse_status($status);
        $name = trim((string)$request->get_parameter('list_name'));
        ListsCommon::check_name($name, $status, $user_id);
        $description = ListsCommon::description_html((string)$request->get_parameter('list_description'));
        // The website keeps a password only for private lists (it lets others open the list).
        $password = ($status == 0) ? ListsCommon::check_password((string)$request->get_parameter('list_password')) : '';
        $watch = $request->get_parameter('list_watch');
        $watch = ($watch === null) ? false : ListsCommon::parse_bool('list_watch', $watch);

        // uuid, date_created and last_modified are set by OCDE's triggers. description and password
        // are NOT NULL without a default.
        Db::execute("
            insert into cache_lists (user_id, name, is_public, description, password)
            values (
                '".Db::escape_string($user_id)."',
                '".Db::escape_string($name)."',
                '".Db::escape_string($status)."',
                '".Db::escape_string($description)."',
                '".Db::escape_string($password)."'
            )
        ");
        $list_id = (int)Db::last_insert_id();
        if ($watch) {
            Db::execute("
                insert ignore into cache_list_watches (cache_list_id, user_id)
                values ('".Db::escape_string($list_id)."', '".Db::escape_string($user_id)."')
            ");
        }

        $result = array('list_id' => $list_id);
        return Okapi::formatted_response($request, $result);
    }
}
