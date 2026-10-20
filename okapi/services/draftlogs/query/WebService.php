<?php

namespace okapi\services\draftlogs\query;

use okapi\core\Db;
use okapi\core\Exception\BadRequest;
use okapi\core\Exception\InvalidParam;
use okapi\core\Okapi;
use okapi\core\OkapiServiceRunner;
use okapi\core\Request\OkapiInternalRequest;
use okapi\core\Request\OkapiRequest;
use okapi\Settings;

class WebService
{
    # field_note.type => [OKAPI log type, needs_maintenance2]; the reverse of the
    # mapping in services/draftlogs/upload_fieldnotes (oc-server3: Oc\FieldNotes\Enum\LogType).
    private static $draft_types = [
        1    => ['Found it', false],
        2    => ["Didn't find it", false],
        3    => ['Comment', false],
        1000 => ['Comment', true],
    ];

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

        $user_id = $request->token->user_id;
        $where_cache = '';

        $cache_code = $request->get_parameter('cache_code');
        if ($cache_code !== null) {
            try {
                $geocache = OkapiServiceRunner::call(
                    'services/caches/geocache',
                    new OkapiInternalRequest($request->consumer, $request->token, array(
                        'cache_code' => $cache_code,
                        'fields' => 'internal_id'
                    ))
                );
            } catch (\Exception $e) {
                throw new InvalidParam('cache_code', 'There is no such geocache.');
            }
            $where_cache = "and field_note.geocache_id = '".Db::escape_string($geocache['internal_id'])."'";
        }

        $rows = Db::select_all("
            select field_note.id, field_note.type, field_note.date, field_note.text, caches.wp_oc
            from field_note
            join caches on caches.cache_id = field_note.geocache_id
            where field_note.user_id = '".Db::escape_string($user_id)."'
            $where_cache
            order by field_note.date, field_note.id
        ");

        $results = array();
        foreach ($rows as $row) {
            list($type, $needs_maintenance2) = self::$draft_types[$row['type']] ?? ['Comment', false];
            $results[] = array(
                'draft_id' => (string)$row['id'],
                'cache_code' => $row['wp_oc'],
                'type' => $type,
                'needs_maintenance2' => $needs_maintenance2,
                'date' => date('c', strtotime($row['date'])),
                'comment' => $row['text'],
            );
        }
        return Okapi::formatted_response($request, $results);
    }
}
