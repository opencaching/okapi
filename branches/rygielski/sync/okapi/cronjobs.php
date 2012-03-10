<?

namespace okapi\cronjobs;

use Exception;
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
				new CheckCronTab1(),
				new CheckCronTab2(),
				new ChangeLogWriterJob(),
			);
			# If you want fulldump generated for your development machine, comment
			# the 'if' out.
			if ((!isset($GLOBALS['debug_page'])) || (!$GLOBALS['debug_page']))
				$cache[] = new FulldumpGeneratorJob();
			foreach ($cache as $cronjob)
				if (!in_array($cronjob->get_type(), array('pre-request', 'cron-5')))
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
		# Get lock. If lock cannot be acquired, then probably other process is running
		# cronjobs already. We'll just return in this case, and let the other process finish.
		
		$lock_name = 'okapi-cronjobs-'.$type;
		$lock = Db::select_value("select get_lock('$lock_name', 0)");
		if (!$lock)
			return time() - 1; # force to check it again on next request or crontab event

		$schedule = Cache::get("cron_schedule");
		if ($schedule == null)
			$schedule = array();
		foreach (self::get_enabled_cronjobs() as $cronjob)
		{
			$name = $cronjob->get_name();
			if ((!isset($schedule[$name])) || ($schedule[$name] <= time()))
			{
				if ($cronjob->get_type() != $type)
				{
					$next_run = time() - 1;
				}
				else
				{
					$cronjob->execute();
					$next_run = $cronjob->get_next_scheduled_run(isset($schedule[$name]) ? $schedule[$name] : time());
				}
				$schedule[$name] = $next_run;
			}
		}
		$nearest = time() + 3600;
		foreach ($schedule as $name => $time)
			if ($time < $nearest)
				$nearest = $time;
		Cache::set("cron_schedule", $schedule, 30*86400);
		Db::select_value("select release_lock('$lock_name')");
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
	 * Get the type of this cronjob. Currently there are two: 'pre-request'
	 * and 'cron-5'. The first can be executed before every request, the second
	 * is executed from system's crontab, as a separate process. 'cron-5' can be
	 * executed every 5 minutes, or every 10, 15 etc. minutes. 'pre-request'
	 * can be executed before each HTTP request, AND additionally every 5 minutes
	 * (before 'cron-5' runs).
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
 * CronJob which is run before requests. All implenatations specify a *minimum* time period
 * that should pass between running a job. If job was run at time X, then it will
 * be run again just before the first request made after X+period. The job also
 * will be run after server gets updated.
 */
abstract class PrerequestCronJob extends CronJob
{
	/**
	 * Always returns 'pre-request'.
	 */
	public final function get_type() { return 'pre-request'; }

	/** 
	 * Return number of seconds - a *minimum* time period that should pass between
	 * running the job.
	 */
	public abstract function get_period();
	
	public function get_next_scheduled_run($previously_scheduled_run)
	{
		return time() + $this->get_period();
	}
}

/**
 * CronJob which is run from crontab. It may be invoked every 5 minutes, or
 * every 10, 15 etc. Hence the name - cron-5.
 */
abstract class Cron5Job extends CronJob
{
	/**
	 * Always returns 'cron-5'.
	 */
	public final function get_type() { return 'cron-5'; }
	
	/** 
	 * Return number of seconds - period of time after which cronjob execution
	 * should be repeated. This should be dividable be 300 (5 minutes).
	 */
	public abstract function get_period();
	
	public function get_next_scheduled_run($previously_scheduled_run)
	{
		$t = $previously_scheduled_run + $this->get_period();
		return ($t - ($t % 300));
	}
}


/**
 * Deletes old Request Tokens and Nonces every 5 minutes. This is required for
 * OAuth to run safely.
 */
class OAuthCleanupCronJob extends PrerequestCronJob
{
	public function get_period() { return 300; } # 5 minutes
	public function execute()
	{
		if (Okapi::$data_store)
			Okapi::$data_store->cleanup();
	}
}

/** Deletes all expired cache elements, once per hour. */
class CacheCleanupCronJob extends Cron5Job
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
class StatsWriterCronJob extends PrerequestCronJob
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

/**
 * Once per hour, put a test entry in the database. This is to make sure
 * that crontab is set up properly.
 */
class CheckCronTab1 extends Cron5Job
{
	public function get_period() { return 3600; }
	public function execute()
	{
		Cache::set('crontab_last_ping', time(), 86400);
	}
}

/**
 * Twice an hour, upon request, check if the test entry in the
 * database is up-to-date (the one which was saved by CheckCronTab1 job).
 */
class CheckCronTab2 extends PrerequestCronJob
{
	public function get_period() { return 30 * 60; }
	public function execute()
	{
		$last_ping = Cache::get('crontab_last_ping', time() - 86400); # if not set, assume 1 day ago.
		if ($last_ping > time() - 3600)
		{
			# There was a ping during the last hour. Everything is okay.
			# Reset the counter and return.
			
			Cache::set('crontab_check_counter', 3, 86400);
			return;
		}
		
		# There was no ping. Decrement the counter. When reached zero, alert.
		
		$counter = Cache::get('crontab_check_counter', 3);
		$counter--;
		if ($counter > 0)
		{
			Cache::set('crontab_check_counter', $counter, 86400);
		}
		elseif ($counter == 0)
		{
			Okapi::mail_admins(
				"Crontab not working.",
				"Hello. OKAPI detected, that it's crontab is not working properly.\n".
				"Please check your configuration or contact OKAPI developers.\n\n".
				"This line should be present among your crontab entries:\n\n".
				"*/5 * * * * wget -O - -q -t 1 ".$GLOBALS['absolute_server_URI']."okapi/cron5"
			);
			
			# Schedule the next admin-nagging. Each subsequent notification will be sent
			# with a greater delay.
			
			$since_last = time() - $last_ping;
			Cache::set('crontab_check_counter', (int)($since_last / $this->get_period()), 86400);
		}
	}
}

/**
 * Once per 10 minutes, search for changes in the database and update the changelog.
 */
class ChangeLogWriterJob extends Cron5Job
{
	public function get_period() { return 600; }
	public function execute()
	{
		require_once 'services/dbsync/common.inc.php';
		\okapi\services\dbsync\common\SyncCommon::update_clog_table();
	}
}

/**
 * Once per week, generate the fulldump archive.
 */
class FulldumpGeneratorJob extends Cron5Job
{
	public function get_period() { return 7*86400; }
	public function execute()
	{
		require_once 'services/dbsync/common.inc.php';
		\okapi\services\dbsync\common\SyncCommon::generate_fulldump();
	}
}

