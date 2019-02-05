<?php

namespace okapi;

use okapi\core\Cache;
use okapi\core\Consumer\OkapiFacadeConsumer;
use okapi\core\Db;
use okapi\core\Okapi;
use okapi\core\OkapiErrorHandler;
use okapi\core\OkapiServiceRunner;
use okapi\core\Request\OkapiInternalRequest;
use okapi\core\Token\OkapiFacadeAccessToken;
use okapi\lib\OCPLSignals;
use okapi\lib\OCSession;

# OKAPI Framework -- Wojciech Rygielski <rygielski@mimuw.edu.pl>

# Use this class when you want to use OKAPI's services within OC code.
# (Your service calls will appear with the name "Facade" in the weekly
# OKAPI usage report).

# IMPORTANT COMPATIBILITY NOTES:

# Note, that this is the *ONLY* internal OKAPI file that is guaranteed
# to stay backward-compatible (note that we mean FILES here, all OKAPI
# methods will stay compatible forever). If you want to use any class or
# method that has not been exposed through the Facade class, contact
# OKAPI developers, we will add it here.

# EXAMPLE OF USAGE:

# require_once $rootpath.'okapi/Facade.php';
# \okapi\Facade::schedule_user_entries_check(...);
# \okapi\Facade::disable_error_handling();

# --------------------

# This initialization code is executed by PHP upon first call of a Facade method:

require_once __DIR__ . '/autoload.php';

OkapiErrorHandler::init();
Okapi::init_internals();

# OCPL autoloader is needed to load OCPL settings and for accessing HTML purifier.
$ocplAutoloaderPath = __DIR__.'/../lib/ClassPathDictionary.php';
if (file_exists($ocplAutoloaderPath)) {
    require_once $ocplAutoloaderPath;
}

/**
 * Use this class to access OKAPI's services from external code (i.e. OC code).
 */
class Facade
{
    /**
     * Perform OKAPI service call, signed by internal 'facade' consumer key, and return the result
     * (this will be PHP object or OkapiHttpResponse, depending on the method). Use this method
     * whenever you need to access OKAPI services from within OC code. If you want to simulate
     * Level 3 Authentication, you should supply user's internal ID (the second parameter).
     */
    public static function service_call(
        $service_name,
        $user_id_or_null,  # ID of the logged-in user; noone else! See issues #496 and #439.
        $parameters
    ) {
        self::reenable_error_handling();
        $request = new OkapiInternalRequest(
            new OkapiFacadeConsumer(),
            ($user_id_or_null !== null) ? new OkapiFacadeAccessToken($user_id_or_null) : null,
            $parameters
        );
        $request->perceive_as_http_request = true;
        $result = OkapiServiceRunner::call($service_name, $request);
        self::disable_error_handling();
        return $result;
    }

    /**
     * This works like service_call with two exceptions: 1. It passes all your
     * current HTTP request headers to OKAPI (which can make use of them in
     * terms of caching), 2. It outputs the service response directly, instead
     * of returning it.
     */
    public static function service_display(
        $service_name,
        $user_id_or_null,  # ID of the logged-in user; noone else! See issues #496 and #439.
        $parameters
    ) {
        self::reenable_error_handling();
        $request = new OkapiInternalRequest(
            new OkapiFacadeConsumer(),
            ($user_id_or_null !== null) ? new OkapiFacadeAccessToken($user_id_or_null) : null,
            $parameters
        );
        $request->i_want_OkapiResponse = true;
        $request->perceive_as_http_request = true;
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']))
            $request->etag = $_SERVER['HTTP_IF_NONE_MATCH'];
        $response = OkapiServiceRunner::call($service_name, $request);
        $response->display();
        self::disable_error_handling();
    }

    /**
     * Return ID of currently logged in user or NULL if no user is logged in.
     * OKAPI detects signed-in users by comparing browser's cookies with database
     * user sessions.
     */
    public static function detect_user_id()
    {
        self::reenable_error_handling();
        $user_id = OCSession::get_user_id();
        self::disable_error_handling();
        return $user_id;
    }

