<?php

namespace okapi\services\cachesets\search;

use Exception;
use okapi\BadRequest;
use okapi\Db;
use okapi\InvalidParam;
use okapi\Okapi;
use okapi\services\cachesets\CachesetStatics;
use okapi\OkapiRequest;

require_once(__DIR__.'/../cacheset_static.inc.php');

class CSSearchAssistant
{
    /**
     * Current request issued by the client.
     */
    private $request; /* @var OkapiRequest */

    /**
     * Initializes an object with a content of the client request.
     * (The request should contain common cacheset search parameters.)
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
     * Load, parse and check common cacheset search parameters (the ones
     * described in services/cachesets/search/all method) from $this->request.
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
                    $id = CachesetStatics::cacheset_type_name2id($name);
                    $types[] = $id;
                }
                catch (Exception $e)
                {
                    throw new InvalidParam('type', "'$name' is not a valid cacheset type.");
                }
            }
            if (count($types) > 0)
                $where_conds[] = "cs.type $operator ('".implode("','", array_map('\okapi\Db::escape_string', $types))."')";
            else if ($operator == "in")
                $where_conds[] = "false";
        }


        #
        # status - filter by status
        #

        $tmp = $this->request->get_parameter('status');
        if ($tmp == null) $tmp = "Available";
        $codes = array();
        foreach (explode("|", $tmp) as $name)
        {
            try
            {
                $codes[] = CachesetStatics::cacheset_status_name2id($name);
            }
            catch (Exception $e)
            {
                throw new InvalidParam('status', "'$name' is not a valid cacheset status.");
            }
        }
        $where_conds[] = "cs.status in ('".implode("','", array_map('\okapi\Db::escape_string', $codes))."')";

        #
        # limit
        #

        $limit = $this->request->get_parameter('limit');
        if ($limit == null) $limit = "500";
        if (!is_numeric($limit))
            throw new InvalidParam('limit', "'$limit'");

        if ($limit < 1 || (($limit > 500) && (!$this->request->skip_limits)))
            throw new InvalidParam(
                'limit',
                $this->request->skip_limits
                ? "Cannot be lower than 1."
                : "Has to be between 1 and 500."
            );


        #
        # offset
        #

        $offset = $this->request->get_parameter('offset');
        if ($offset == null) $offset = "0";
        if (!is_numeric($offset))
            throw new InvalidParam('offset', "'$offset'");

        if (($offset + $limit > 500) && (!$this->request->skip_limits))
            throw new BadRequest("The sum of offset and limit may not exceed 500.");

        if ($offset < 0 || (($offset > 499) && (!$this->request->skip_limits)))
            throw new InvalidParam(
                'offset',
                $this->request->skip_limits
                ? "Cannot be lower than 0."
                : "Has to be between 0 and 499."
            );

        #
        # order_by
        #

        $order_clauses = array();
        $order_by = $this->request->get_parameter('order_by');
        if ($order_by != null)
        {
            $order_by = explode('|', $order_by);
            foreach ($order_by as $field)
            {
                $dir = 'asc';
                if ($field[0] == '-')
                {
                    $dir = 'desc';
                    $field = substr($field, 1);
                }
                elseif ($field[0] == '+')
                    $field = substr($field, 1); # ignore leading "+"

                switch ($field)
                {
                    case 'uuid': $cl = "cs.id"; break;
                    case 'name': $cl = "cs.name"; break;
                    case 'date_created': $cl = "cs.dateCreated"; break;
                    case 'cache_count': $cl = "cs.cacheCount"; break;
                    default:
                        throw new InvalidParam('order_by', "Unsupported field '$field'");
                }
                $order_clauses[] = "($cl) $dir";
            }
        }

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
     * Search for cacheset using conditions and options stored in the instance
     * of this class. These conditions are usually initialized by the call
     * to prepare_common_search_params(), and may be further altered by the
     * client of this call by calling get_search_params() and set_search_params().
     *
     * Returns an array in a "standard" format of array('results' => list of
     * cacheset uuids, 'more' => boolean). This method takes care of the
     * 'more' variable in an appropriate way.
     */
    public function get_common_search_result()
    {

        $tables = array_merge(
            array('PowerTrail as cs '.$this->search_params['caches_indexhint']),
            $this->search_params['extra_tables']
            );

        $where_conds = $this->search_params['where_conds'];

        # We need to pull limit+1 items, in order to properly determine the
        # value of "more" variable.

        $cacheset_uuids = Db::select_column("
            select uuid
            from ".implode(", ", $tables)." ".
            implode(" ", $this->search_params['extra_joins'])."
            where ".implode(" and ", $where_conds)."
            ".((count($this->search_params['order_by']) > 0) ? "order by ".implode(", ", $this->search_params['order_by']) : "")."
            limit ".($this->search_params['offset']).", ".($this->search_params['limit'] + 1).";
        ");

        if (count($cacheset_uuids) > $this->search_params['limit'])
        {
            $more = true;
            array_pop($cacheset_uuids); # get rid of the one above the limit
        } else {
            $more = false;
        }

        $result = array(
            'results' => $cacheset_uuids,
            'more' => $more,
        );
        return $result;

    }
}
