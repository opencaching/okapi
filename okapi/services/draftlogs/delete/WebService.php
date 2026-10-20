<?php

namespace okapi\services\draftlogs\delete;

use okapi\core\Db;
use okapi\core\Exception\BadRequest;
use okapi\core\Exception\InvalidParam;
use okapi\core\Exception\ParamMissing;
use okapi\core\Okapi;
use okapi\core\Request\OkapiRequest;
use okapi\Settings;

class WebService
{
    # Upper limit of draft IDs per call.
    const MAX_DRAFT_IDS = 500;

    public static function options()
    {
        return array(
            'min_auth_level' => 3
        );
    }

    public static function call(OkapiRequest $request)
    {
        if (Settings::get('OC_BRANCH') != 'oc.de')
            throw new BadRequest('This method is not supported in this OKAPI installation. See the has_draft_logs field in services/apisrv/installation method.');

        $draft_ids = $request->get_parameter('draft_ids');
        if ($draft_ids === null || $draft_ids === '') throw new ParamMissing('draft_ids');

        $draft_ids = array_values(array_unique(explode('|', $draft_ids)));
        foreach ($draft_ids as $id) {
            if (!ctype_digit($id))
                throw new InvalidParam('draft_ids', "'$id' is not a valid draft ID.");
        }
        if (count($draft_ids) > self::MAX_DRAFT_IDS)
            throw new InvalidParam('draft_ids', 'Maximum allowed number of draft IDs is '.self::MAX_DRAFT_IDS.'.');

        # Like the website's own field notes page: a user can only delete their own drafts.
        $user_id = $request->token->user_id;
        $escaped_ids = "'".implode("','", array_map('okapi\core\Db::escape_string', $draft_ids))."'";
        $own_ids = Db::select_column("
            select id
            from field_note
            where id in ($escaped_ids)
            and user_id = '".Db::escape_string($user_id)."'
        ");
        $own_ids = array_map('strval', $own_ids);

        if ($own_ids) {
            Db::query("
                delete from field_note
                where id in ('".implode("','", array_map('okapi\core\Db::escape_string', $own_ids))."')
                and user_id = '".Db::escape_string($user_id)."'
            ");
        }

        $result = array(
            'success' => true,
            'deleted_draft_ids' => array_values(array_intersect($draft_ids, $own_ids)),
            'not_found_draft_ids' => array_values(array_diff($draft_ids, $own_ids)),
        );
        return Okapi::formatted_response($request, $result);
    }
}
