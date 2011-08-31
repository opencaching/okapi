<?php

namespace okapi\services\apisrv\stats;

use Exception;
use okapi\Okapi;
use okapi\Db;
use okapi\Cache;
use okapi\OkapiRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\OkapiServiceRunner;
use okapi\OkapiInternalRequest;

class WebService
{
	public static function options()
	{
		return array(
			'min_auth_level' => 0
		);
	}
	
	public static function call(OkapiRequest $request)
	{
		$cachekey = "apisrv/stats";
		$result = Cache::get($cachekey);
		if (!$result)
		{
			$result = array(
				'cache_count' => Db::select_value("
					select count(*) from caches where status in (1,2,3)
				"),
				'user_count' => Db::select_value("
					select count(*) from (
						select distinct user_id
						from cache_logs
						where
							type in (1,2)
							and deleted = 0
						UNION DISTINCT
						select distinct user_id
						from caches
					) as t;
				"),
			);
			Cache::set($cachekey, $result, 86400); # cache it for one day
		}
		return Okapi::formatted_response($request, $result);
	}
}
