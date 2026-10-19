<?php

namespace okapi\services\caches\delete;

use okapi\core\Db;
use okapi\Settings;
use okapi\core\Exception\BadRequest;
use okapi\core\Exception\InvalidParam;
use okapi\core\Okapi;
use okapi\core\Request\OkapiRequest;

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
        # Writes the OCDE database layout; other installations would fail half-way.
        if (Settings::get('OC_BRANCH') != 'oc.de')
            throw new BadRequest('This method is not supported in this OKAPI installation. See the has_cache_editing field in services/apisrv/installation method.');

        $user_id = $request->token->user_id;

        $cache_code = $request->get_parameter('cache_code');
        if (empty($cache_code))
            throw new InvalidParam('cache_code', 'cache_code is mandatory.');

        # Fetch cache and verify ownership
        $cache = Db::select_row("
            SELECT cache_id, user_id, status
            FROM caches
            WHERE wp_oc = '".Db::escape_string($cache_code)."'
        ");

        if (!$cache)
            throw new InvalidParam('cache_code', 'This cache does not exist.');

        if ($cache['user_id'] != $user_id)
            throw new BadRequest("Only the cache owner can archive this cache.");

        $archived_id = Okapi::cache_status_name2id('Archived'); # = 3

        if ($cache['status'] == $archived_id) {
            $result = array(
                'success' => true,
                'message' => 'Cache was already archived.',
            );
            return Okapi::formatted_response($request, $result);
        }

        # Set status to Archived. The DB triggers (cachesBeforeUpdate/cachesAfterUpdate)
        # handle last_modified, listing_last_modified, search index updates,
        # and notifications automatically.
        Db::execute("
            UPDATE caches
            SET status = '".Db::escape_string($archived_id)."'
            WHERE cache_id = '".Db::escape_string($cache['cache_id'])."'
        ");

        $result = array(
            'success' => true,
            'message' => 'Cache has been archived. Log entries remain in the system.',
        );
        return Okapi::formatted_response($request, $result);
    }
}