    /**
     * Creates a search set from a file, each line containing a cache code.
     * This is very similar to the "services/caches/search/save" method, but
     * allows OC server to include its own result instead of using OKAPI's
     * search options.
     */
    public static function import_search_set_file($search_data_id, $filepath)
    {
        // We need to transform OC's "searchdata" into OKAPI's "search set".
        // First, we need to determine if we ALREADY did that.
        // Note, that this is not exactly thread-efficient. Multiple threads may
        // do this transformation in the same time. However, this is done only once
        // for each searchdata, so we will ignore it.

        self::reenable_error_handling();
        if (!preg_match('/^[a-z0-9_]+$/i', $search_data_id)) {
            throw new \Exception("bad search data id: '".$search_data_id."'");
        }

        $cache_key = "OC_searchdata_" . $search_data_id;
        $set_id = self::cache_get($cache_key);

        if ($set_id === null) {
            // Read the searchdata file into a temporary table.

            if (file_exists($filepath)) {
                $temp_table = "temp_" . $search_data_id;
                Db::execute("
                    create temporary table " . $temp_table . " (
                    cache_id integer primary key
                    ) engine=memory
                ");
                Db::execute("
                    load data local infile '".$filepath."'
                    into table " . $temp_table . "
                    fields terminated by ' '
                    lines terminated by '\\n'
                    (cache_id)
                ");
            } else {
                throw new \Exception("File missing: " . $filepath);
            }

            // Tell OKAPI to import the table into its own internal structures.
            // Cache it for two hours.

            $tables = array('caches', $temp_table);
            $where_conds = array(
                $temp_table.".cache_id = caches.cache_id",
                'caches.status in (1,2,3)',
            );
            $set_info = \okapi\services\caches\search\save\WebService::get_set(
                $tables, array() /* joins */, $where_conds, 7200, 7200
            );
            $set_id = $set_info['set_id'];
            self::cache_set($cache_key, $set_id, 7200);
        }

        self::disable_error_handling();
        return $set_id;
    }

    /**
     * Mark the specified caches as *possibly* modified. The replicate module
     * will scan for changes within these caches on the next changelog update.
     * This is useful in some cases, when OKAPI cannot detect the modification
     * for itself (grep OCPL code for examples). See issue #179.
     *
     * $cache_codes - a single cache code OR an array of cache codes.
     */
    public static function schedule_geocache_check($cache_codes)
    {
        self::reenable_error_handling();
        if (!is_array($cache_codes))
            $cache_codes = array($cache_codes);
        Db::execute("
            update caches
            set okapi_syncbase = now()
            where wp_oc in ('".implode("','", array_map('\okapi\core\Db::escape_string', $cache_codes))."')
        ");
        self::disable_error_handling();
    }

    /**
     * Find all log entries of the specified user for the specified cache and
     * mark them as *possibly* modified. See issue #265.
     *
     * $cache_id - internal ID of the geocache,
     * $user_id - internal ID of the user.
     */
    public static function schedule_user_entries_check($cache_id, $user_id)
    {
        self::reenable_error_handling();
        Db::execute("
            update cache_logs
            set okapi_syncbase = now()
            where
                cache_id = '".Db::escape_string($cache_id)."'
                and user_id = '".Db::escape_string($user_id)."'
        ");
        self::disable_error_handling();
    }

    /**
     * Remove all OAuth Access Tokens bound to a certain user. This method
     * should be called (once) e.g. after a user is banned. It will disable his
     * ability to submit cache logs, etc.
     *
     * Note, that OKAPI will *automatically* remove Access Tokens of banned
     * users, but it will perform this action with a slight delay. This method
     * can be used to do this immediatelly. See #432 for details.
     */
    public static function remove_user_tokens($user_id)
    {
        self::reenable_error_handling();
        Db::execute("
            delete from okapi_tokens
            where user_id = '".Db::escape_string($user_id)."'
        ");
        self::disable_error_handling();
    }

    /**
     * Run OKAPI database update.
     * Will output messages to stdout.
     */
    public static function database_update()
    {
        self::reenable_error_handling();
        views\update\View::call();
        self::disable_error_handling();
    }

    /**
     * Store the object $value in OKAPI's cache, under the name of $key.
     *
     * Parameters:
     *
     * $key -- must be a string of max 57 characters in length (you can use
     *     md5(...) to shorten your keys). Use the same $key to retrieve your
     *     value later.
     *
     * $value -- can be any serializable PHP object. Currently there's no
     *     strict size limit, but try to keep it below 1 MB (for future
     *     compatibility with memcached).
     *
     * $timeout -- *maximum* time allowed to store the value, given in seconds
     *     (however, the value *can* be removed sooner than that, see the note
     *     below). Timeout can be also set to null, but you should avoid this,
     *     because such objects may clutter the cache unnecessarilly. (You must
     *     remember to remove them yourself!)
     *
     * Please note, that this cache is not guaranteed to be persistent.
     * Usually it is, but it can be emptied in case of emergency (low disk
     * space), or if we decide to switch the underlying cache engine in the
     * future (e.g. to memcached). Most of your values should be safe though.
     */
    public static function cache_set($key, $value, $timeout)
    {
        self::reenable_error_handling();
        Cache::set("facade#".$key, $value, $timeout);
        self::disable_error_handling();
    }

    /** Same as `cache_set`, but works on many key->value pair at once. */
    public static function cache_set_many($dict, $timeout)
    {
        self::reenable_error_handling();
        $prefixed_dict = array();
        foreach ($dict as $key => &$value_ref) {
            $prefixed_dict["facade#".$key] = &$value_ref;
        }
        Cache::set_many($prefixed_dict, $timeout);
        self::disable_error_handling();
    }

    /**
     * Retrieve object stored in cache under the name of $key. If object does
     * not exist or its timeout has expired, return null.
     */
    public static function cache_get($key)
    {
        self::reenable_error_handling();
        $value = Cache::get("facade#".$key);
        self::disable_error_handling();
        return $value;
    }

    /** Same as `cache_get`, but it works on multiple keys at once. */
    public static function get_many($keys)
    {
        self::reenable_error_handling();
        $prefixed_keys = array();
        foreach ($keys as $key) {
            $prefixed_keys[] = "facade#".$key;
        }
        $prefixed_result = Cache::get_many($prefixed_keys);
        $result = array();
        foreach ($prefixed_result as $prefixed_key => &$value_ref) {
            $result[substr($prefixed_key, 7)] = &$value_ref;
        }
        self::disable_error_handling();
        return $result;
    }

    /**
     * Delete the entry named $key from the cache.
     */
    public static function cache_delete($key)
    {
        self::reenable_error_handling();
        Cache::delete("facade#".$key);
        self::disable_error_handling();
    }

    /** Same as `cache_delete`, but works on many keys at once. */
    public static function cache_delete_many($keys)
    {
        self::reenable_error_handling();
        $prefixed_keys = array();
        foreach ($keys as $key) {
            $prefixed_keys[] = "facade#".$key;
        }
        Cache::delete_many($prefixed_keys);
        self::disable_error_handling();
    }

    /**
     * Signalling system for complex OC database consistency updates;
     * see class OCPLSignals for more explanation.
     */

    public static function fetch_signals($maxcount)
    {
        self::reenable_error_handling();
        $signals = OCPLSignals::fetch($maxcount);
        self::disable_error_handling();
        return $signals;
    }

    public static function signals_done($signals)
    {
        self::reenable_error_handling();
        OCPLSignals::delete($signals);
        self::disable_error_handling();
    }

    /**
     * Return list of tables whose *content* does not need to be backed up.
     */
    public static function get_temporary_table_names()
    {
        return [
            'okapi_cache', 'okapi_cache_reads',
            'okapi_tile_caches', 'okapi_tile_status',
            'okapi_nonces',
        ];
    }

    /**
     * Switch from Okapi to external error handling
     */
    private static function disable_error_handling()
    {
        OkapiErrorHandler::disable();
    }

    /**
     * Switch from external to Okapi error handling
     */
    private static function reenable_error_handling()
    {
        OkapiErrorHandler::enable();
    }
}
