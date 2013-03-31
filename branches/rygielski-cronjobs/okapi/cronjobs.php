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
		{
			$cache = array(
				new OAuthCleanupCronJob(),
				new CacheCleanupCronJob(),
				new StatsWriterCronJob(),
			);
			foreach ($cache as $cronjob)
				if (!in_array($cronjob->get_type(), array('before-request', 'after-response')))
					throw new Exception("Cronjob '".$cronjob->get_name()."' has an invalid (unsupported) type.");
		}
		return $cache;
	}
	
	/**
	 * Execute all scheduled cronjobs of given type, reschedule, and return
	 * UNIX timestamp of the nearest scheduled event.
	 */
	public static function run_jobs($type)
	{
		$schedule = Cache::get("cron_schedule");
		if ($schedule == null)
			$schedule = array();
		foreach (self::get_enabled_cronjobs() as $cronjob)
		{
			if ($cronjob->get_type() != $type)
				continue;
			$name = $cronjob->get_name();
			if ((!isset($schedule[$name])) || ($schedule[$name] <= time()))
			{
				$cronjob->execute();
				$schedule[$name] = $cronjob->get_next_scheduled_run(isset($schedule[$name]) ? $schedule[$name] : time());
			}
		}
		$nearest = time() + 3600;
		foreach ($schedule as $name => $time)
			if ($time < $nearest)
				$nearest = $time;
		Cache::set("cron_schedule", $schedule, 30*86400);
		return $nearest;
	}
}

abstract class CronJob
{
	/** Run the job. */
	public abstract function execute();
	
	/** Get unique name for this cronjob. */
	public function get_name() { return get_class($this); }
	
	/**
	 * Get the type of this cronjob. Currently there are two: 'before-request'
	 * and 'after-response'. If you're not sure which to use, use the 'after-response'
	 * type.
	 */
	public abstract function get_type();
	
	/**
	 * Get the next scheduled run (unix timestamp). You may assume this function
	 * will be called ONLY directly after the job was run. You may use this to say,
	 * for example, "run the job before first request made after midnight".
	 */
	public abstract function get_next_scheduled_run($previously_scheduled_run);
}

/**
 * Simplified CronJob instance. Implementor must specify a minimum time period
 * that should pass between running a job. If job was run at time X, then it will
 * be run again just before the first request made after X+period. The job also
 * will be run after server gets updated.
 */
abstract class PeriodicalCronJob extends CronJob
{
	/** 
	 * Return number of seconds - period of time after which cronjob execution
	 * should be repeated. Please note, that current implementation is invoked by
	 * HTTP requests only and cronjobs cannot be executed at their exact planned
	 * times!
	 */
	public abstract function get_period();
	
	public function get_next_scheduled_run($previously_scheduled_run)
	{
		return time() + $this->get_period();
	}
}

/**
 * Deletes old Request Tokens and Nonces every 5 minutes. This is required for
 * OAuth to run safely.
 */
class OAuthCleanupCronJob extends PeriodicalCronJob
{
	public function get_type() { return 'before-request'; }
	public function get_period() { return 300; } # 5 minutes
	public function execute()
	{
		if (Okapi::$data_store)
			Okapi::$data_store->cleanup();
	}
}

/** Deletes all expired cache elements, once per hour. */
class CacheCleanupCronJob extends PeriodicalCronJob
{
	public function get_type() { return 'after-response'; }
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
class StatsWriterCronJob extends PeriodicalCronJob
{
	public function get_type() { return 'after-response'; }
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
