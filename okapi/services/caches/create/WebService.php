<?php

namespace okapi\services\caches\create;

use okapi\core\Db;
use okapi\core\Exception\InvalidParam;
use okapi\core\Okapi;
use okapi\core\Request\OkapiRequest;
use okapi\services\caches\CacheEditCommon;
use okapi\Settings;

class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 3
        );
    }

    public static function call(OkapiRequest $request)
    {
        # Writes the OCDE database layout; other installations would fail half-way.
        CacheEditCommon::require_cache_editing();

        $user_id = $request->token->user_id;

        # Required parameters

        $cache_name = CacheEditCommon::parse_text('cache_name',
            (string)$request->get_parameter('cache_name'), 'caches', 'name', false);

        $cache_type = $request->get_parameter('cache_type');
        if (!$cache_type)
            throw new InvalidParam('cache_type', 'cache_type is mandatory.');
        try {
            $type_id = Okapi::cache_type_name2id($cache_type);
        } catch (\Exception $e) {
            throw new InvalidParam('cache_type', "Unknown cache type '$cache_type'.");
        }

        list($latitude, $longitude) = CacheEditCommon::parse_location(
            $request->get_parameter('latitude'), $request->get_parameter('longitude'));

        # Required on the website too
        foreach (['difficulty', 'terrain', 'size2'] as $name)
            if ($request->get_parameter($name) === null)
                throw new InvalidParam($name, "$name is mandatory.");
        $diff_int = CacheEditCommon::parse_rating('difficulty', $request->get_parameter('difficulty'));
        $terr_int = CacheEditCommon::parse_rating('terrain', $request->get_parameter('terrain'));
        $size_int = CacheEditCommon::parse_size($request->get_parameter('size2'));

        # Optional parameters

        $short_desc = CacheEditCommon::parse_text('short_description',
            (string)$request->get_parameter('short_description'), 'cache_desc', 'short_desc', true);

        # Plain text only; descriptions with formatting are written on the website.
        $description = CacheEditCommon::encode_description((string)$request->get_parameter('description'));
        $desc_html = 1;

        $hint = CacheEditCommon::encode_hint(trim((string)$request->get_parameter('hint2')));

        $passwd = (string)$request->get_parameter('passwd');
        if (strlen($passwd) > Db::field_length('caches', 'logpw'))
            throw new InvalidParam('passwd', 'Password too long.');

        $desc_lang = strtoupper($request->get_parameter('desc_lang') ?: 'EN');
        if (!Db::select_value("select 1 from languages where short = '".Db::escape_string($desc_lang)."'"))
            throw new InvalidParam('desc_lang', "Unknown language '$desc_lang'.");

        $country = $request->get_parameter('country');
        if ($country === null)
            $country = Db::select_value("select country from user where user_id = '".Db::escape_string($user_id)."'");
        $country = strtoupper((string)$country);
        if (!Db::select_value("select 1 from countries where short = '".Db::escape_string($country)."'"))
            throw new InvalidParam('country', $country === '' ? 'country is mandatory, because your profile has none.' : "Unknown country '$country'.");

        $date_hidden = $request->get_parameter('date_hidden') ?: date('Y-m-d');
        $d = \DateTime::createFromFormat('!Y-m-d', $date_hidden);
        if (!$d || $d->format('Y-m-d') !== $date_hidden)
            throw new InvalidParam('date_hidden', "Must be a date like '2026-10-20'.");

        $waypoints = [];
        $alt_wpts = $request->get_parameter('alt_wpts');
        if ($alt_wpts !== null && $alt_wpts !== '')
            $waypoints = CacheEditCommon::parse_waypoints('alt_wpts', $alt_wpts);

        # Publishing, as the choices of the website's form: now, later (at a
        # given day and full hour; the website publishes it then), or not now
        # (the owner publishes it on the website). Not published = status 5.
        $publish = $request->get_parameter('publish') ?: 'now';
        if (!in_array($publish, ['now', 'later', 'notnow']))
            throw new InvalidParam('publish', "Must be 'now', 'later' or 'notnow'.");
        $activation_date = null;
        if ($publish == 'later') {
            $activate_on = $request->get_parameter('activate_on');
            $a = $activate_on ? \DateTime::createFromFormat('!Y-m-d', $activate_on) : false;
            if (!$a || $a->format('Y-m-d') !== $activate_on)
                throw new InvalidParam('activate_on', "With publish=later, a date like '2026-11-01' is mandatory.");
            $activate_hour = $request->get_parameter('activate_hour') ?: '0';
            if (!ctype_digit((string)$activate_hour) || $activate_hour > 23)
                throw new InvalidParam('activate_hour', 'Must be a full hour, 0 to 23.');
            $activation_date = sprintf('%s %02d:00:00', $activate_on, $activate_hour);
        }

        # The website's date rules (newcache.php): when published now or later,
        # an event must not be before its publication, and any other cache must
        # be hidden by then. The website compares the day of date_hidden (0:00)
        # with the time of publication (now, or 0:00 of the activation day).
        if ($publish != 'notnow') {
            $hidden_ts = strtotime($date_hidden.' 00:00:00');
            $publish_ts = ($publish == 'later') ? strtotime($activate_on.' 00:00:00') : time();
            if ($cache_type == 'Event' && $hidden_ts < $publish_ts)
                throw new InvalidParam('date_hidden', 'The event must happen after publish.');
            if ($cache_type != 'Event' && $hidden_ts > $publish_ts)
                throw new InvalidParam('date_hidden', 'The cache must be hidden before publish.');
        }
        $status = ($publish == 'now') ? Okapi::cache_status_name2id('Available') : 5;

        # As on the website: no second active cache at the same coordinates.
        $duplicate = Db::select_value("
            select min(wp_oc) from caches
            where status = 1
            and round(longitude, 6) = round('".Db::escape_string($longitude)."', 6)
            and round(latitude, 6) = round('".Db::escape_string($latitude)."', 6)
        ");
        if ($duplicate)
            throw new InvalidParam('latitude', "Another cache ($duplicate) exists at these coordinates.");

        $node = Settings::get('OC_NODE_ID');

        Db::execute('start transaction');

        try {
            # Same columns as the website's newcache.php. The database triggers set
            # uuid, wp_oc (from the waypoint pool), date_created, last_modified,
            # listing_last_modified, the search index and cache_coordinates. The
            # columns after `node` have no default, and OKAPI's connection runs
            # in strict SQL mode, so they are given here.
            Db::execute("
                insert into caches
                    (user_id, name, longitude, latitude, type, status, country,
                     date_hidden, date_activate, size, difficulty, terrain,
                     logpw, search_time, way_length, wp_gc, node,
                     meta_last_modified, wp_gc_maintained, wp_nc, desc_languages,
                     default_desclang, show_cachelists, protect_old_coords,
                     needs_maintenance, listing_outdated, flags_last_modified)
                values (
                    '".Db::escape_string($user_id)."',
                    '".Db::escape_string($cache_name)."',
                    '".Db::escape_string($longitude)."',
                    '".Db::escape_string($latitude)."',
                    '".Db::escape_string($type_id)."',
                    '".Db::escape_string($status)."',
                    '".Db::escape_string($country)."',
                    '".Db::escape_string($date_hidden)."',
                    ".($activation_date === null ? "null" : "'".Db::escape_string($activation_date)."'").",
                    '".Db::escape_string($size_int)."',
                    '".Db::escape_string($diff_int)."',
                    '".Db::escape_string($terr_int)."',
                    '".Db::escape_string($passwd)."',
                    0, 0, '',
                    '".Db::escape_string($node)."',
                    now(), '', '', '',
                    '".Db::escape_string($desc_lang)."', 1, 0,
                    0, 0, now()
                )
            ");
            $cache_id = Db::last_insert_id();
            $cache_code = Db::select_value("
                select wp_oc from caches where cache_id = '".Db::escape_string($cache_id)."'
            ");

            Db::execute("
                insert into cache_desc
                    (cache_id, language, `desc`, desc_html, hint, short_desc,
                     last_modified, desc_htmledit, node)
                values (
                    '".Db::escape_string($cache_id)."',
                    '".Db::escape_string($desc_lang)."',
                    '".Db::escape_string($description)."',
                    '".Db::escape_string($desc_html)."',
                    '".Db::escape_string($hint)."',
                    '".Db::escape_string($short_desc)."',
                    now(), 0,
                    '".Db::escape_string($node)."'
                )
            ");

            CacheEditCommon::insert_waypoints($cache_id, $waypoints);

            Db::execute("commit");
        } catch (\Exception $e) {
            Db::execute("rollback");
            throw $e;
        }

        $result = array(
            'cache_code' => $cache_code,
            'url' => Settings::get('SITE_URL')."viewcache.php?wp=".$cache_code,
        );
        return Okapi::formatted_response($request, $result);
    }
}
