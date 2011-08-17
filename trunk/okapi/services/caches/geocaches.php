<?php

namespace okapi\services\caches\geocaches;

use okapi\Okapi;
use okapi\OkapiRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\services\caches\search\SearchAssistant;

class WebService
{
	public static function options()
	{
		return array(
			'consumer'   => 'required',
			'token'      => 'ignored',
		);
	}
	
	public static $valid_field_names = array('oxcode', 'name', 'location', 'type', 'status',
		'owner_id', 'founds', 'notfounds', 'last_found', 'size', 'difficulty', 'terrain',
		'rating', 'rating_votes', 'recommendations', 'descriptions', 'last_modified',
		'date_created', 'date_hidden');
	
	public static function call(OkapiRequest $request)
	{
		$oxcodes = $request->get_parameter('oxcodes');
		if (!$oxcodes) throw new ParamMissing('oxcodes');
		$oxcodes = explode("|", $oxcodes);
		if (count($oxcodes) > 500)
			throw new InvalidParam('oxcodes', "Maximum allowed number of referenced OX ".
				"codes is 500. You provided ".count($oxcodes)." codes.");
		$fields = $request->get_parameter('fields');
		if (!$fields) $fields = "oxcode|name|location|type|status";
		$fields = explode("|", $fields);
		foreach ($fields as $field)
			if (!in_array($field, self::$valid_field_names))
				throw new InvalidParam('fields', "'$field' is not a valid field code.");
		$rs = sql("
			select
				cache_id, user_id, name, longitude, latitude, last_modified,
				date_created, type, status, date_hidden, founds, notfounds, last_found,
				size, difficulty, terrain, wp_oc, topratings, votes, score
			from caches
			where wp_oc in ('".implode("','", array_map('mysql_real_escape_string', $oxcodes))."')
		");
		$results = array();
		while ($row = sql_fetch_assoc($rs))
		{
			$entry = array();
			foreach ($fields as $field)
			{
				switch ($field)
				{
					case 'oxcode': $entry['oxcode'] = $row['wp_oc']; break;
					case 'name': $entry['name'] = $row['name']; break;
					case 'location': $entry['location'] = round($row['latitude'], 6)."|".round($row['longitude'], 6); break;
					case 'type': $entry['type'] = Okapi::cache_type_id2name($row['type']); break;
					case 'status': $entry['status'] = Okapi::cache_status_id2name($row['status']); break;
					case 'owner_id': $entry['owner_id'] = $row['user_id'] + 0; break;
					case 'founds': $entry['founds'] = $row['founds'] + 0; break;
					case 'notfounds': $entry['notfounds'] = $row['notfounds'] + 0; break;
					case 'last_found': $entry['last_found'] = $row['last_found']; break;
					case 'size': $entry['size'] = ($row['size'] < 7) ? $row['size'] - 1 : null; break;
					case 'difficulty': $entry['difficulty'] = round($row['difficulty'] / 2.0, 1); break;
					case 'terrain': $entry['terrain'] = round($row['terrain'] / 2.0, 1); break;
					case 'rating':
						if ($row['votes'] <= 3) $entry['rating'] = null;
						elseif ($row['score'] >= 2.2) $entry['rating'] = 5;
						elseif ($row['score'] >= 1.4) $entry['rating'] = 4;
						elseif ($row['score'] >= 0.1) $entry['rating'] = 3;
						elseif ($row['score'] >= -1.0) $entry['rating'] = 2;
						else $entry['score'] = 1;
						break;
					case 'rating_votes': $entry['rating_votes'] = $row['votes'] + 0; break;
					case 'recommendations': $entry['recommendations'] = $row['topratings'] + 0; break;
					case 'descriptions': /* handled separately */ break;
					case 'last_modified': $entry['last_modified'] = $row['last_modified']; break;
					case 'date_created': $entry['date_created'] = $row['date_created']; break;
					case 'date_hidden': $entry['date_hidden'] = $row['date_hidden']; break;
					default: throw new Exception("Missing field case: ".$field);
				}
			}
			$results[$row['wp_oc']] = &$entry;
		}
		mysql_free_result($rs);
		# Check which OX codes were not found and mark them with null.
		foreach ($oxcodes as $oxcode)
			if (!isset($results[$oxcode]))
				$results[$oxcode] = null;
		return Okapi::formatted_response($request, $results);
	}
}
