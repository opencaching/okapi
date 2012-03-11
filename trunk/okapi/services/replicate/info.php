<?php

namespace okapi\services\replicate\info;

use Exception;
use okapi\Okapi;
use okapi\Db;
use okapi\Cache;
use okapi\OkapiRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\BadRequest;
use okapi\DoesNotExist;
use okapi\OkapiInternalRequest;
use okapi\OkapiInternalConsumer;
use okapi\OkapiServiceRunner;
use okapi\services\replicate\ReplicateCommon;

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
		require_once 'replicate_common.inc.php';

		$result = array();
		$result['changelog'] = array(
			'min_revision' => ReplicateCommon::get_min_revision(),
			'max_revision' => ReplicateCommon::get_revision(),
		);
		$dump = Cache::get("last_fulldump");
		if ($dump)
		{
			$result['latest_fulldump'] = array(
				'filename' => $dump['meta']['public_filename'],
				'revision' => $dump['revision'],
				'generated_at' => $dump['meta']['generated_at'],
				'size' => $dump['meta']['compressed_size'],
				'size_uncompressed' => $dump['meta']['uncompressed_size'],
			);
		} else {
			$result['latest_fulldump'] = null;
		}
		
		return Okapi::formatted_response($request, $result);
	}
}
