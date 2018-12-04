<?php

namespace okapi\services\caches\edit;

use Exception;
use okapi\core\Okapi;
use okapi\core\Db;
use okapi\core\Exception\BadRequest;
use okapi\core\Exception\InvalidParam;
use okapi\core\Exception\ParamMissing;
use okapi\core\Request\OkapiRequest;
use okapi\core\Request\OkapiInternalRequest;
use okapi\core\OkapiServiceRunner;
use okapi\lib\OCPLSignals;
use okapi\Settings;
use okapi\services\attrs\AttrHelper;

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
        $cache_code = $request->get_parameter('cache_code');
        if ($cache_code == null) {
            throw new ParamMissing('cache_code');
        }
        $cache = OkapiServiceRunner::call(
            'services/caches/geocache',
            new OkapiInternalRequest(
                $request->consumer,
                $request->token,
                array(
                    'cache_code' => $cache_code,
                    'langpref' => $request->get_parameter('language'),
                    'fields' => 'internal_id|location|country_code|region_code|type|size2|difficulty|terrain|trip_time|trip_distance|attr_acodes|names|name|date_created|req_passwd|gc_code'
                )
            )
        );
        $cache_internal_id_escaped = Db::escape_string($cache['internal_id']);
        $cache_internal = Db::select_row("
            select node, user_id from caches where cache_id='".$cache_internal_id_escaped."'
        ");
        if ($cache_internal['node'] != Settings::get('OC_NODE_ID')) {
            throw new Exception(
                "This site's database contains the geocache '$cache_code' which has been"
                . " imported from another OC node. OKAPI is not prepared for that."
            );
        }
        if ($cache_internal['user_id'] != $request->token->user_id) {
            throw new InvalidParam('cache_code', "Only own caches may be edited.");
        }

        $problems = [];
        $change_sqls_escaped = [];

        $langpref = $request->get_parameter('langpref');
        if (!$langpref) $langpref = "en";
        $langprefs = explode("|", $langpref);

        Okapi::gettext_domain_init($langprefs);
        try
        {
            # name

            $name = $request->get_parameter('name');
            if ($name !== null) {
                $old_name = $request->get_parameter('old_name');
                if (!$old_name)
                    throw new ParamMissing('old_name');
                elseif (count($cache['names']) != 1)
                    throw new Exception("Unexpected cache name count");
                elseif ($old_name != $cache['name'])
                    throw new InvalidParam('old_name', "'".$old_name."' does not match the cache name ('".$cache['name']."').");
                elseif (trim($name) == '')
                    $problems['name'] = _("The cache name must not be empty.");
                elseif ($name != $cache['name'])
                    $change_sqls_escaped[] = "name = '".Db::escape_string(trim($name))."'";
            }

            # type

            $type = $request->get_parameter('type');
            if ($type !== null) {
                if (!in_array($type, Okapi::get_local_cachetypes()))
                    throw new InvalidParam('type', "'".$type."' is not a valid cache type (at this OC site).");
                elseif ($type != $cache['type'])
                    $change_sqls_escaped[] = "type = ".Okapi::cache_type_name2id($type);
            } else {
                $type = $cache['type'];
            }

            # query capabilities -- DEPENDS ON TYPE

            $capabilites_needed = [
                'country_code' => ['countries', 'regions'],
                'region_code' => ['countries', 'regions'],
                'type' => ['sizes'],
                'size2' => ['sizes'],
                'description' => ['languages'],
                'short_description' => ['languages'],
                'hint2' => ['languages'],
                'passwd' => ['password_max_length'],
            ];
            $cap_fields = [];
            foreach ($request->get_all_parameters_including_unknown() as $param => $dummy) {
                if (isset($capabilites_needed[$param]))
                    foreach ($capabilites_needed[$param] as $field)
                        $cap_fields[$field] = true;
            }
            $capabilities = OkapiServiceRunner::call(
                'services/caches/capabilities',
                new OkapiInternalRequest(
                    $request->consumer,
                    $request->token,
                    [
                        'cache_type' => $type,
                        'langpref' => $langpref,
                            # This determines the language for cache_location.adm1,
                            # if the country is changed. It's weird to derive it from
                            # the langpref, but 1. that's what OC code does, and 2.
                            # the field is obsolete anyway; see comments below.
                        'fields' => implode('|', array_keys($cap_fields))
                    ]
                )
            );
            unset($cap_fields);
            unset($capabilites_needed);

            # location

            $location = $request->get_parameter('location');
            $location_update = false;
            if ($location !== null)
            {
                $coords = Okapi::parse_location($location);
                if ($coords === null)
                    throw new InvalidParam('location', "'".$location."' is no valid 'lat|lon' pair.");
                elseif ($coords[0] < -90 || $coords[0] > 90)
                    $problems['location'] = _("Latitude degrees must range between -90 and 90.");
                elseif ($coords[1] < -180 || $coords[1] > 180)
                    $problems['location'] = _("Longitude degrees must range between -180 and 180.");
                else if ($coords[0] == 0 && $coords[1] == 0)
                    $problems['location'] = _("Coordinates must not be 0/0.");   # common error
                else {
                    $old_coords = Okapi::parse_location($cache['location']);
                    if ($coords != $old_coords) {
                        $change_sqls_escaped[] = "
                            latitude = ".Db::float_sql($coords[0]).",
                            longitude = ".Db::float_sql($coords[1])."
                        ";
                        $location_update = true;
                    }
                }
                unset($old_coords);
                unset($coords);
            }

            # country and region

            $country_or_region_change = false;

            $country_code = $request->get_parameter('country_code');
            if ($country_code !== null)
            {
                if ($country_code != $cache['country_code']) {
                    if (!in_array($country_code, array_keys($capabilities['countries']))) {
                        throw new InvalidParam('country', "'".$country_code."' is no valid country code.");
                    }
                    $change_sqls_escaped[] = "country = '".Db::escape_string($country_code)."'";
                    $country_or_region_change = true;
                }
            }
            else
            {
                $country_code = $cache['country_code'];
            }

            if (Settings::get('OC_BRANCH') == 'oc.pl')
            {
                $region_code = $request->get_parameter('region_code');
                if ($region_code !== null)
                {
                    if ($region_code == 'null') {
                        $region_code = null;
                    } else if ($region_code != $cache['region_code']) {

                        if (!isset($capabilities['regions'][substr($region_code, 0, 2)][$region_code]))
                            throw new InvalidParam('region', "'".$region_code."' is no valid region code.");
                        $effective_country_code =
                            ($country_code !== null ? $country_code : $cache['country_code']);
                        if (substr($region_code, 0, 2) != $effective_country_code) {
                            throw new InvalidParam(
                                'region',
                                "'".$region_code."' is not a valid region code for this country."
                            );
                        }
                    }
                }
                else
                {
                    $region_code = $cache['region_code'];

                    # If the cache country is changed without providing a new region code,
                    # then we either assign the sole region code for the country, or clear
                    # the region.

                    if ($country_code !== null && $country_code != $cache['country_code'])
                    {
                        if (isset($capabilities['regions'][$country_code])
                            && count($capabilities['regions'][$country_code]) == 1
                        ) {
                            reset($capabilities['regions'][$country_code]);
                            $region_code = key($capabilities['regions'][$country_code]);
                        } else {
                            $region_code = null;
                        }
                    }
                }
                if ($region_code !== $cache['region_code']) {
                    $country_or_region_change = true;
                }
            }

            # $country_code and $region_code now both have the correct values for
            # storing them into the 'cache_location' table.

            # size2 -- DEPENDS ON TYPE

            $size2 = $request->get_parameter('size2');
            if ($size2 !== null)
            {
                # We allow to confirm an existing size, even if it is no longer
                # available for the cache's type. OC websites do the same.

                if ($size2 != $cache['size2']) {
                    if (!in_array($size2, Okapi::get_local_cachesizes()))
                        throw new InvalidParam('size2', "'".$size2."' is not a valid cache size (at this OC site).");
                    elseif (!in_array($size2, $capabilities['sizes']))
                        $problems['size2'] = _("This size is not available for this type of cache.");
                    else
                        $change_sqls_escaped[] = "size = ".Okapi::cache_size2_to_sizeid($size2);
                }
            }
            elseif ($type !== $cache['type'] && !in_array($cache['size2'], $capabilities['sizes']))
            {
                if (count($capabilities['sizes']) == 1) {
                    # Enforce the only valid cache size after a type change.
                    $change_sqls_escaped[] = "size = ".Okapi::cache_size2_to_sizeid($capabilities['sizes'][0]);
                } else {
                    $problems['type'] = _("Cache type does not match cache size.");
                }
            }

            # difficulty, terrain

            foreach (['difficulty', 'terrain'] as $property)
            {
                $tmp = $request->get_parameter($property);
                if ($tmp !== null)
                {
                    if (!preg_match('/^[0-9](\.[05]0*)?$/', $tmp))
                        throw new InvalidParam($property, "'".$tmp."' is not a valid ".$property." value");
                    elseif (!in_array($tmp, [1, 1.5, 2, 2.5, 3, 3.5, 4, 4.5, 5]))
                        throw new InvalidParam($property, "'".$tmp."' is not a valid ".$property." value");
                    elseif (2 * $tmp != $cache[$property])
                        $change_sqls_escaped[] = $property." = ".(2 * $tmp);
                }
            }

            # trip_time, trip_distance

            foreach (
                ['trip_time' => 'search_time', 'trip_distance' => 'way_length']
                as $property => $db_field_sql
            ) {
                $tmp = $request->get_parameter($property);
                if ($tmp !== null)
                {
                    # OC websites can handle numbers >= 0.01 for both properties,
                    # so we accept only those. Also impose some reasonable upper limit
                    # (but retain higher values that were entered on the OC website).

                    $max_value = max($cache[$property], $property == 'trip_time' ? 999 : 99999);

                    if (!preg_match('/^(null|[0-9]+\.?[0-9]*)$/', $tmp)) {
                        throw new InvalidParam($property, "'".$tmp."' is not a valid ".$property." value");
                    } elseif ($tmp != 'null' && ($tmp < 0.01 || $tmp > $max_value)) {
                        $problems[$property] = (
                            $property == 'trip_time'
                            ? sprintf(_("Invalid trip time; must range between 1 minute and %d hours."), $max_value)
                            : sprintf(_("Invalid trip distance; must range between 0.01 and %d km."), $max_value)
                        );
                    } else {
                        $change_sqls_escaped[] = $db_field_sql." = ".Db::float_sql($tmp + 0);  # 'null' => 0
                    }
                    unset($max_value);
                }
            }

            # passwd -- DEPENDS ON TYPE

            $passwd = $request->get_parameter('passwd');
            if ($passwd !== null)
            {
                if (
                    $passwd != '' &&
                    Settings::get('OC_BRANCH') == 'oc.pl' &&
                    $type == 'Traditional' &&
                    $cache['date_created'] > '2010-06-18 20:03:18'
                ) {
                    # We won't bother the user with the creation date thing here.
                    # The *current* rule is that OCPL sites do not allow tradi passwords.
                    # For older caches, the user won't see this message.

                    $problems['passwd'] = sprintf(
                        _('%s does not allow log passwords for traditional caches.'),
                        Okapi::get_normalized_site_name()
                    );
                } elseif (strlen($passwd) > $capabilities['password_max_length']) {
                    $problems['passwd'] = sprintf(_(
                        'The password must not be longer than %d characters.'),
                        $capabilities['password_max_length']
                    );
                } else {
                    # There are passwords in OC databases that only consist of space(s).
                    # OCPL still allows to enter them. We retain those old passwords, but
                    # will trim any new password.

                    $oldpw = Db::select_value("select logpw from caches where cache_id='".$cache_internal_id_escaped."'");
                    if ($passwd != $oldpw)
                        $change_sqls_escaped[] = "logpw = '".Db::escape_string(trim($passwd))."'";
                    unset($oldpw);
                }
            } elseif (
                $cache['req_passwd'] != '' &&
                Settings::get('OC_BRANCH') == 'oc.pl' &&
                $type != $cache['type'] && $type == 'Traditional'
            ) {
                $problems['type'] = sprintf(
                    _('%s does not allow log passwords for traditional caches.'),
                    Okapi::get_normalized_site_name()
                ) . " "._("Please choose another cache type or remove the password.");
            }

            # gc_code

            $gc_code = $request->get_parameter('gc_code');
            if ($gc_code !== null)
            {
                if ($gc_code == '') {
                    throw new InvalidParam(
                        'gc_code',
                        "Must not be empty. Supply 'null' if you want to remove the GC code."
                    );
                }
                if ($gc_code == 'null')
                    $gc_code = '';

                # Correct frequent misspelling.
                $gc_code = str_replace('O', '0', $gc_code);

                if (!preg_match('/^(|^GC[0-9A-HJKMNPQRTV-Z]{2,})$/', $gc_code))
                    $problems['gc_code'] = _("Invalid GC code");
                elseif ($gc_code != $cache['gc_code'])
                    $change_sqls_escaped[] = "wp_gc = '".Db::escape_string($gc_code)."'";
            }

            # attr_acodes

            # The add/remove logic ensures that we will not remove local attributes
            # that are not available (yet) in OKAPI. It also prevents accidential
            # deletions.

            $acodes_to_remove = [];
            $acodes_to_add = [];

            $attr_acodes = $request->get_parameter('attr_acodes');
            if ($attr_acodes)   # empty list is allowed
            {
                $attr_problems = [];
                $available_acodes = OkapiServiceRunner::call(
                    'services/attrs/attribute_index',
                    new OkapiInternalRequest(
                        $request->consumer,
                        $request->token,
                        [
                            'fields' => 'name|is_addable|incompatible_acodes',
                            'only_locally_used' => 'true',
                            'langpref' => $langpref
                        ]
                    )
                );
                foreach (explode('|', $attr_acodes) as $acode)
                {
                    $remove = (substr($acode, 0, 1) == '-');
                    if ($remove)
                        $acode = substr($acode, 1);
                    if (!isset($available_acodes[$acode]))
                        throw new InvalidParam('attr_acodes', "'".$acode."' is not a valid A-code (at this OC site).");
                    if ($remove)
                        $acodes_to_remove[] = $acode;
                    elseif ($available_acodes[$acode]['is_addable'])
                        $acodes_to_add[] = $acode;
                    else {
                        $attr_problems[] = sprintf(
                            _("The attribute '%s' can no longer be added to %s caches."),
                            $available_acodes[$acode]['name'],
                            Okapi::get_normalized_site_name()
                        );
                    }
                }
                unset($remove);
                unset($acode);

                # test for conflicting attributes

                if ($tmp = array_intersect($acodes_to_remove, $acodes_to_add)) {
                    throw new InvalidParam(
                        'attr_acodes',
                        "You tried to add AND remove the attribute(s) ".implode(' and ', $tmp)
                    );
                }
                $effective_acodes = array_merge(
                    array_diff($cache['attr_acodes'], $acodes_to_remove),
                    $acodes_to_add
                );
                $already_warned = [];
                foreach ($acodes_to_add as $acode)
                    foreach ($available_acodes[$acode]['incompatible_acodes'] as $incompatible)
                        if (in_array($incompatible, $effective_acodes) &&
                            !isset($already_warned[$incompatible.$acode])
                        ) {
                            $attr_problems[] = sprintf(
                                _("The attributes '%s' and '%s' contradict."),
                                $available_acodes[$acode]['name'],
                                $available_acodes[$incompatible]['name']
                            );
                            # Attribute incompatibility is mutual. Take care that we
                            # don't inform the user *twice* for the same attribute pair:
                            $already_warned[$acode.$incompatible] = true;
                        }
                unset($already_warned);
                unset($effective_acodes);

                if ($attr_problems) {
                    $problems['attr_acodes'] = implode("\n", $attr_problems);
                }
                unset($attr_problems);
            }

            # TODO: Detect conflicting attribute/cachetype combinations.
            # OC websites don't do it, but it would improve content quality.

            # description, short_description and hint2

            $description = $request->get_parameter('description');
            $short_description = $request->get_parameter('short_description');
            $hint2 = $request->get_parameter('hint2');
            $desc_params_given = ($description !== null || $short_description !== null || $hint2 !== null);

            if ($desc_params_given)
            {
                $language = $request->get_parameter('language');
                if ($language === null)
                    throw new ParamMissing('language');
                if (!isset($capabilities['languages'][$language]))
                    throw new InvalidParam('language', "'".$language."' is not a valid language code.");

                # purify/trim/encode texts

                if ($description != '') {
                    list($description, $value_for_desc_html_field) = Okapi::purify_html($description);
                } else {
                    # This avoid to change null => '', and to make the assumption
                    # purify_html('') == ''.

                    list($dummy, $value_for_desc_html_field) = Okapi::purify_html('');
                }
                if ($short_description != '') {
                    $short_description = preg_replace('/[\r\n\t]+/', ' ', $short_description);
                    $short_description = trim($short_description);
                }
                if ($hint2 != '') {
                    # Both OC branches store the hint as HTML with \r\n line breaks.

                    $hint2 = htmlspecialchars(trim($hint2), ENT_COMPAT);
                    $hint2 = preg_replace('~\R~u', "\r\n", $hint2);   # https://stackoverflow.com/questions/7836632
                    $hint2 = nl2br($hint2);
                }

                # validate descriptions and hint

                # We won't use information returned by services/caches/geocaches
                # here, to avoid assumptions on how that method processes
                # descriptions and hints.

                $language_upper_escaped = Db::escape_string(strtoupper($language));
                $is_new_language = !Db::select_value("
                    select 1 from cache_desc
                    where cache_id = '".$cache_internal_id_escaped."'
                    and language ='".$language_upper_escaped."'
                    limit 1
                ");
                if ($is_new_language)
                {
                    if ($description.$short_description.$hint2 == '')
                    {
                        # Return messages with priority in mostly used field.
                        if ($description !== null)
                            $problems['description'] = _("Please enter some text.");
                        elseif ($hint2 !== null)
                            $problems['hint2'] = _("Please enter some text.");
                        else
                            $problems['short_description'] = _("Please enter some text.");
                    }
                    elseif ($description == '')
                    {
                        # We don't allow to submit a translation only of short
                        # description and/or hint, because OC websites users of
                        # this language wouldn't notice that there also is a
                        # full description. See also the method docs.

                        if (Db::select_value("
                            select 1 from cache_desc
                            where cache_id = '".$cache_internal_id_escaped."'
                            and trim(`desc`) != ''
                            limit 1
                        ")) {
                            if ($short_description != '') {
                                $problems['short_description'] = _(
                                    "Please also translate the full description text, ".
                                    "not just the short description."
                                );
                            } else {
                                $problems['hint2'] = _("Please also translate the description.");
                            }
                        }
                    }
                }
            }

            Okapi::gettext_domain_restore();
        }
        catch (Exception $e)
        {
            Okapi::gettext_domain_restore();
            throw $e;
        }

        # save changes

        if (!$problems && ($change_sqls_escaped || $acodes_to_add || $acodes_to_remove || $desc_params_given || $country_or_region_change))
        {
            Db::execute("start transaction");

            # description, short_description, hint2

            if ($desc_params_given)
            {
                if ($is_new_language)
                {
                    # There is a slight chance that since calculation of $is_new_language,
                    # a description of this language already was inserted - e.g. by an
                    # erroneously double-submit. To be on the safe side, we recalculate
                    # $is_new_language inside the transaction. Will go quick from DB
                    # cache, if cache_desc table was not changed.

                    $is_new_language = !Db::select_value("
                        select 1 from cache_desc
                        where cache_id = '".$cache_internal_id_escaped."'
                        and language ='".$language_upper_escaped."'
                        limit 1
                    ");
                    if (!$is_new_language)
                    {
                        # Probably a duplicate submission - ignore.
                    }
                    else
                    {
                        Db::execute("
                            insert into cache_desc (
                                uuid, node, cache_id, language,
                                `desc`, desc_html, desc_htmledit, hint, short_desc,
                                date_created, last_modified
                            )
                            values (
                                '".Okapi::create_uuid()."',
                                '".Db::escape_string(Settings::get('OC_NODE_ID'))."',
                                '".$cache_internal_id_escaped."',
                                '".$language_upper_escaped."',
                                '".Db::escape_string($description)."',
                                '".Db::escape_string($value_for_desc_html_field)."',
                                '".Db::escape_string(Okapi::get_default_value_for_text_htmledit($request->token->user_id))."',
                                '".Db::escape_string($hint2)."',
                                '".Db::escape_string($short_description)."',
                                now(),
                                now()
                            )
                        ");
                        $desc_internal_id = Db::last_insert_id();
                        Db::execute("
                            insert into okapi_submitted_objects (object_type, object_id, consumer_key)
                            values (
                                ".Okapi::OBJECT_TYPE_CACHE_DESCRIPTION.",
                                '".Db::escape_string($desc_internal_id)."',
                                '".Db::escape_string($request->consumer->key)."'
                            )
                        ");
                        unset($desc_internal_id);
                    }
                }
                else   # not a new language
                {
                    # We won't use information returned by services/caches/geocaches
                    # here, to avoid assumptions on how that method processes
                    # descriptions and hints.

                    $is_only_language = Db::select_value("
                        select count(*)
                        from cache_desc
                        where cache_id = '".$cache_internal_id_escaped."'
                    ") <= 1;
                    $old_desc = Db::select_row("
                        select `desc`, short_desc, hint
                        from cache_desc
                        where cache_id = '".$cache_internal_id_escaped."'
                        and language='".$language_upper_escaped."'
                    ");
                    if (!$is_only_language)
                    {
                        $effective_desc =
                            $description !== null ? $description : trim($old_desc['desc']);
                        $effective_short_desc =
                            $short_description !== null ? $short_description : trim($old_desc['short_desc']);
                        $effective_hint =
                            $hint2 !== null ? $hint2 : trim($old_desc['hint']);
                    }
                    if (!$is_only_language
                        && $effective_desc.$effective_short_desc.$effective_hint == ''
                    ) {
                        Db::execute("
                            delete from cache_desc
                            where cache_id = '".$cache_internal_id_escaped."'
                            and language = '".$language_upper_escaped."'
                        ");
                    }
                    else
                    {
                        $desc_change_sqls_escaped = [];
                        if ($description !== null && $description != $old_desc['desc']) {
                            $desc_change_sqls_escaped[] = "`desc` = '".Db::escape_string($description)."'";
                        }
                        if ($short_description !== null && $short_description != $old_desc['short_desc']) {
                            $desc_change_sqls_escaped[] = "short_desc = '".Db::escape_string($short_description)."'";
                        }
                        if ($hint2 !== null && $hint2 != $old_desc['hint']) {
                            $desc_change_sqls_escaped[] = "hint = '".Db::escape_string($hint2)."'";
                        }
                        if ($desc_change_sqls_escaped) {
                            $desc_change_sqls_escaped[] = "last_modified = now()";
                            Db::execute("
                                update cache_desc
                                set ".implode(", ", $desc_change_sqls_escaped)."
                                where cache_id = '".$cache_internal_id_escaped."'
                                and language = '".$language_upper_escaped."'
                            ");
                        }
                        unset($desc_change_sqls_escaped);
                    }
                    unset($is_only_language);
                    unset($old_desc);
                    unset($effective_desc);
                    unset($effective_short_desc);
                    unset($effective_hint);
                }

                # Finally, update the cache's default language. OC sites use
                # this language for GPX export. Note that OCDE has updated it
                # via trigger, but only using one default language. We can do
                # better (if OCDE provides a SITELANGS list; else we just
                # repeat what the OCDE trigger did).

                # The desc_languages field has been updated on both branches
                # via trigger.

                $cache_desclangs = Db::select_value("
                    select desc_languages
                    from caches
                    where cache_id = '".$cache_internal_id_escaped."'
                ");
                $cache_default_desclang = substr($cache_desclangs, 0, 2);

                foreach (Settings::get('SITELANGS') as $sitelang) {
                    if (strpos($cache_desclangs, strtoupper($sitelang)) !== false) {
                        $cache_default_desclang = strtoupper($sitelang);
                        break;
                    }
                }
                $change_sqls_escaped[] =
                    "default_desclang = '".Db::escape_string($cache_default_desclang)."'";
            }

            # attr_acodes

            if ($acodes_to_add || $acodes_to_remove)
            {
                $acode2id = AttrHelper::get_acode_to_internal_id_mapping();
                foreach ($acodes_to_add as $acode) {
                    Db::execute("
                        insert ignore into caches_attributes (cache_id, attrib_id)
                        values (
                            '".$cache_internal_id_escaped."',
                            '".Db::escape_string($acode2id[$acode])."'
                        )
                    ");
                }
                $ids_to_remove_escaped = [];
                foreach ($acodes_to_remove as $acode) {
                    $ids_to_remove_escaped[] = Db::escape_string($acode2id[$acode]);
                }
                if ($ids_to_remove_escaped) {
                    Db::execute("
                        delete from caches_attributes
                        where cache_id = '".$cache_internal_id_escaped."'
                        and attrib_id in ('".implode("','", $ids_to_remove_escaped)."')
                    ");
                }
                unset($ids_to_remove_escaped);
                unset($acode2id);
            }

            # country/region; OCDE calculates them by cronjob

            if ($country_or_region_change && Settings::get('OC_BRANCH') == 'oc.pl')
            {
                # Note that the cache_location.adm1 value is obsolete.
                # Both OCPL and OCDE websites correctly generate the name
                # from code1.

                # For the case that we are processing bad codes that were
                # fetched from OC database and passed on, fallback to "?".

                $code1_escaped = Db::escape_string($country_code);
                $adm1_escaped = Db::escape_string(
                    isset($capabilities['countries'][$country_code])
                    ? $capabilities['countries'][$country_code] : "?"
                );
                if ($region_code === null) {
                    $code3_escaped_quoted = 'null';
                    $adm3_escaped_quoted = 'null';
                } else {
                    $code3_escaped_quoted = "'".Db::escape_string($region_code)."'";
                    $adm3_escaped_quoted = "'".Db::escape_string(
                        isset($capabilities['regions'][$country_code][$region_code])
                        ? $capabilities['regions'][$country_code][$region_code] : "?"
                    )."'";
                }

                Db::execute("
                    insert into cache_location (
                        cache_id, code1, adm1, code3, adm3
                    ) values (
                        '".$cache_internal_id_escaped."',
                        '".$code1_escaped."',
                        '".$adm1_escaped."',
                        ".$code3_escaped_quoted.",
                        ".$adm3_escaped_quoted."
                    ) on duplicate key update
                        code1 = '".$code1_escaped."',
                        adm1 = '".$adm1_escaped."',
                        code3 = ".$code3_escaped_quoted.",
                        adm3 = ".$adm3_escaped_quoted."
                ");
            }

            # other changes

            if (Settings::get('OC_BRANCH') == 'oc.pl') {
                $change_sqls_escaped[] = "last_modified = now()";
                # OCDE does this via trigger
            }
            if ($change_sqls_escaped) {
                Db::execute("
                    update caches
                    set ".implode(", ", $change_sqls_escaped)."
                    where cache_id = '".$cache_internal_id_escaped."'
                ");
            }
            if ($location_update) {
                OCPLSignals::cache_location_changed($cache['internal_id']);
            }

            Db::execute("commit");
        }

        $result = ['success' => count($problems) == 0, 'messages' => $problems];

        return Okapi::formatted_response($request, $result);
    }
}
