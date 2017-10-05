<?php

namespace okapi\views\devel\tilereport;

use okapi\core\CronJob\CronJobController;
use okapi\core\Db;
use okapi\core\Okapi;
use okapi\core\Response\OkapiHttpResponse;

class View
{
    public static function call()
    {
        Okapi::require_developer_cookie();

        CronJobController::force_run('StatsWriterCronJob');

        // When services/caches/map/tile method is called, it writes some extra
        // stats in the okapi_stats_hourly table. This page retrieves and
        // formats these stats in a readable manner (for debugging).

        $response = new OkapiHttpResponse();
        $response->content_type = 'text/plain; charset=utf-8';
        ob_start();

        $start = isset($_GET['start']) ? $_GET['start'] : date(
            'Y-m-d 00:00:00', time() - 7 * 86400);
        $end = isset($_GET['end']) ? $_GET['end'] : date('Y-m-d 23:59:59');

        echo "From: $start\n";
        echo "  To: $end\n\n";

        $rs = Db::query("
            select
                service_name,
                sum(total_calls),
                sum(total_runtime)
            from okapi_stats_hourly
            where
                period_start >= '".Db::escape_string($start)."'
                and period_start < '".Db::escape_string($end)."'
                and service_name like '%caches/map/tile%'
            group by service_name
        ");

        $total_calls = 0;
        $total_runtime = 0.0;
        $calls = array('A' => 0, 'B' => 0, 'C' => 0, 'D' => 0);
        $runtime = array('A' => 0.0, 'B' => 0.0, 'C' => 0.0, 'D' => 0.0);

        while (list($name, $c, $r) = Db::fetch_row($rs)) {
            if ($name == 'services/caches/map/tile') {
                $total_calls = $c;
                $total_runtime = $r;
            } elseif (strpos($name, 'extra/caches/map/tile/checkpoint') === 0) {
                $calls[$name[32]] = $c;
                $runtime[$name[32]] = $r;
            }
        }
        if ($total_calls != $calls['A']) {
            echo 'Partial results. Only '.$calls['A']." out of $total_calls are covered.\n";
            echo "All other will count as \"unaccounted for\".\n\n";
            $total_calls = $calls['A'];
        }

        $calls_left = $total_calls;
        $runtime_left = $total_runtime;

        $perc = function ($a, $b) {
            return ($b > 0) ? sprintf('%.1f', 100 * $a / $b).'%' : '(?)';
        };
        $avg = function ($a, $b) {
            return ($b > 0) ? sprintf('%.4f', $a / $b).'s' : '(?)';
        };
        $get_stats = function () use (&$calls_left, &$runtime_left, &$total_calls, &$total_runtime, &$perc) {
            return
                str_pad($perc($calls_left, $total_calls), 6, ' ', STR_PAD_LEFT).
                str_pad($perc($runtime_left, $total_runtime), 7, ' ', STR_PAD_LEFT)
            ;
        };

        echo "%CALLS  %TIME  Description\n";
        echo "====== ======  ======================================================================\n";
        echo $get_stats()."  $total_calls responses served. Total runtime: ".sprintf('%.2f', $total_runtime)."s\n";
        echo "\n";
        echo "               All of these requests needed a TileTree build/lookup. The average runtime of\n";
        echo '               these lookups was '.$avg($runtime['A'], $total_calls).'. '.$perc($runtime['A'], $total_runtime)." of total runtime was spent here.\n";
        echo "\n";

        $runtime_left -= $runtime['A'];

        echo $get_stats().'  All calls passed here after ~'.$avg($runtime['A'], $total_calls)."\n";

        echo "\n";
        echo "               Lookup result was then processed and \"image description\" was created. It was\n";
        echo "               passed on to the TileRenderer to compute the ETag hash string. The average runtime\n";
        echo '               of this part was '.$avg($runtime['B'], $total_calls).'. '.$perc($runtime['B'], $total_runtime)." of total runtime was spent here.\n";
        echo "\n";

        $runtime_left -= $runtime['B'];

        echo $get_stats().'  All calls passed here after ~'.$avg($runtime['A'] + $runtime['B'], $total_calls)."\n";

        $etag_hits = $calls['B'] - $calls['C'];

        echo "\n";
        echo "               $etag_hits of the requests matched the ETag and were served an HTTP 304 response.\n";
        echo "\n";

        $calls_left = $calls['C'];

        echo $get_stats()."  $calls_left calls passed here after ~".$avg($runtime['A'] + $runtime['B'], $total_calls)."\n";

        $imagecache_hits = $calls['C'] - $calls['D'];

        echo "\n";
        echo "               $imagecache_hits of these calls hit the server image cache.\n";
        echo '               '.$perc($runtime['C'], $total_runtime)." of total runtime was spent to find these.\n";
        echo "\n";

        $calls_left = $calls['D'];
        $runtime_left -= $runtime['C'];

        echo $get_stats()."  $calls_left calls passed here after ~".$avg($runtime['A'] + $runtime['B'] + $runtime['C'], $total_calls)."\n";
        echo "\n";
        echo "               These calls required the tile to be rendered. On average, it took\n";
        echo '               '.$avg($runtime['D'], $calls['D'])." to *render* a tile.\n";
        echo '               '.$perc($runtime['D'], $total_runtime)." of total runtime was spent here.\n";
        echo "\n";

        $runtime_left -= $runtime['D'];

        echo $perc($runtime_left, $total_runtime)." of runtime was unaccounted for (other processing).\n";
        echo 'Average response time was '.$avg($total_runtime, $total_calls).".\n\n";

        echo "Current okapi_cache score distribution:\n";
        $rs = Db::query('
            select floor(log2(score)), count(*), sum(length(value))
            from okapi_cache
            where score is not null
            group by floor(log2(score))
        ');
        while (list($log2, $count, $size) = Db::fetch_row($rs)) {
            echo $count." elements ($size bytes) with score between ".pow(2, $log2).' and '.pow(2, $log2 + 1).".\n";
        }

        $response->body = ob_get_clean();

        return $response;
    }
}
