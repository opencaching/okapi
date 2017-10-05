<?php

namespace okapi\views\devel\cronreport;

use okapi\core\Cache;
use okapi\core\CronJob\CronJobController;
use okapi\core\Okapi;
use okapi\core\Response\OkapiHttpResponse;
use okapi\services\replicate\ReplicateCommon;

class View
{
    public static function call()
    {
        // This is a hidden page for OKAPI developers. It will output a cronjobs
        // report. This is useful for debugging.

        $response = new OkapiHttpResponse();
        $response->content_type = 'text/plain; charset=utf-8';
        ob_start();

        $schedule = Cache::get('cron_schedule');
        if ($schedule == null) {
            $schedule = array();
        }
        echo 'Nearest event: ';
        if (Okapi::get_var('cron_nearest_event')) {
            echo 'in '.(Okapi::get_var('cron_nearest_event') - time())." seconds.\n\n";
        } else {
            echo "NOT SET\n\n";
        }
        $cronjobs = CronJobController::get_enabled_cronjobs();
        usort($cronjobs, function ($a, $b) {
            $cmp = function ($a, $b) {
                return ($a < $b) ? -1 : (($a > $b) ? 1 : 0);
            };
            $by_type = $cmp($a->get_type(), $b->get_type());
            if ($by_type != 0) {
                return $by_type;
            }

            return $cmp($a->get_name(), $b->get_name());
        });
        echo str_pad('TYPE', 11).'  '.str_pad('NAME', 40)."  SCHEDULE\n";
        echo str_pad('----', 11).'  '.str_pad('----', 40)."  --------\n";
        foreach ($cronjobs as $cronjob) {
            $type = $cronjob->get_type();
            $name = $cronjob->get_name();
            echo str_pad($type, 11).'  '.str_pad($name, 40).'  ';
            if (!isset($schedule[$name])) {
                echo 'NOT YET SCHEDULED';
            } elseif ($schedule[$name] <= time()) {
                echo 'DELAYED: should be run '.(time() - $schedule[$name]).' seconds ago';
            } else {
                echo 'scheduled to run in '.str_pad($schedule[$name] - time(), 6, ' ', STR_PAD_LEFT).' seconds';
            }
            if (isset($schedule[$name])) {
                $delta = abs(time() - $schedule[$name]);
                if ($delta > 10 * 60) {
                    echo ' (';
                    echo substr(date('c', $schedule[$name]), 11, 8);
                    echo ', ';
                    $datestr = substr(date('c', $schedule[$name]), 0, 10);
                    $today = substr(date('c', time()), 0, 10);
                    $tomorrow = substr(date('c', time() + 86400), 0, 10);
                    if ($datestr == $today) {
                        echo 'today';
                    } elseif ($datestr == $tomorrow) {
                        echo 'tomorrow';
                    } else {
                        echo $datestr;
                    }
                    echo ')';
                }
            }
            echo "\n";
        }
        echo "\n";

        echo 'Crontab last ping: ';
        if (Cache::get('crontab_last_ping')) {
            echo(time() - Cache::get('crontab_last_ping')).' seconds ago';
        } else {
            echo 'NEVER';
        }
        echo ' (crontab_check_counter: '.Cache::get('crontab_check_counter').").\n";
        echo 'Debug clog_revisions_daily: ';
        if (Cache::get('clog_revisions_daily')) {
            $prev = null;
            foreach (Cache::get('clog_revisions_daily') as $time => $rev) {
                if ($prev != null) {
                    echo '(+'.($rev - $prev).') ';
                }
                echo "$rev ";
                $prev = $rev;
            }
            echo "\n";
        } else {
            echo "NULL\n";
        }
        echo 'Fulldump: '.ReplicateCommon::get_fulldump_status_message()."\n";

        $response->body = ob_get_clean();

        return $response;
    }
}
