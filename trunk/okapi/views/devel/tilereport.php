<?php

namespace okapi\views\devel\tilereport;

use Exception;
use okapi\Okapi;
use okapi\Cache;
use okapi\Db;
use okapi\OkapiRequest;
use okapi\OkapiRedirectResponse;
use okapi\OkapiHttpResponse;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\OkapiServiceRunner;
use okapi\OkapiInternalRequest;
use okapi\cronjobs\CronJobController;

class View
{
	public static function call()
	{
		# Flush the stats, so the page is fresh upon every request.
		
		require_once($GLOBALS['rootpath']."okapi/cronjobs.php");
		CronJobController::force_run("StatsWriterCronJob");
		
		# When services/caches/map/tile method is called, it writes some extra
		# stats in the okapi_stats_hourly table. This page retrieves and
		# formats these stats in a readable manner (for debugging).
		
		$response = new OkapiHttpResponse();
		$response->content_type = "text/plain; charset=utf-8";
		ob_start();
		
		$start = isset($_GET['start']) ? $_GET['start'] : date(
			"Y-m-d 00:00:00", time() - 7*86400);
		$end = isset($_GET['end']) ? $_GET['end'] : date("Y-m-d 23:59:59");
		
		$rs = Db::query("
			select
				service_name,
				sum(total_calls),
				sum(total_runtime)
			from okapi_stats_hourly
			where
				period_start >= '".mysql_real_escape_string($start)."'
				and period_start < '".mysql_real_escape_string($end)."'
				and service_name like '%caches/map/tile%'
			group by service_name
		");
		
		$total_calls = 0;
		$total_runtime = 0.0;
		$etag_nonempty_hits = 0;
		$etag_nonempty_runtime = 0.0;
		$etag_empty_hits = 0;
		$etag_empty_runtime = 0.0;
		$imagecache_nonempty_hits = 0;
		$imagecache_nonempty_runtime = 0;
		$imagecache_empty_hits = 0;
		$imagecache_empty_runtime = 0;
		
		while (list($name, $calls, $runtime) = mysql_fetch_array($rs))
		{
			switch ($name)
			{
				case 'services/caches/map/tile':
					$total_calls = $calls;
					$total_runtime = $runtime;
					break;
				case 'extra/caches/map/tile/etag-hit':
					$etag_nonempty_hits = $calls;
					$etag_nonempty_runtime = $runtime;
					break;
				case 'extra/caches/map/tile/etag-hit-empty':
					$etag_empty_hits = $calls;
					$etag_empty_runtime = $runtime;
					break;
				case 'extra/caches/map/tile/imagecache-hit':
					$imagecache_nonempty_hits = $calls;
					$imagecache_nonempty_runtime = $runtime;
					break;
				case 'extra/caches/map/tile/imagecache-hit-empty':
					$imagecache_empty_hits = $calls;
					$imagecache_empty_runtime = $runtime;
					break;
				default:
					# Unknown key. Ingore.
					break;
			}
		}
		
		$calls_left = $total_calls;
		$runtime_left = $total_runtime;
		
		$perc = function($a, $b) { return ($b > 0) ? sprintf("%.1f", 100 * $a / $b)."%" : "(?)"; };
		$avg = function($a, $b) { return ($b > 0) ? sprintf("%.4f", $a / $b)."s" : "(?)"; };
		$get_stats = function() use (&$calls_left, &$runtime_left, &$total_calls, &$total_runtime, &$perc)
		{
			return (
				str_pad($perc($calls_left, $total_calls), 6, " ", STR_PAD_LEFT).
				str_pad($perc($runtime_left, $total_runtime), 7, " ", STR_PAD_LEFT)
			);
		};
		
		print "%CALLS  %TIME  Description\n";
		print "====== ======  ======================================================================\n";
		print $get_stats()."  $total_calls responses served. Total runtime: ".sprintf("%.2f", $total_runtime)."s\n";

		$etag_hits = $etag_nonempty_hits + $etag_empty_hits;
		$calls_left -= $etag_hits;
		$etag_runtime = $etag_nonempty_runtime + $etag_empty_runtime;
		$runtime_left -= $etag_runtime;
		
		print "\n";
		print "               All of these requests needed a TileTree lookup. The average runtime of\n";
		print "               these lookups is currently not included in the stats. Lookup results were\n";
		print "               then passed on to the TileRenderer which computed an ETag hash string.\n";
		print "               $etag_hits of the requests matched the ETag and were served a HTTP 304 response.\n";
		print "               (".$perc($etag_empty_hits, $etag_hits)." of them were the empty tile.)\n";
		print "               The average runtime of an ETag HTTP 304 response was ".$avg($etag_runtime, $etag_hits)."\n";
		print "               (empty ".$avg($etag_empty_runtime, $etag_empty_hits).", non-empty ".$avg($etag_nonempty_runtime, $etag_nonempty_hits).").\n";
		print "\n";
		print $get_stats()."  $calls_left calls continued to the next \"level\".\n";
		
		$imagecache_hits = $imagecache_nonempty_hits + $imagecache_empty_hits;
		$calls_left -= $imagecache_hits;
		$imagecache_runtime = $imagecache_nonempty_runtime + $imagecache_empty_runtime;
		$runtime_left -= $imagecache_runtime;

		print "\n";
		print "               $imagecache_hits of the rest hit the server image cache.\n";
		print "               (".$perc($imagecache_empty_hits, $imagecache_hits)." of them were the empty tile.)\n";
		print "               The average runtime of image-cache response was ".$avg($imagecache_runtime, $imagecache_hits)."\n";
		print "               (empty ".$avg($imagecache_empty_runtime, $imagecache_empty_hits).", non-empty ".$avg($imagecache_nonempty_runtime, $imagecache_nonempty_hits).").\n";
		print "\n";
		print $get_stats()."  $calls_left calls continued to the next \"level\".\n";
		print "\n";
		print "               The calls required the tile to be rendered. On average, it took\n";
		print "               ".$avg($runtime_left, $calls_left)." to *render* a tile.\n";
		print "\n";
		
		print "Average response time was ".$avg($total_runtime, $total_calls).".";
		$response->body = ob_get_clean();
		return $response;
	}
}
