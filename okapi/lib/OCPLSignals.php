<?php

namespace okapi\lib;

use okapi\core\Db;
use okapi\Settings;

/**
 * This mechanism works around the problem that OCPL database needs lots of
 * manual housekeeping for statistics etc. We store signals into a table,
 * which will be processed by an OCPL cronjob.
 *
 * Some of these signals may become obsolete by implementing automatical
 * DB consistency in OCPL code.
 */

class OCPLSignals
{
    private static $signal_types = [
        'log-merit-badges' => 1,
        'cache-altitude' => 2,
    ];

    /** update merit badges after cache was found, or 'found' log disappeared **/
    public static function update_merit_badges($cache_id, $user_id)
    {
        if (Settings::get('OC_BRANCH') == 'oc.pl')
            self::create('log-merit-badges', ['cache_id' => $cache_id, 'user_id' => $user_id]);
    }

    /** update cache altutude after coords have changed **/
    public static function cache_location_changed($cache_id)
    {
        if (Settings::get('OC_BRANCH') == 'oc.pl')
            self::create('cache-altitude', ['cache_id' => $cache_id]);
    }

    /** create a new signal **/
    private static function create($signal_type, $payload)
    {
        if (!isset(self::$signal_types[$signal_type])) {
            throw new Exception("tried to add signal of undefined type '".$signal_type."'");
        }
        Db::execute("
            insert into okapi_signals (type, payload, created_at)
            values (
                ".self::$signal_types[$signal_type].",
                '".Db::escape_string(serialize($payload))."',
                now()
            )
        ");
    }

    /**
     * Reserves a bunch of signals for processing and returns them. If not
     * processed within one hour, signals are released for another try.
     */
    public static function fetch($maxcount)
    {
        static $reverse_types = null;

        Db::execute("start transaction");

        # The signal processing is designed to run concurrently, i.e. in
        # multiple instances of the same OC cronjob. This can speed up jobs
        # like the altitude calculation, which block on request of external
        # data. The "... for update" locks the selected rows until commit
        # and thus ensures that they cannot be fetched by a concurrent thread:

        $signals = Db::select_all("
            select id, type, payload
            from okapi_signals
            where fetched_at is null or timestampdiff(minute, fetched_at, now()) >= 60
            order by id
            limit ".($maxcount + 0)."
            for update
        ");
        if ($signals)
        {
            Db::execute("
                update okapi_signals
                set fetched_at = now()
                where id in (".implode(
                    ",",
                    array_map(function($t) { return $t['id']; }, $signals)
                ).")
            ");
            if (!$reverse_types) {
                $reverse_types = array_flip(self::$signal_types);
            }
            foreach ($signals as &$signal) {
                $signal['type'] = $reverse_types[$signal['type']];
                $signal['payload'] = unserialize($signal['payload']);
            }
        }
        Db::execute("commit");
        return $signals;
    }

    /**
     * removes processed signals from the database
     */
    public static function delete($signals)
    {
        Db::execute("
            delete from okapi_signals
            where id in (".implode(
                ",",
                array_map(function($t) { return $t['id']; }, $signals)
            ).")
        ");
    }

    /** check if the OCPL cronjob is working **/
    public static function are_overdue($hours)
    {
        return Db::select("
            select 1 from okapi_signals
            where timestampdiff(hour, created_at, now()) >= '".Db::escape_string($hours)."'
            order by id
            limit 1
        ") != 0;
    }

    /** diagnostics **/
    public static function dump()
    {
        $all = Db::select_all("select * from okapi_signals order by id desc limit 100");
        foreach ($all as &$signal) {
            $signal['payload'] = unserialize($signal['payload']);
        }
        ob_start();
        var_dump($all);
        return ob_get_clean();
    }
}
