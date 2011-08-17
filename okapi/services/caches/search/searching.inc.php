<?php

namespace okapi\services\caches\search;

use okapi\OkapiRequest;
use okapi\InvalidParam;
use okapi\Okapi;

class SearchAssistant
{
	/**
	 * Load, parse and check common geocache search parameters from the
	 * given OKAPI request. Most cache search methods share a common set
	 * of filtering parameters recognized by this method. It returns
	 * a dictionary of the following structure:
	 * 
	 *  - "where_conds" - list of additional WHERE conditions to be ANDed
	 *    to the rest of your SQL query,
	 *  - "limit" - value of the limit parameter.
	 */
	public static function get_common_search_params(OkapiRequest $request)
	{
		$where_conds = array('true');
		
		#
		# type
		#
		
		if ($tmp = $request->get_parameter('type'))
		{
			$operator = "in";
			if ($tmp[0] == '-')
			{
				$tmp = substr($tmp, 1);
				$operator = "not in";
			}
			$types = array();
			foreach (explode("|", $tmp) as $name)
			{
				if ($id = Okapi::cache_type_name2id($name))
					$types[] = $id;
				else
					throw new InvalidParam('type', "'$name' is not a valid cache type.");
			}
			$where_conds[] = "caches.type $operator ('".implode("','", array_map('mysql_real_escape_string', $types))."')";
		}
		
		#
		# status - filter by status codes
		#
		
		$tmp = $request->get_parameter('status');
		if ($tmp == null) $tmp = "ready";
		$codes = array();
		foreach (explode("|", $tmp) as $name)
		{
			if ($id = Okapi::cache_status_name2id($name))
				$codes[] = $id;
			else
				throw new InvalidParam('status', "'$name' is not a valid cache status.");
		}
		$where_conds[] = "caches.status in ('".implode(",", array_map('mysql_real_escape_string', $codes))."')";
		
		#
		# owner
		#
		
		if ($tmp = $request->get_parameter('owner'))
		{
			$rs = sql("
				select user_id, username
				from user
				where username in ('".implode("','", array_map('mysql_real_escape_string', explode("|", $tmp)))."');
			");
			$dict = array();
			while ($row = sql_fetch_assoc($rs))
				$dict[$row['username']] = $row['user_id'];
			mysql_free_result($rs);
			foreach (explode("|", $tmp) as $username)
				if (!isset($dict[$username]))
					throw new InvalidParam('owner', "user '$username' does not exist.");
			$where_conds[] = "caches.user_id in ('".implode("','", array_map('mysql_real_escape_string', array_values($dict)))."')";
		}
		
		#
		# terrain, difficulty, size, rating - these are similar, we'll do them in a loop
		#
		
		foreach (array('terrain', 'difficulty', 'size', 'rating') as $param_name)
		{
			if ($tmp = $request->get_parameter($param_name))
			{
				if (!preg_match("/^[1-5]-[1-5]$/", $tmp))
					throw new InvalidParam($param_name, "'$tmp'");
				list($min, $max) = explode("-", $tmp);
				if ($min > $max)
					throw new InvalidParam($param_name, "'$tmp'");
				switch ($param_name)
				{
					case 'terrain':
						$where_conds[] = "caches.terrain between 2*$min and 2*$max";
						break;
					case 'difficulty':
						$where_conds[] = "caches.difficulty between 2*$min and 2*$max";
						break;
					case 'size':
						$where_conds[] = "caches.size between $min+1 and $max+1";
						break;
					case 'rating':
						$divisors = array(-3.0, -1.0, 0.1, 1.4, 2.2, 3.0);
						$min = $divisors[$min - 1];
						$max = $divisors[$max];
						$where_conds[] = "caches.score between $min and $max";
						$where_conds[] = "caches.votes > 3";
						break;
				}
			}
		}
		
		#
		# min_recommendations
		#
		
		if ($tmp = $request->get_parameter('min_recommendations'))
		{
			if ($tmp[strlen($tmp) - 1] == '%')
			{
				$tmp = substr($tmp, 0, strlen($tmp) - 1);
				if (intval($tmp) != $tmp)
					throw new InvalidParam('min_recommendations', "'$tmp'");
				$tmp = intval($tmp);
				if ($tmp > 100 || $tmp < 0)
					throw new InvalidParam('min_recommendations', "'$tmp'");
				$tmp = floatval($tmp) / 100.0;
				$where_conds[] = "caches.topratings >= caches.founds * '".mysql_real_escape_string($tmp)."'";
				$where_conds[] = "caches.founds > 0";
			}
			if (intval($tmp) != $tmp)
				throw new InvalidParam('min_recommendations', "'$tmp'");
			$where_conds[] = "caches.topratings >= '".mysql_real_escape_string($tmp)."'";
		}
		
		#
		# min_founds
		#
		
		if ($tmp = $request->get_parameter('min_founds'))
		{
			if (intval($tmp) != $tmp)
				throw new InvalidParam('min_founds', "'$tmp'");
			$where_conds[] = "caches.founds >= '".mysql_real_escape_string($tmp)."'";
		}
		
		#
		# modified_since
		#
		
		if ($tmp = $request->get_parameter('modified_since'))
		{
			if (Okapi::is_valid_datetime($tmp))
				$where_conds[] = "caches.last_modified > '".mysql_real_escape_string($tmp)."'";
			else
				throw new InvalidParam('modified_since', "'$tmp' is not in a valid format or is not a valid date.");
		}
		
		#
		# found_status
		#
		
		if ($tmp = $request->get_parameter('found_status'))
		{
			if ($request->token == null)
				throw new InvalidParam('found_status', "Might be used only for requests signed with an Access Token.");
			if (!in_array($tmp, array('found_only', 'unfound_only', 'either')))
				throw new InvalidParam('found_status', "'$tmp'");
			if ($tmp != 'either')
			{
				$rs = sql("
					select cache_id
					from cache_logs
					where
						user_id = '".mysql_real_escape_string($request->token->user_id)."'
						and type = 1
				");
				$found_cache_ids = array();
				while ($row = sql_fetch_assoc($rs))
					$found_cache_ids[] = $row['cache_id'];
				mysql_free_result($rs);
				$operator = ($tmp == 'found_only') ? "in" : "not in";
				$where_conds[] = "caches.cache_id $operator ('".implode("','", array_map('mysql_real_escape_string', $found_cache_ids))."')";
			}
		}
		
		#
		# exclude_my_own
		#
		
		if ($tmp = $request->get_parameter('exclude_my_own'))
		{
			if ($request->token == null)
				throw new InvalidParam('exclude_my_own', "Might be used only for requests signed with an Access Token.");
			if (!in_array($tmp, array('true', 'false')))
				throw new InvalidParam('exclude_my_own', "'$tmp'");
			if ($tmp == 'true')
				$where_conds[] = "caches.user_id != '".mysql_real_escape_string($request->token->user_id)."'";
		}
		
		#
		# limit
		#
		$limit = $request->get_parameter('limit');
		if ($limit == null) $limit = "100";
		if (intval($limit) != $limit)
			throw new InvalidParam('limit', "'$limit'");
		if ($limit < 1 || $limit > 1000)
			throw new InvalidParam('limit', "Has to be between 1 and 1000.");
		
		
		/*
		#
		# area WRBUG
		#
		
		$area = $request->get_parameter('area');
		
		#
		# point WRBUG
		#
		
		$point = $request->get_parameter('point');
		
		#
		# dist WRBUG
		#
		
		$dist = $request->get_parameter('dist');
		if (!$dist) $dist = "0";
		if (floatval($dist) != $dist)
			throw new InvalidParam('dist');
		
		#
		# wp WRBUG
		#
		
		if ($tmp = $request->get_parameter('wpts'))
		{
			$where_conds[] = "caches.wp_oc in ('".implode("','", array_map('mysql_real_escape_string', $tmp))."')";
		}
		
		#
		# desc WRBUG
		#
		
		if ($tmp = $request->get_parameter('desc'))
		{
			if (!in_array($tmp, array('true', 'false')))
				throw new InvalidParam('desc', "'$tmp'");
			if ($tmp == 'true' && $format == 'gpx')
				throw new InvalidParam('desc', "Must be set to false when format is set to 'gpx'.");
			# WRTODO
			# if ($tmp == 'true')
			# {
			#	$select_exprs[] = "cache_desc.hint";
			#	$select_exprs[] = "cache_desc.short_desc";
			#	$select_exprs[] = "cache_desc.desc";
			# }
		}
		
		#
		# logs WRBUG
		#
		
		$logs = $request->get_parameter('logs');
		if (!$logs) $logs = "0";
		if (intval($logs) != $logs)
			throw new InvalidParam('logs', "'$logs'");
		if ($logs < 0 || $logs > 500)
			throw new InvalidParam('logs', "Has to be between 0 and 500.");
		*/
		
		
		return array(
			'where_conds' => $where_conds,
			'limit' => $limit,
		);
	}
	
	/**
	 * Search for OX codes using given conditions and options. Return
	 * an array in a "standard" format of array('results' => list of
	 * OX codes, 'more' => boolean). This method takes care of the
	 * 'more' variable in an appropriate way.
	 * 
	 * The $options parameter include:
	 *  - where_conds - list of additional WHERE conditions to be ANDed
	 *    to the rest of your SQL query,
	 *  - extra_tables - list of additional tables to be joined within
	 *    the query,
	 *  - order_by - SQL formula to be used with ORDER BY clause,
	 *  - limit - maximum number of OX codes to be returned.
	 */
	public static function get_common_search_result($options)
	{
		$tables = array_merge(
			array('caches'),
			$options['extra_tables']
		);
		$where_conds = array_merge(
			array('caches.wp_oc is not null'),
			$options['where_conds']
		);
		
		# We need to pull limit+1 items, in order to properly determine the
		# value of "more" variable.
		
		$rs = sql("
			select caches.wp_oc
			from ".implode(", ", $tables)."
			where ".implode(" and ", $where_conds)."
			".((isset($options['order_by']))?"order by ".$options['order_by']:"")."
			limit ".($options['limit'] + 1).";
		");
		$ox_codes = array();
		while ($row = sql_fetch_assoc($rs))
			$ox_codes[] = $row['wp_oc'];
		mysql_free_result($rs);
		
		if (count($ox_codes) > $options['limit'])
		{
			$more = true;
			array_pop($ox_codes); # get rid of the one above the limit
		} else {
			$more = false;
		}
		
		$result = array(
			'results' => $ox_codes,
			'more' => $more,
		);
		return $result;
	}
}
