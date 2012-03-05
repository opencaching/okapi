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
		
		# First we have to update the changelog. We make sure to do this only if it hasn't been
		# updated during the last minute or so.
		
		$last_update = Okapi::get_var('last_clog_update', time() - 86400) + 0;
		if (time() - $last_update > 60)
			SyncCommon::update_clog_table($last_update);
		
		# Changelog is up-to-date. Let's check the $since parameter.
		
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
