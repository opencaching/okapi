<?php

namespace okapi\services\logs\delete;

use okapi\core\Db;
use okapi\core\exception\BadRequest;
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

    static function call(OkapiRequest $request)
    {
        # handle log_uuid

        $log = LogsCommon::process_log_uuid($request);
        if ($log['user']['internal_id'] != $request->token->user_id)
            throw new BadRequest("Only own log entries can be deleted via OKAPI.");

        # delete log images
        #
        # IMPORTANT NOTE: So far, only own logs can be deleted. If we enable
        # deleting other user's logs by cache owners or OC admins, OCDE image
        # archiving must be implemented in services/logs/images/delete!
        # This enables OCDE admins to fully restore "owner-vandalized" caches
        # including deleted logs.

        foreach (array_reverse($log['images']) as $image) {
            $cache = OkapiServiceRunner::call(
                'services/logs/images/delete',
                new OkapiInternalRequest($request->consumer, $request->token, array(
                    'image_uuid' => $image['uuid']
                ))
            );
        }

        # delete log entry and update dependent data

        Db::execute('start transaction');

        if (Settings::get('OC_BRANCH') == 'oc.de')
        {
            # OCDE moves deleted logs to a separate archiving table.
            # cache_logs.last_modified is updated via trigger.
            #
            # As in native OCDE code, deletion is implemented MyISAM-safe here.
            # I.e. it will not break OKAPI replication if the 'delete' SQL
            # throws an exception in a non-transactional environment. 

            Db::execute("
                insert ignore into cache_logs_archived
                select
                    *,
                    '0' as deletion_date,
                    '".Db::escape_string($request->token->user_id)."'as deleted_by,
                    0 as restored_by
                from cache_logs
                where cache_logs.id = '".Db::escape_string($log['internal_id'])."'
            ");
            Db::execute("
                delete from cache_logs
                where id = '".Db::escape_string($log['internal_id'])."'
            ");

            # Now tell OKAPI replication about the deletion.
            # This will trigger another okapi_syncbase update.

            Db::execute("
                update cache_logs_archived
                set deletion_date = now()
                where id = '".Db::escape_string($log['internal_id'])."'
            ");
        }
        else
        {
            # OCPL just marks deleted logs

            Db::execute("
                update cache_logs
                    set deleted = 1,
                    del_by_user_id = '".Db::escape_string($request->token->user_id)."',
                    last_modified = now(),
                    last_deleted = now()
                where id = '".Db::escape_string($log['internal_id'])."'
            ");

            # Theoretically, a 'removed_objects' table entry should be created
            # now (OCDE does that via trigger). This would be needed for the
            # old "XML Interface" replication. But OCPL "XML" log entry
            # replication is FUBAR, so we omit that, as in current native OCPL
            # code.
        }

        # Finalize the transaction.

        LogsCommon::update_statistics_after_change("", null, $log);
        Db::execute("commit");
        LogsCommon::update_statpic("", $log['type'], $log['user']['internal_id']);

        Okapi::update_user_activity($request);
        $result = array(
            # Currently nothing can wrong - there are no restriction on deleting
            # own logs. But that may change in the future, or we me add other
            # deletion options that are restricted.

            'success' => true,
            'message' => _("Your log entry has been deleted.")
        );
        return Okapi::formatted_response($request, $result);
    }
}
