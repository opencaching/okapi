<?php

namespace okapi\services\caches\geocaches;

use Exception;
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
			'min_auth_level' => 1
		);
	}
	
	public static $valid_field_names = array('oxcode', 'name', 'location', 'type', 'status',
		'owner_id', 'founds', 'notfounds', 'last_found', 'size', 'difficulty', 'terrain',
		'rating', 'rating_votes', 'recommendations', 'descriptions', 'hints', 'images',
		'last_modified', 'date_created', 'date_hidden');
	
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
		$cacheid2oxcode = array();
		while ($row = sql_fetch_assoc($rs))
		{
			$entry = array();
			$cacheid2oxcode[$row['cache_id']] = $row['wp_oc'];
			foreach ($fields as $field)
			{
				switch ($field)
				{
					case 'oxcode': $entry['oxcode'] = $row['wp_oc']; break;
					case 'name': $entry['name'] = array('PL' => $row['name']); break;
					case 'location': $entry['location'] = round($row['latitude'], 6)."|".round($row['longitude'], 6); break;
					case 'type': $entry['type'] = Okapi::cache_type_id2name($row['type']); break;
					case 'status': $entry['status'] = Okapi::cache_status_id2name($row['status']); break;
					case 'owner_id': $entry['owner_id'] = $row['user_id']; break;
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
					case 'hints': /* handled separately */ break;
					case 'images': /* handled separately */ break;
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
		
		$include_descriptions = in_array('descriptions', $fields);
		$include_hints = in_array('hints', $fields);
		if ($include_descriptions || $include_hints)
		{
			if ($include_descriptions)
				foreach ($results as &$result_ref)
					$result_ref['descriptions'] = array();
			if ($include_hints)
				foreach ($results as &$result_ref)
					$result_ref['hints'] = array();
			
			# Get cache descriptions and hints.
			$rs = sql("
				select cache_id, language, `desc`, hint
				from cache_desc
				where cache_id in ('".implode("','", array_map('mysql_real_escape_string', array_keys($cacheid2oxcode)))."')
			");
			while ($row = sql_fetch_assoc($rs))
			{
				$oxcode = $cacheid2oxcode[$row['cache_id']];
				if ($include_descriptions && $row['desc'])
				{
					$site_url = $GLOBALS['absolute_server_URI'];
					$cache_url = $site_url."viewcache.php?cacheid=".$row['cache_id'];
					switch ($row['language'])
					{
						case 'PL':
							$extra = "<p>Opis <a href='$cache_url'>skrzynki</a> pochodzi z serwisu <a href='$site_url'>$site_url</a>.</p>";
							break;
						default:
							$extra = "<p>This <a href='$cache_url'>geocache</a> description comes from the <a href='$site_url'>$site_url</a> site.</p>";
							break;
					}
					$results[$oxcode]['descriptions'][$row['language']] = $row['desc']."\n".$extra;
				}
				if ($include_hints && $row['hint'])
					$results[$oxcode]['hints'][$row['language']] = $row['hint'];
			}
		}
		$include_images = in_array('images', $fields);
		if ($include_images)
		{
			foreach ($results as &$result_ref)
				$result_ref['images'] = array();
			$rs = sql("
				select object_id, url, thumb_url, title, spoiler
				from pictures
				where
					object_id in ('".implode("','", array_map('mysql_real_escape_string', array_keys($cacheid2oxcode)))."')
					and display = 1
			");
			while ($row = sql_fetch_assoc($rs))
			{
				$oxcode = $cacheid2oxcode[$row['object_id']];
				$results[$oxcode]['images'][] = array(
					'url' => $row['url'],
					'thumb_url' => $row['thumb_url'] ? $row['thumb_url'] : null,
					'caption' => array('PL' => $row['title']),
					'is_spoiler' => ($row['spoiler'] ? true : false),
				);
			}
		}
		return Okapi::formatted_response($request, $results);
	}
}
