<?php

namespace okapi\core\CronJob;

use okapi\core\Db;

/** Removes outdated diagnostics records. */
class DiagnosticsCleanerJob extends Cron5Job
{
    # There may be high frequency data with short expiration time,
    # so let's clean up every hour.

    public function get_period() { return 3600; }

    public function execute()
    {
        Db::execute("
            delete from okapi_diagnostics
            where expires < now()
        ");
    }
}
