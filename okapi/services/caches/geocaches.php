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
	
	public static $valid_field_names = array('wpt', 'name', 'names', 'location', 'type',
		'status', 'url', 'owner_id', 'founds', 'notfounds', 'size', 'difficulty', 'terrain',
		'rating', 'rating_votes', 'recommendations', 'description', 'descriptions', 'hint',
		'hints', 'images', 'last_found', 'last_modified', 'date_created', 'date_hidden');
	
	public static function call(OkapiRequest $request)
	{
		$cache_wpts = $request->get_parameter('cache_wpts');
		if (!$cache_wpts) throw new ParamMissing('cache_wpts');
		$cache_wpts = explode("|", $cache_wpts);
		if (count($cache_wpts) > 500)
			throw new InvalidParam('cache_wpts', "Maximum allowed number of referenced ".
				"caches is 500. You provided ".count($cache_wpts)." waypoint codes.");
		$langpref = $request->get_parameter('langpref');
		if (!$langpref) $langpref = "en";
		$langpref = explode("|", $langpref);
		$fields = $request->get_parameter('fields');
		if (!$fields) $fields = "wpt|name|location|type|status";
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
			where wp_oc in ('".implode("','", array_map('mysql_real_escape_string', $cache_wpts))."')
		");
		$results = array();
		$cacheid2wptcode = array();
		while ($row = sql_fetch_assoc($rs))
		{
			$entry = array();
			$cacheid2wptcode[$row['cache_id']] = $row['wp_oc'];
			foreach ($fields as $field)
			{
				switch ($field)
				{
					case 'wpt': $entry['wpt'] = $row['wp_oc']; break;
					case 'name': $entry['name'] = $row['name']; break;
					case 'names': $entry['name'] = array('pl' => $row['name']); break; // for the future
					case 'location': $entry['location'] = round($row['latitude'], 6)."|".round($row['longitude'], 6); break;
					case 'type': $entry['type'] = Okapi::cache_type_id2name($row['type']); break;
					case 'status': $entry['status'] = Okapi::cache_status_id2name($row['status']); break;
					case 'url': $entry['url'] = $GLOBALS['absolute_server_URI']."viewcache.php?cacheid=".$row['cache_id']; break;
					case 'owner_id': $entry['owner_id'] = $row['user_id']; break;
					case 'founds': $entry['founds'] = $row['founds'] + 0; break;
					case 'notfounds': $entry['notfounds'] = $row['notfounds'] + 0; break;
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
					case 'description': /* handled separately */ break;
					case 'descriptions': /* handled separately */ break;
					case 'hint': /* handled separately */ break;
					case 'hints': /* handled separately */ break;
					case 'images': /* handled separately */ break;
					case 'last_found': $entry['last_found'] = $row['last_found'] ? date('c', strtotime($row['last_found'])) : null; break;
					case 'last_modified': $entry['last_modified'] = date('c', strtotime($row['last_modified'])); break;
					case 'date_created': $entry['date_created'] = date('c', strtotime($row['date_created'])); break;
					case 'date_hidden': $entry['date_hidden'] = date('c', strtotime($row['date_hidden'])); break;
					default: throw new Exception("Missing field case: ".$field);
				}
			}
			$results[$row['wp_oc']] = $entry;
		}
		mysql_free_result($rs);
		
		if (in_array('description', $fields) || in_array('descriptions', $fields)
			|| in_array('hint', $fields) || in_array('hints', $fields))
		{
			# At first, we will fill all those 4 fields, even if user requested just one
			# of them. We will chop off the remaining three at the end.
			
			foreach ($results as &$result_ref)
				$result_ref['descriptions'] = array();
			foreach ($results as &$result_ref)
				$result_ref['hints'] = array();
			
			# Get cache descriptions and hints.
			
			$rs = sql("
				select cache_id, language, `desc`, hint
				from cache_desc
				where cache_id in ('".implode("','", array_map('mysql_real_escape_string', array_keys($cacheid2wptcode)))."')
			");
			while ($row = sql_fetch_assoc($rs))
			{
				$cache_wpt = $cacheid2wptcode[$row['cache_id']];
				// strtolower - ISO 639-1 codes are lowercase
				if ($row['desc'])
					$results[$cache_wpt]['descriptions'][strtolower($row['language'])] = $row['desc'];
						"\n".self::get_cache_attribution_note($row['cache_id'], $row['language']);
				if ($row['hint'])
					$results[$cache_wpt]['hints'][strtolower($row['language'])] = $row['hint'];
			}
			foreach ($results as &$result_ref)
			{
				$result_ref['description'] = Okapi::pick_best_language($result_ref['descriptions'], $langpref);
				$result_ref['hint'] = Okapi::pick_best_language($result_ref['hints'], $langpref);
			}
			
			# Remove unwanted fields.
			
			foreach (array('description', 'descriptions', 'hint', 'hints') as $field)
				if (!in_array($field, $fields))
					foreach ($results as &$result_ref)
						unset($result_ref[$field]);
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
					object_id in ('".implode("','", array_map('mysql_real_escape_string', array_keys($cacheid2wptcode)))."')
					and display = 1
			");
			while ($row = sql_fetch_assoc($rs))
			{
				$cache_wpt = $cacheid2wptcode[$row['object_id']];
				$results[$cache_wpt]['images'][] = array(
					'url' => $row['url'],
					'thumb_url' => $row['thumb_url'] ? $row['thumb_url'] : null,
					'caption' => $row['title'],
					'is_spoiler' => ($row['spoiler'] ? true : false),
				);
			}
		}
		
		# Check which waypoint codes were not found and mark them with null.
		foreach ($cache_wpts as $cache_wpt)
			if (!isset($results[$cache_wpt]))
				$results[$cache_wpt] = null;
		
		return Okapi::formatted_response($request, $results);
	}
	
	public static function get_cache_attribution_note($cache_id, $lang)
	{
		$site_url = $GLOBALS['absolute_server_URI'];
		$cache_url = $site_url."viewcache.php?cacheid=$cache_id";
		
		# This list if to be extended (opencaching.de, etc.).
		
		switch ($lang)
		{
			case 'pl':
				return "<p>Opis <a href='$cache_url'>skrzynki</a> pochodzi z serwisu <a href='$site_url'>$site_url</a>.</p>";
				break;
			default:
				$extra = "<p>This <a href='$cache_url'>geocache</a> description comes from the <a href='$site_url'>$site_url</a> site.</p>";
				break;
		}
	}
}
