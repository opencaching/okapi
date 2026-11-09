<?php

namespace okapi\services\lists;

use okapi\core\Db;
use okapi\core\Exception\BadRequest;
use okapi\core\Exception\InvalidParam;
use okapi\Settings;

/**
 * Shared by the services/lists methods: the installation check, the ownership check, and the
 * rules the website applies to its own list form (htdocs/lib2/logic/cachelist.class.php).
 */
class ListsCommon
{
    const MAX_CODES = 500;

    public static function require_lists()
    {
        if (Settings::get('OC_BRANCH') != 'oc.de')
            throw new BadRequest('This method is not supported in this OKAPI installation. See the has_lists field in services/apisrv/installation method.');
    }

    /** The list's id, after checking that it is a number and belongs to the user. */
    public static function own_list_id($list_id, $user_id)
    {
        if ($list_id === null || $list_id === '')
            throw new InvalidParam('list_id', 'Missing.');
        if (!ctype_digit((string)$list_id))
            throw new InvalidParam('list_id', 'Must be a number.');
        $found = Db::select_value("
            select id
            from cache_lists
            where id = '".Db::escape_string($list_id)."'
              and user_id = '".Db::escape_string($user_id)."'
        ");
        if (!$found)
            throw new InvalidParam('list_id', 'The specified list does not exist or does not belong to you.');
        return (int)$found;
    }

    /** list_status: 0 private, 2 public, 3 public and shown in cache listings (the website's visibility). */
    public static function parse_status($value)
    {
        if (!in_array((string)$value, array('0', '2', '3'), true))
            throw new InvalidParam('list_status', "Must be 0, 2 or 3.");
        return (int)$value;
    }

    public static function parse_bool($name, $value)
    {
        if (in_array($value, array('true', '1'), true)) return true;
        if (in_array($value, array('false', '0'), true)) return false;
        throw new InvalidParam($name, "Must be 'true' or 'false'.");
    }

    /**
     * The website's name rules: not empty, at most 80 characters, unique among the user's lists,
     * and at least 10 characters for a public list.
     */
    public static function check_name($name, $status, $user_id, $except_list_id = 0)
    {
        if ($name === '')
            throw new InvalidParam('list_name', 'Must not be empty.');
        if (mb_strlen($name, 'UTF-8') > 80)
            throw new InvalidParam('list_name', 'At most 80 characters.');
        if ($status >= 2 && mb_strlen($name, 'UTF-8') < 10)
            throw new InvalidParam('list_name', 'A public list needs a name of at least 10 characters.');
        $other = Db::select_value("
            select id
            from cache_lists
            where user_id = '".Db::escape_string($user_id)."'
              and id <> '".Db::escape_string($except_list_id)."'
              and name = '".Db::escape_string($name)."'
        ");
        if ($other)
            throw new InvalidParam('list_name', 'You already have a list with this name.');
    }

    /**
     * list_description is plain text. The website stores list descriptions as (purified) HTML,
     * so the text is escaped and its line breaks become <br />.
     */
    public static function description_html($text)
    {
        $text = str_replace(array("\r\n", "\r"), "\n", trim($text));
        return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), true);
    }

    public static function check_password($password)
    {
        if (mb_strlen($password, 'UTF-8') > 80)
            throw new InvalidParam('list_password', 'At most 80 characters.');
        return $password;
    }

    /** Pipe-separated cache codes: at least one, at most MAX_CODES, duplicates dropped. */
    public static function parse_codes($cache_codes)
    {
        if ($cache_codes === null || $cache_codes === '')
            throw new InvalidParam('cache_codes', 'Missing.');
        $codes = array_values(array_unique(array_filter(array_map('trim', explode('|', $cache_codes)), 'strlen')));
        if (count($codes) == 0)
            throw new InvalidParam('cache_codes', 'Missing.');
        if (count($codes) > self::MAX_CODES)
            throw new InvalidParam('cache_codes', 'At most '.self::MAX_CODES.' cache codes per request.');
        return $codes;
    }

    /**
     * The ids of the caches with these codes; with $visible_only only the ones OKAPI shows.
     * Looked up separately because OCDE's triggers on cache_list_items write to caches, and MySQL
     * refuses that inside a statement that reads caches (error 1442).
     */
    public static function cache_ids($codes, $visible_only)
    {
        return Db::select_column("
            select cache_id
            from caches
            where wp_oc in ('".implode("','", array_map('\okapi\core\Db::escape_string', $codes))."')
            ".($visible_only ? "and status in (1, 2, 3)" : "")."
        ");
    }

    /**
     * The user's lists in the structure of services/lists/query (all of them, or the one with $only_id).
     * $with_caches adds the codes of the caches on each list.
     */
    public static function get_lists($user_id, $only_id = null, $with_caches = false)
    {
        $rs = Db::query("
            select
                cl.id, cl.name, cl.description, cl.is_public, cl.password,
                cl.date_created, cl.last_modified, cl.last_added,
                (select count(*) from cache_list_items cli where cli.cache_list_id = cl.id) as caches_count,
                (select count(*) from cache_list_watches clw where clw.cache_list_id = cl.id) as watches_count,
                (select count(*) from cache_list_watches clw where clw.cache_list_id = cl.id
                    and clw.user_id = '".Db::escape_string($user_id)."') as watched_by_me
            from cache_lists cl
            where cl.user_id = '".Db::escape_string($user_id)."'
            ".($only_id !== null ? "and cl.id = '".Db::escape_string($only_id)."'" : "")."
            order by cl.name
        ");
        $lists = array();
        while ($row = Db::fetch_assoc($rs))
        {
            $lists[] = array(
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'description' => $row['description'],
                'status' => (int)$row['is_public'],
                'password' => $row['is_public'] == 0 ? $row['password'] : '',
                'is_watched' => $row['watched_by_me'] > 0,
                'date_created' => $row['date_created'],
                'last_modified' => $row['last_modified'],
                'last_added' => $row['last_added'],
                'caches_count' => (int)$row['caches_count'],
                'watches_count' => (int)$row['watches_count'],
            );
        }
        if ($with_caches && count($lists) > 0)
        {
            $ids = array_map(function ($l) { return $l['id']; }, $lists);
            $rs = Db::query("
                select cli.cache_list_id, c.wp_oc
                from cache_list_items cli
                join caches c on c.cache_id = cli.cache_id
                where cli.cache_list_id in ('".implode("','", array_map('intval', $ids))."')
                order by c.wp_oc
            ");
            $codes = array();
            while ($row = Db::fetch_assoc($rs))
                $codes[$row['cache_list_id']][] = $row['wp_oc'];
            foreach ($lists as &$list)
                $list['cache_codes'] = isset($codes[$list['id']]) ? $codes[$list['id']] : array();
            unset($list);
        }
        return $lists;
    }
}
