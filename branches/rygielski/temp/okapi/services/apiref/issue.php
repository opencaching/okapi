<?php

namespace okapi\services\apiref\issue;

use Exception;
use okapi\Okapi;
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
		$issue_id = $request->get_parameter('issue_id');
		if (!$issue_id)
			throw new ParamMissing('issue_id');
		if (!preg_match("/^[0-9]+$/", $issue_id))
			throw new InvalidParam('issue_id');
		
		# Download list of comments from Google Code Issue Tracker.
		$xml = file_get_contents("http://code.google.com/feeds/issues/p/opencaching-api/issues/$issue_id/comments/full");
		$doc = simplexml_load_string($xml);
		$result = array(
			'id' => $issue_id + 0,
			'last_updated' => (string)$doc->updated,
			'title' => (string)$doc->title,
			'url' => (string)$doc->link[0]['href'],
			'comment_count' => $doc->entry->count()
		);
		return Okapi::formatted_response($request, $result);
	}
}
