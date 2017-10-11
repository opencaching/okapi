<?php

namespace okapi\views\devel\counterreport;

use okapi\core\Db;
use okapi\core\Response\OkapiHttpResponse;

class View
{
    public static function call()
    {
        # This is a hidden page for OKAPI developers. It will output diagnostic
        # data of the geocache view counter.
        #
        # FTODO: Change this to statistsical output for the final implementation,
        #        or discard it.

        $response = new OkapiHttpResponse();
        $response->content_type = "text/plain; charset=utf-8";
        ob_start();

        if (isset($_GET['hours']))
        {
            $rs = Db::query("
                select *
                from okapi_geocache_views
                where timestampdiff(minute, viewed_at, now()) < 60*".Db::escape_string(0 + $_GET['hours'])."
                order by viewed_at desc
                ");
            while ($row = Db::fetch_assoc($rs)) {
                print substr($row['viewed_at'], 11).' '.$row['cache_id'].' '.$row['anonymized_ip']."\n";
            }
            Db::free_result($rs);
        }

        $response->body = ob_get_clean();
        return $response;
    }
}
