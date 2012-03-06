<?php

namespace okapi\views\cron5;

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

/**
 * This is an entry point for system's crontab. System's crontab will be
 * running this view every 5 minutes.
 */
class View
{
	public static function out($str)
	{
		print $str;
		ob_flush();
		flush();
	}

	public static function call()
	{
		ignore_user_abort(true);
		set_time_limit(0);
		header("Content-Type: text/plain; charset=utf-8");
		Okapi::execute_cron5_cronjobs();
		self::out("Done.");
	}
}
