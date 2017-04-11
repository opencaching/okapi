<?php

namespace okapi\services\OCPL\paths\search;

use Exception;
use okapi\BadRequest;
use okapi\Db;
use okapi\InvalidParam;
use okapi\Okapi;
use okapi\OkapiInternalRequest;
use okapi\OkapiRequest;
use okapi\OkapiServiceRunner;
use okapi\Settings;

class GPSearchAssistant
{
    /**
     * Current request issued by the client.
     */
    private $request; /* @var OkapiRequest */

    /**
     * Initializes an object with a content of the client request.
     * (The request should contain common geocache search parameters.)
     */
    public  function __construct(OkapiRequest $request)
    {
        $this->request = $request;
        $this->longitude_expr = NULL;
        $this->latitude_expr = NULL;
        $this->location_extra_sql = NULL;
        $this->search_params = NULL;
    }

    /**
     * This member holds a dictionary, which is used to build SQL query. For details,
     * see documentation of get_search_params() and prepare_common_search_params()
     */
    private $search_params;

    /**
     * This function returns a dictionary of the following structure:
     *
     *  - "where_conds" - list of additional WHERE conditions to be ANDed
     *    to the rest of your SQL query,
     *  - "offset" - value of the offset parameter to be used in the LIMIT clause,
     *  - "limit" - value of the limit parameter to be used in the LIMIT clause,
     *  - "order_by" - list of order by clauses to be included in the "order by"
     *    SQL clause,
     *  - "extra_tables" - extra tables to be included in the FROM clause.
     *  - "extra_joins" - extra join statements to be included
     *
     * The dictionary is initalized by the call to prepare_common_search_params(),
     * and may be further altered before an actual SQL execution, performed usually
     * by get_common_search_result().
     *
     * If you alter the results, make sure to save them back to this class by calling
     * set_search_params().
     *
     * Important: YOU HAVE TO make sure that all options are properly sanitized
     * for SQL queries! I.e. they cannot contain unescaped user-supplied data.
     */
    public function get_search_params()
    {
        return $this->search_params;
    }

    /**
     * Set search params, a dictionary of the structure described in get_search_params().
     *
     * Important: YOU HAVE TO make sure that all options are properly sanitized
     * for SQL queries! I.e. they cannot contain unescaped user-supplied data.
     */
    public function set_search_params($search_params)
    {
        $this->search_params = $search_params;
    }

