<?php

namespace okapi\views\devel\attrlist;

use Exception;
use okapi\core\Db;
use okapi\core\Response\OkapiHttpResponse;
use okapi\services\attrs\AttrHelper;
use okapi\Settings;

class View
{
    public static function call()
    {
        # This is a hidden page for OKAPI developers. It will list all
        # attributes defined in this OC installation (and some other stuff).

        ob_start();

        $show_all_translations = isset($_REQUEST['all_translations']);
        if (!$show_all_translations)
            print "Add argument '?all_translations' to show all translations.\n\n";

        foreach ([
            'Cache Types' => 'cache_type',
            'Cache Sizes' => 'cache_size',
            'Cache Statuses' => 'cache_status',
            'Log Types' => 'log_types',
            'Waypoint Types' => Settings::get('OC_BRANCH') == 'oc.pl' ? 'waypoint_type' : 'coordinates_type',
            'Languages' => 'languages',
            'Countries' => 'countries',
        ] as $entity => $table_name)
        {
            print $entity.":\n\n";
            foreach (self::get_elements($table_name) as $id => $dict) {
                print "$id: ".$dict['en']."\n";
                if ($show_all_translations) {
                    foreach ($dict as $lang => $name)
                        if ($lang != 'en')
                            print "    ".$lang.": ".$name."\n";
                    print "\n";
                }
            }
            print "\n";
        }

        print "Attributes:\n\n";
        $internal2acode = AttrHelper::get_internal_id_to_acode_mapping();
        $dict = self::get_all_attribute_names();
        foreach ($dict as $internal_id => $langs)
        {
            print $internal_id.": ";
            $langkeys = array_keys($langs);
            sort($langkeys);
            if (in_array('en', $langkeys))
                print strtoupper($langs['en']);
            else
                print ">>>> ENGLISH NAME UNSET! <<<<";
            if (isset($internal2acode[$internal_id]))
                print " - ".$internal2acode[$internal_id];
            else
                print " - >>>> MISSING A-CODE MAPPING <<<<";
            print "\n";
            foreach ($langkeys as $langkey)
                print "        $langkey: ".$langs[$langkey]."\n";
        }

        print "\nAttribute notices:\n\n";
        print "There are three priorities: (!), (-) and ( )\n";
        print "(the last one ( ) can be safely ignored)\n\n";

        $attrdict = AttrHelper::get_attrdict();
        foreach ($dict as $internal_id => $langs)
        {
            if (!isset($internal2acode[$internal_id]))
            {
                print "(!) Attribute ".$internal_id." is not mapped to any A-code.\n";
                continue;
            }
            $acode = $internal2acode[$internal_id];
            $attr = $attrdict[$acode];
            foreach ($langs as $lang => $value)
            {
                if ($lang == 'en')
                {
                    continue;
                }
                if (!isset($attr['names'][$lang]))
                {
                    print "(-) Attribute $acode is missing a name in the '$lang' language.\n";
                    print "    Local name: $value\n";
                    print "    OKAPI name: >> none <<\n";
                    continue;
                }
                if ($attr['names'][$lang] !== $value)
                {
                    print "( ) Attribute $acode has a different name in the '$lang' language\n";
                    print "    Local name: $value\n";
                    print "    OKAPI name: ".$attr['names'][$lang]."\n";
                }
            }
        }

        $response = new OkapiHttpResponse();
        $response->content_type = "text/plain; charset=utf-8";
        $response->body = ob_get_clean();
        return $response;
    }

    /**
     * Get an array of all site-specific attributes in the following format:
     * $arr[<id_of_the_attribute>][<language_code>] = <attribute_name>.
     */
    private static function get_all_attribute_names()
    {
        if (Settings::get('OC_BRANCH') == 'oc.pl')
        {
            # OCPL branch uses cache_attrib table to store attribute names. It has
            # different structure than the OCDE cache_attrib table. OCPL does not
            # have translation tables.

            $rs = Db::query("select id, language, text_long from cache_attrib order by id");
        }
        else
        {
            # OCDE branch uses translation tables. Let's make a select which will
            # produce results compatible with the one above.

            $rs = Db::query("
                select
                    ca.id,
                    stt.lang as language,
                    stt.text as text_long
                from
                    cache_attrib ca,
                    sys_trans_text stt
                where ca.trans_id = stt.trans_id
                order by ca.id
            ");
        }

        $dict = array();
        while ($row = Db::fetch_assoc($rs)) {
            $dict[$row['id']][strtolower($row['language'])] = $row['text_long'];
        }
        return $dict;
    }

    /**
     * Get an array of all site-specific types/sizes (id => name in English).
     */

    private static function get_elements($table)
    {
        $idcolumn = (in_array($table, ['countries', 'languages']) ? 'short' : 'id');
        $dict = [];

        if (Settings::get('OC_BRANCH') == 'oc.pl')
        {
            # OCPL branch does store elements in at least three languages (pl, en, nl),
            # which are columns of the definition table.

            $rs = Db::query("select * from ".$table." order by ".$idcolumn);
            while ($row = Db::fetch_assoc($rs)) {
                $tmp = [];
                foreach ($row as $column => $value)
                    if (strlen($column) == 2 && $column != 'id')
                        $tmp[$column] = $value;
                $dict[$row[$idcolumn]] = $tmp;
            }
        }
        else
        {
            # OCDE branch uses translation tables.

            $rs = Db::query("
                select
                    elements.".$idcolumn.",
                    stt.lang,
                    stt.text
                from
                    ".$table." elements
                    left join sys_trans_text stt
                        on elements.trans_id = stt.trans_id
                order by elements.".$idcolumn.", stt.lang
            ");
            while ($row = Db::fetch_assoc($rs))
                $dict[$row[$idcolumn]][strtolower($row['lang'])] = $row['text'];
        }

        return $dict;
    }
}
