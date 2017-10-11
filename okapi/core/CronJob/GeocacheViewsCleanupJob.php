<?php

namespace okapi\core\CronJob;

use okapi\core\Db;

/**
 * Discard old geocache-visit-counter evaluation data.
 * FTODO: Change this to cleanup of filtering data.
 */
class GeocacheViewsCleanupJob extends Cron5Job
{
    public function get_period() { return 3600; }
    public function execute()
    {
        Db::query("delete from okapi_geocache_views where timestampdiff(minute, viewed_at, now()) >= 60*24");
    }
}
