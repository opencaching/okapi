<?php

namespace okapi\services\logs\images\delete;

use Exception;
use okapi\Okapi;
use okapi\Db;
use okapi\OkapiRequest;
use okapi\Settings;
use okapi\services\logs\images\LogImagesCommon;


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
        require_once('log_images_common.inc.php');

        list($image_uuid, $log_internal_id) = LogImagesCommon::validate_image_uuid($request);
        $image_uuid_escaped = Db::escape_string($image_uuid);

        Db::execute('start transaction');

        $local_image_url = Db::select_value("
            select url from pictures where uuid = '".$image_uuid_escaped."' and local
        ");
        Db::execute("
            delete from pictures where uuid = '".$image_uuid_escaped."'"
        );

        # Remember that OCPL picture sequence numbers are always 1, and
        # OCDE sequence numbers may have gaps. So we do not need to adjust
        # any numbers after deleting from table 'pictures'.

        if (Settings::get('OC_BRANCH') == 'oc.pl') {
            # This will also update cache_logs.okapi_syncbase, so that replication
            # can output the updated log entry with one image less. For OCDE
            # that's done by DB trigges.

            Db::execute("
                update cache_logs
                set picturescount = greatest(0, picturescount - 1)
                where id = '".Db::escape_string($log_internal_id)."'
            ");

            # It may make sense to update cache_logs.date_modified, too, but OCPL
            # code currently does NOT do that; see
            # https://github.com/opencaching/opencaching-pl/issues/341.
            # See also the corrresponding note in add.php and delete.php.
        }

        Db::execute('commit');

        if ($local_image_url) {
            $filename = basename($local_image_url);
            unlink(Settings::get('IMAGES_DIR').'/'.$filename);
        }

        $result = array(
            'success' => true,
        );
        return Okapi::formatted_response($request, $result);
    }
}
