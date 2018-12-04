<?php

namespace okapi\services\caches\capabilities;

use okapi\core\Cache;
use okapi\core\Db;
use okapi\core\Exception\InvalidParam;
use okapi\core\Okapi;
use okapi\core\Request\OkapiRequest;
use okapi\Settings;

class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 1
        );
    }

    private static $valid_field_names = [
        'types', 'sizes', 'statuses', 'has_ratings', 'languages', 'primary_languages',
        'countries', 'regions', 'can_set_region', 'password_max_length'
    ];

    public static function call(OkapiRequest $request)
    {
        $result = [];

        # evaluate parameters

        $fields = $request->get_parameter('fields');
        if (!$fields) $fields = "types|sizes|statuses|has_ratings";
        $fields = explode("|", $fields);
        foreach ($fields as $field)
            if (!in_array($field, self::$valid_field_names))
                throw new InvalidParam('fields', "'$field' is not a valid field code.");

        $langpref = $request->get_parameter('langpref');
        if (!$langpref) $langpref = "en";
        $langprefs = explode("|", $langpref);

        $cache_type = $request->get_parameter('cache_type');
        if ($cache_type !== null)
            if (!in_array($cache_type, Okapi::get_local_cachetypes()))
                throw new InvalidParam('type', "'".$cache_type."' is not a valid cache type identifier.");

        # calculate results

        if (in_array('types', $fields)) {
            $result['types'] = Okapi::get_local_cachetypes();
        }
        if (in_array('sizes', $fields)) {
            $result['sizes'] = Okapi::get_local_cachesizes($cache_type);
        }
        if (in_array('has_ratings', $fields)) {
            $result['has_ratings'] = (Settings::get('OC_BRANCH') == 'oc.pl');
        }
        if (in_array('languages', $fields)) {
            $result['languages'] = self::get_cl_dict($langprefs, 'languages');
        }
        if (in_array('primary_languages', $fields)) {
            $result['primary_languages'] = self::get_primary_languages();
        }
        if (in_array('countries', $fields)) {
            $result['countries'] = self::get_cl_dict($langprefs, 'countries');
        }
        if (in_array('regions', $fields)) {
            $result['regions'] = self::get_regions();
        }
        if (in_array('can_set_region', $fields)) {
            $result['can_set_region'] = (Settings::get('OC_BRANCH') == 'oc.pl');
        }
        if (in_array('password_max_length', $fields)) {
            if (Settings::get('OC_BRANCH') == 'oc.pl' && $cache_type == 'Traditional')
                $result['password_max_length'] = 0;
            else
                $result['password_max_length'] = Db::field_length('caches', 'logpw') + 0;
        }

        # Done. Return the results.

        return Okapi::formatted_response($request, $result);
    }

    /**
     * The 'countries' and 'languages' tables have basically the same
     * structure, so we use this parametrized function to construct either
     * the countries dictionary or the languages dictionary.
     */
    private static function get_cl_dict($langprefs, $dict_type)
    {
        static $dict = [];

        $cache_key = 'cachecaps/'.$dict_type;
        if (!isset($dict[$dict_type])) {
            $dict[$dict_type] = Cache::get($cache_key);
        }
        if ($dict[$dict_type] === null)
        {
            $dict[$dict_type] = [];

            $table_sql = $dict_type;
            $field_sql = ($dict_type == 'languages' ? 'lower(short)' : 'short');

            if (Settings::get('OC_BRANCH') == 'oc.pl')
            {
                $tmp = Db::select_all("
                    select ".$field_sql." as lang, pl, en, nl
                    from ".$table_sql."
                ");
                foreach ($tmp as $row)
                    foreach (['en', 'nl', 'pl'] as $lang)
                        $dict[$dict_type][$row['lang']][$lang] = $row[$lang];
            }
            else
            {
                $tmp = Db::select_all("
                    select
                        ".$field_sql." as lang,
                        lower(sys_trans_text.lang) trans_lang,
                        ifnull(sys_trans_text.text, ".$table_sql.".name) as name
                    from ".$table_sql."
                    left join sys_trans on ".$table_sql.".trans_id = sys_trans.id
                    left join sys_trans_text on sys_trans.id = sys_trans_text.trans_id
                ");
                foreach ($tmp as $row) {
                    $dict[$dict_type][$row['lang']][$row['trans_lang']] = $row['name'];
                }
            }
            Cache::set($cache_key, $dict[$dict_type], 24 * 3600);
        }

        $localized_dict = [];
        foreach ($dict[$dict_type] as $lang => $trans) {
            $localized_dict[$lang] = Okapi::pick_best_language($trans, $langprefs);
        }
        asort($localized_dict);

        return $localized_dict;
    }

    private static function get_primary_languages()
    {
        static $primary_langs = null;

        $cache_key = 'cachecaps/primary_languages';
        if ($primary_langs === null) {
            $primary_langs = Cache::get($cache_key);
        }
        if ($primary_langs === null)
        {
            # We start with the configured language set of the OC site,
            # then try to detect other significant languages.

            $primary_langs = Settings::get('SITELANGS');

            # Calculate statistics of the languages that are used by the most
            # number of owners of active caches. As estimated from develsite,
            # this query runs << 1 second on the largest site, OCDE.

            $language_stats = Db::select_all("
                select lower(language) as lang, count(distinct caches.user_id) as count
                from cache_desc
                join caches on caches.cache_id = cache_desc.cache_id
                where caches.status = 1
                and caches.node='".Db::escape_string(Settings::get('OC_NODE_ID'))."'
                group by language
                order by count desc, language
            ");
            $total_owners = 0;
            foreach ($language_stats as $row) {
                $total_owners += $row['count'];
            }

            # Do some educated guess of additional significant languages, based
            # on anlaysis of OCDE and OCPL data and estimates for small OC sites.

            if ($total_owners > 0) {
                $threshold = floor(log($total_owners) - 1);
                $primary_langs = [];
                foreach ($language_stats as $row) {
                    if ($row['count'] >= $threshold) {
                        if (!in_array($row['lang'], $primary_langs)) { 
                            $primary_langs[] = $row['lang'];
                        }
                    } else {
                        break;
                    }
                }
            }

            # The more owners, the less volatile will the language statistics be.
            # Therefore we calculate the cache timeout from the owner count.
            #
            # ~10.000 OCDE owners with active geocaches => ~1 month cache timeout

            Cache::set($cache_key, $primary_langs, $total_owners * 250);
        }
        return $primary_langs;
    }

    private static function get_regions()
    {
        if (Settings::get('OC_BRANCH') == 'oc.pl')
        {
            # OCPL has one regional level, which is identical to NUTS level 3.
            # This fast query is not worth caching.

            $regions = Db::select_all("
                select code, name
                from nuts_codes nc
                join countries c on c.short = left(nc.code,2)  /* filter out unknown countries */
                where code like '____'
                order by code
            ");
        }
        else
        {
            # OCDE has two regional levels. The corresponding NUTS levels are
            # selected by the countries.adm_display2 and adm_display3 values.

            # But as long as we don't implement searching for regions, we don't
            # need OCDE regions, so we return null.

            return null;
            /*
            $regions = Db::select_all("
                select nc.code, nc.name
                from nuts_codes nc
                join countries c on c.short = left(nc.code, 2)
                where code like
                    IF(c.adm_display2 = 4, '_____', IF(c.adm_display2 = 3, '____', '___'))
                )
                order by code
            ");
            */
        }

        $result  = [];
        foreach ($regions as $r)
            if (substr($r['code'], -1) != 'Z')      # In OCDE DB there are "...Z" dummy entries.
                $result[substr($r['code'], 0, 2)][$r['code']] = $r['name'];

        return $result;
    }
}
