<?

namespace okapi\cronjobs;

use okapi\Okapi;
use okapi\Db;
use okapi\Cache;

class CronJobController
{
	/** Return the list of all currently enabled cronjobs. */
	public static function get_enabled_cronjobs()
	{
		static $cache = null;
		if ($cache == null)
			$cache = array(
				new OAuthCleanupCronJob(),
				new CacheCleanupCronJob(),
				new StatsWriterCronJob(),
			);
		return $cache;
	}
	
	/**
	 * Execute all scheduled, reschedule, and return UNIX timestamp of the
	 * nearest scheduled event.
	 */
	public static function run()
	{
		$schedule = Cache::get("cron_schedule");
		if ($schedule == null)
			$schedule = array();
		foreach (self::get_enabled_cronjobs() as $cronjob)
		{
			$name = $cronjob->get_name();
			if ((!isset($schedule[$name])) || ($schedule[$name] <= time()))
			{
				$cronjob->execute();
				$schedule[$name] = time() + $cronjob->get_period();
			}
		}
		$nearest = time() + 3600;
		foreach ($schedule as $name => $time)
			if ($time < $nearest)
				$nearest = $time;
		Cache::set("cron_schedule", $schedule, 86400);
		return $nearest;
	}
}

abstract class CronJob
{
	/** 
	 * Return number of seconds - period of time after which cronjob execution
	 * should be repeated. Please note, that current implementation is invoked by
	 * HTTP requests only and cronjobs cannot be executed at their exact planned
	 * times!
	 */
	public abstract function get_period();
	
	/** Run the job. */
	public abstract function execute();
	
	/** Get unique name for this cronjob. */
	public function get_name() { return get_class($this); }
}

/**
 * Deletes old Request Tokens and Nonces every 5 minutes. This is required for
 * OAuth to run safely.
 */
class OAuthCleanupCronJob extends CronJob
{
	public function get_period() { return 300; } # 5 minutes
	public function execute()
	{
		if (Okapi::$data_store)
			Okapi::$data_store->cleanup();
	}
}

/** Deletes all expired cache elements, once per hour. */
class CacheCleanupCronJob extends CronJob
{
	public function get_period() { return 3600; } # 1 hour
	public function execute()
	{
		Db::execute("
			delete from okapi_cache
			where expires < now()
		");
	}
}

/** Reads temporary (fast) stats-tables and reformats them into more permanent structures. */
class StatsWriterCronJob extends CronJob
{
	public function get_period() { return 60; } # 1 minute
	public function execute()
	{
		if (Okapi::get_var('db_version', 0) + 0 < 32)
			return;
		Db::query("lock tables okapi_stats_hourly write, okapi_stats_temp write;");
		$rs = Db::query("
			select
				consumer_key,
				user_id,
				concat(substr(`datetime`, 1, 13), ':00:00') as period_start,
				service_name,
				calltype,
				count(*) as calls,
				sum(runtime) as runtime
			from okapi_stats_temp
			group by substr(`datetime`, 1, 13), consumer_key, user_id, service_name, calltype
		");
		while ($row = mysql_fetch_assoc($rs))
		{
			Db::execute("
				insert into okapi_stats_hourly (consumer_key, user_id, period_start, service_name,
					total_calls, http_calls, total_runtime, http_runtime)
				values (
					'".mysql_real_escape_string($row['consumer_key'])."',
					'".mysql_real_escape_string($row['user_id'])."',
					'".mysql_real_escape_string($row['period_start'])."',
					'".mysql_real_escape_string($row['service_name'])."',
					".$row['calls'].",
					".(($row['calltype'] == 'http') ? $row['calls'] : 0).",
					".$row['runtime'].",
					".(($row['calltype'] == 'http') ? $row['runtime'] : 0)."
				)
				on duplicate key update
					".(($row['calltype'] == 'http') ? "
						http_calls = http_calls + ".$row['calls'].",
						http_runtime = http_runtime + ".$row['runtime'].",
					" : "")."
					total_calls = total_calls + ".$row['calls'].",
					total_runtime = total_runtime + ".$row['runtime']."
			");
		}
		Db::execute("delete from okapi_stats_temp;");
		Db::execute("unlock tables;");
	}
}