    /**
     * Load, parse and check common geocache search parameters (the ones
     * described in services/caches/search/all method) from $this->request.
     * Most cache search methods share a common set
     * of filtering parameters recognized by this method. It initalizes
     * search params, which can be further altered by calls to other methods
     * of this class, or outside of this class by a call to get_search_params();
     *
     * This method doesn't return anything. See get_search_params method.
     */
    public function prepare_common_search_params()
    {
        $where_conds = array('true');
        $extra_tables = array();
        $extra_joins = array();

        # At the beginning we have to set up some "magic e$Xpressions".
        # We will use them to make our query run on both OCPL and OCDE databases.

        if (Settings::get('OC_BRANCH') == 'oc.pl')
        {
            # OCPL's 'caches' table contains some fields which OCDE's does not
            # (topratings, founds, notfounds, last_found, votes, score). If
            # we're being run on OCPL installation, we will simply use them.

            $X_TOPRATINGS = 'caches.topratings';
            $X_FOUNDS = 'caches.founds';
            $X_NOTFOUNDS = 'caches.notfounds';
            $X_LAST_FOUND = 'caches.last_found';
            $X_VOTES = 'caches.votes';
            $X_SCORE = 'caches.score';
        }
        else
        {
            # OCDE holds this data in a separate table. Additionally, OCDE
            # does not provide a rating system (votes and score fields).
            # If we're being run on OCDE database, we will include this
            # additional table in our query and we will map the field
            # expressions to approriate places.

            # stat_caches entries are optional, therefore we must do a left join:
            $extra_joins[] = 'left join stat_caches on stat_caches.cache_id = caches.cache_id';

            $X_TOPRATINGS = 'ifnull(stat_caches.toprating,0)';
            $X_FOUNDS = 'ifnull(stat_caches.found,0)';
            $X_NOTFOUNDS = 'ifnull(stat_caches.notfound,0)';
            $X_LAST_FOUND = 'ifnull(stat_caches.last_found,0)';
            $X_VOTES = '0'; // no support for ratings
            $X_SCORE = '0'; // no support for ratings
        }

        #
        # type
        #

        if ($tmp = $this->request->get_parameter('type'))
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
                try
                {
                    $id = Okapi::cache_type_name2id($name);
                    $types[] = $id;
                }
                catch (Exception $e)
                {
                    throw new InvalidParam('type', "'$name' is not a valid cache type.");
                }
            }
            if (count($types) > 0)
                $where_conds[] = "caches.type $operator ('".implode("','", array_map('\okapi\Db::escape_string', $types))."')";
            else if ($operator == "in")
                $where_conds[] = "false";
        }


        #
        # status - filter by status codes
        #

        $tmp = $this->request->get_parameter('status');
        if ($tmp == null) $tmp = "Available";
        $codes = array();
        foreach (explode("|", $tmp) as $name)
        {
            try
            {
                $codes[] = Okapi::cache_status_name2id($name);
            }
            catch (Exception $e)
            {
                throw new InvalidParam('status', "'$name' is not a valid cache status.");
            }
        }
        $where_conds[] = "caches.status in ('".implode("','", array_map('\okapi\Db::escape_string', $codes))."')";

        # To avoid join errors, put each of the $where_conds in extra paranthesis.

        $tmp = array();
        foreach($where_conds as $cond)
            $tmp[] = "(".$cond.")";
        $where_conds = $tmp;
        unset($tmp);

        $ret_array = array(
            'where_conds' => $where_conds,
            'offset' => (int)$offset,
            'limit' => (int)$limit,
            'order_by' => $order_clauses,
            'caches_indexhint' => '',
            'extra_tables' => $extra_tables,
            'extra_joins' => $extra_joins,
        );

        if ($this->search_params === NULL)
        {
            $this->search_params = $ret_array;
        } else {
            $this->search_params = array_merge_recursive($this->search_params, $ret_array);
        }
    }

    /**
     * Search for caches using conditions and options stored in the instance
     * of this class. These conditions are usually initialized by the call
     * to prepare_common_search_params(), and may be further altered by the
     * client of this call by calling get_search_params() and set_search_params().
     *
     * Returns an array in a "standard" format of array('results' => list of
     * cache codes, 'more' => boolean). This method takes care of the
     * 'more' variable in an appropriate way.
     */
    public function get_common_search_result()
    {
        $tables = array_merge(
            array('caches '.$this->search_params['caches_indexhint']),
            $this->search_params['extra_tables']
        );
        $where_conds = array_merge(
            array('caches.wp_oc is not null'),
            $this->search_params['where_conds']
        );

        # We need to pull limit+1 items, in order to properly determine the
        # value of "more" variable.

        $cache_codes = Db::select_column("
            select caches.wp_oc
            from ".implode(", ", $tables)." ".
            implode(" ", $this->search_params['extra_joins'])."
            where ".implode(" and ", $where_conds)."
            ".((count($this->search_params['order_by']) > 0) ? "order by ".implode(", ", $this->search_params['order_by']) : "")."
            limit ".($this->search_params['offset']).", ".($this->search_params['limit'] + 1).";
        ");

        if (count($cache_codes) > $this->search_params['limit'])
        {
            $more = true;
            array_pop($cache_codes); # get rid of the one above the limit
        } else {
            $more = false;
        }

        $result = array(
            'results' => $cache_codes,
            'more' => $more,
        );
        return $result;
    }

    # Issue #298 - user coordinates implemented in oc.pl
    private $longitude_expr;
    private $latitude_expr;

    /**
     * This method extends search params in case you would like to search
     * using the geocache location, i.e. search for the geocaches nearest
     * to the given location. When you search for such geocaches, you must
     * use expressions returned by get_longitude_expr() and get_latitude_expr()
     * to query for the actual location of geocaches.
     */
    public function prepare_location_search_params()
    {
        $location_source = $this->request->get_parameter('location_source');
        if (!$location_source)
            $location_source = 'default-coords';

        # Make sure location_source has prefix alt_wpt:
        if ($location_source != 'default-coords' && strncmp($location_source, 'alt_wpt:', 8) != 0)
        {
            throw new InvalidParam('location_source', '\''.$location_source.'\'');
        }

        # Make sure we have sufficient authorization
        if ($location_source == 'alt_wpt:user-coords' && $this->request->token == null)
        {
            throw new BadRequest("Level 3 Authentication is required to access 'alt_wpt:user-coords'.");
        }

        if ($location_source != 'alt_wpt:user-coords') {
            # unsupported waypoint type - use default geocache coordinates
            $location_source = 'default-coords';
        }

        if ($location_source == 'default-coords')
        {
            $this->longitude_expr = 'caches.longitude';
            $this->latitude_expr = 'caches.latitude';
        } else {
            $extra_joins = null;
            if (Settings::get('OC_BRANCH') == 'oc.pl')
            {
                $this->longitude_expr = 'ifnull(cache_mod_cords.longitude, caches.longitude)';
                $this->latitude_expr = 'ifnull(cache_mod_cords.latitude, caches.latitude)';
                $extra_joins = array("
                    left join cache_mod_cords
                        on cache_mod_cords.cache_id = caches.cache_id
                        and cache_mod_cords.user_id = '".Db::escape_string($this->request->token->user_id)."'
                ");
            } else {
                # oc.de
                $this->longitude_expr = 'ifnull(coordinates.longitude, caches.longitude)';
                $this->latitude_expr = 'ifnull(coordinates.latitude, caches.latitude)';
                $extra_joins = array("
                    left join coordinates
                        on coordinates.cache_id = caches.cache_id
                        and coordinates.user_id = '".Db::escape_string($this->request->token->user_id)."'
                        and coordinates.type = 2
                        and coordinates.longitude != 0
                        and coordinates.latitude != 0
                ");
            }
            $location_extra_sql = array(
                'extra_joins' => $extra_joins
            );
            if ($this->search_params === NULL)
            {
                $this->search_params = $location_extra_sql;
            } else {
                $this->search_params = array_merge_recursive($this->search_params, $location_extra_sql);
            }
        }
    }

    /**
     * Returns the expression used as cache's longitude source. You may use this
     * method only after prepare_search_params_for_location() invocation.
     */
    public function get_longitude_expr()
    {
        return $this->longitude_expr;
    }

    /**
     * Returns the expression used as cache's latitude source. You may use this
     * method only after prepare_search_params_for_location() invocation.
     */
    public function get_latitude_expr()
    {
        return $this->latitude_expr;
    }

    /**
     * Get the list of cache IDs which were found by given user.
     * Parameter needs to be *internal* user id, not uuid.
     */
    private static function get_found_cache_ids($internal_user_ids)
    {
        return Db::select_column("
            select cache_id
            from cache_logs
            where
                user_id in ('".implode("','", array_map('\okapi\Db::escape_string', $internal_user_ids))."')
                and type in (
                    '".Db::escape_string(Okapi::logtypename2id("Found it"))."',
                    '".Db::escape_string(Okapi::logtypename2id("Attended"))."'
                )
                and ".((Settings::get('OC_BRANCH') == 'oc.pl') ? "deleted = 0" : "true")."
        ");
    }
}
