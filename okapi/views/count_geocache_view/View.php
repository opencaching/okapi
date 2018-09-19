<?php

namespace okapi\views\count_geocache_view;

use okapi\core\Db;
use okapi\core\Response\OkapiHttpResponse;

class View
{
    public static function call()
    {
        if (isset($_GET['code']))
        {
            $cache_id = Db::select_value("
                select cache_id from caches where wp_oc='".Db::escape_string($_GET['code'])."'
            ");
            if ($cache_id !== null) {
                # This is just for evaluation of the counting-via-img implementation.
                # FTODO: implement filtering and counting

                Db::query("
                    insert into okapi_geocache_views
                    (cache_id, anonymized_ip, viewed_at)
                    values (".$cache_id.", ".crc32($_SERVER["REMOTE_ADDR"]).", now())
                ");
            }
        }

        $response = new OkapiHttpResponse();
        $response->content_type = "image/png";
        $response->body = file_get_contents(__DIR__.'/../../static/viewcounter-dummy.png');

        return $response;
    }
}
