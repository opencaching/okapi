<?php

namespace okapi\services\lists\update;

use okapi\core\Db;
use okapi\core\Exception\BadRequest;
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
        $list_id = ListsCommon::own_list_id($request->get_parameter('list_id'), $user_id);
        $current = ListsCommon::get_lists($user_id, $list_id);
        $current = $current[0];

        // Every parameter is optional; one that is left out keeps the list's current value.
        // An empty list_description removes the description.
        $name = $request->get_parameter('list_name');
        $description = $request->get_parameter('list_description');
        $status = $request->get_parameter('list_status');
        $password = $request->get_parameter('list_password');
        $watch = $request->get_parameter('list_watch');
        if ($name === null && $description === null && $status === null && $password === null && $watch === null)
            throw new BadRequest('Nothing to change: give at least one of list_name, list_description, list_status, list_password, list_watch.');

        $status = ($status === null) ? $current['status'] : ListsCommon::parse_status($status);
        $name = ($name === null) ? $current['name'] : trim($name);
        // Also checked when only the status changes: a public list needs a longer name.
        ListsCommon::check_name($name, $status, $user_id, $list_id);

        $set = array(
            "name = '".Db::escape_string($name)."'",
            "is_public = '".Db::escape_string($status)."'",
        );
        if ($description !== null)
            $set[] = "description = '".Db::escape_string(ListsCommon::description_html($description))."'";
        if ($status != 0)
            $set[] = "password = ''";
        elseif ($password !== null)
            $set[] = "password = '".Db::escape_string(ListsCommon::check_password($password))."'";
        Db::execute("
            update cache_lists set ".implode(', ', $set)."
            where id = '".Db::escape_string($list_id)."'
        ");

        if ($watch !== null) {
            if (ListsCommon::parse_bool('list_watch', $watch)) {
                Db::execute("
                    insert ignore into cache_list_watches (cache_list_id, user_id)
                    values ('".Db::escape_string($list_id)."', '".Db::escape_string($user_id)."')
                ");
            } else {
                Db::execute("
                    delete from cache_list_watches
                    where cache_list_id = '".Db::escape_string($list_id)."'
                      and user_id = '".Db::escape_string($user_id)."'
                ");
            }
        }

        $updated = ListsCommon::get_lists($user_id, $list_id);
        return Okapi::formatted_response($request, $updated[0]);
    }
}
