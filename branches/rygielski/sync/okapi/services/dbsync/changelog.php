<?php

namespace okapi\services\dbsync\changelog;

use Exception;
use okapi\Okapi;
use okapi\Db;
use okapi\OkapiRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\BadRequest;
use okapi\DoesNotExist;
use okapi\OkapiInternalRequest;
use okapi\OkapiInternalConsumer;
use okapi\OkapiServiceRunner;
use okapi\services\dbsync\common\SyncCommon;

require_once 'common.inc.php';

class WebService
{
	public static function options()
	{
		return array(
			'min_auth_level' => 1
		);
	}
	
	public static function call(OkapiRequest $request)
	{
		$since = $request->get_parameter('since');
		if ($since === null) throw new ParamMissing('since');
		if ((int)$since != $since) throw new InvalidParam('since');
		
		# First we have to update the changelog. Usually it will be pretty up-to-date, since it is
		# being updated by a cronjob too, this operation should be quick then.
		
		SyncCommon::update_clog_table();
		
		# Let's check the $since parameter.
		
		if (!SyncCommon::check_since_param($since))
			throw new BadRequest("The 'since' parameter is too old. You must sync your database more frequently.");
		
		# Select a best chunk for the given $since, get the chunk from the database (or cache).
		
		list($from, $to) = SyncCommon::select_best_chunk($since);
		$clog_entries = SyncCommon::get_chunk($from, $to);
		
		$result = array(
			'changelog' => &$clog_entries,
			'revision' => $to,
			'more' => $to < SyncCommon::get_revision(),
		);
		
		return Okapi::formatted_response($request, $result);
	}
}
