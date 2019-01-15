<?php

# Crowdin attribute text import/export

$action = @$argv[1];
$lang = @$argv[2];
if (!in_array($action, ['export', 'import']) || strlen($lang) != 2)
    die("usage: php attr.php export|import <langcode>\n");

$attr_filepath = __DIR__ . '/../../okapi/services/attrs/attribute-definitions.xml';
$lang_filepath = __DIR__ . '/attr-' . $lang . '.json';

$attrdefs_raw = file_get_contents($attr_filepath);
$attrdefs = simplexml_load_string($attrdefs_raw);

function get_desc($descnode)
{
    $desc = $descnode->asxml();
    $innerxml = preg_replace("/(^[^>]+>)|(<[^<]+$)/us", "", $desc);
    return preg_replace('/(^\s+)|(\s+$)/us', "", preg_replace('/\s+/us', " ", $innerxml));
}

if ($action == 'export')
{
    $texts = [];
    foreach ($attrdefs->attr as $attrnode)
        foreach ($attrnode->lang as $langnode)
            if ($langnode['id'] == $lang) {
                $acode = (string)$attrnode['acode'];
                $texts[$acode.'-name'] = (string)$langnode->name;
                $texts[$acode.'-desc'] = get_desc($langnode->desc);
            }
    file_put_contents($lang_filepath, json_encode($texts));
}
elseif ($action == 'import')
{
    $translation = json_decode(file_get_contents($lang_filepath), true);
    ob_start();
    $changed = 0;

    # copy file header

    print substr($attrdefs_raw, 0, strpos($attrdefs_raw, '    <attr acode="A1"'));

    foreach ($attrdefs->attr as $attrnode)
    {
        # write attribute properties

        print '    <attr acode="'.$attrnode['acode'].'" categories="'.$attrnode['categories'].'">' . "\n";
        if (isset($attrnode->groundspeak))
            print '        <groundspeak id="'.$attrnode->groundspeak['id'].'" inc="'.$attrnode->groundspeak['inc'].'" name="'.$attrnode->groundspeak['name'].'" />' . "\n";
        if (isset($attrnode->ocgs))
            print '        <ocgs id="'.$attrnode->ocgs['id'].'" inc="'.$attrnode->ocgs['inc'].'" />' . "\n";
        foreach ($attrnode->opencaching as $ocnode)
            print '        <opencaching schema="'.$ocnode['schema'].'" id="'.$ocnode['id'].'" />' . "\n";

        # read old translation strings

        $texts = [];
        foreach ($attrnode->lang as $langnode) {
            $nlang = (string)$langnode['id'];
            if (isset($langnode->name))
                $texts[$nlang]['name'] = (string)$langnode->name;
            if (isset($langnode->desc))
                $texts[$nlang]['desc'] = get_desc($langnode->desc);
        }
        $acode = (string)$attrnode['acode'];

        # add / overwrite-with new translation strings

        if (!empty($translation[$acode.'-name']) && $translation[$acode.'-name'] != $texts[$lang]['name']) {
            $texts[$lang]['name'] = $translation[$acode.'-name'];
            ++$changed;
        }
        if (!empty($translation[$acode.'-desc']) && $translation[$acode.'-desc'] != $texts[$lang]['desc']) {
            $texts[$lang]['desc'] = $translation[$acode.'-desc'];
            ++$changed;
        }

        # write translation strings

        foreach ($texts as $tlang => $trans) {
            print '        <lang id="'.$tlang.'">' . "\n";

            # OKAPI does not escape anything inside XML tags. Attribute names
            # must not contain '<', and attribute descs must be valid HTML.

            print "            <name>".$trans['name']."</name>\n";
            $desc = $trans['desc'];
            if ($desc != "")
            {
                print "            <desc>\n";
                while ($desc != "")
                {
                    if (strlen($desc) <= 70) {
                        print "                " . $desc . "\n";
                        $desc = "";
                    } else {
                        $p = strrpos(substr($desc, 0, 71), ' ');
                        if ($p < 10) {
                            $p = strpos($desc, ' ', 10);
                            if ($p === false)
                                $p = strlen($desc);
                        }
                        if ($p > 3 && substr($desc, $p-3, 3) == ' <a')
                            $p -= 3;
                        if ($p == 0)
                            $p = strpos($desc, ' ');
                        print "                " . substr($desc, 0, $p) . "\n";
                        $desc = substr($desc, $p + 1);
                    }
                }
                print "            </desc>\n";
            }
            print "        </lang>\n";
        }

        # write end of attribute

        print "    </attr>\n";
        print "\n";
    }

    # write EOF
    print "</xml>\n";

    $new_attrdefs_raw = ob_get_clean();
    file_put_contents($attr_filepath, $new_attrdefs_raw);

    echo "Imported ".$changed." new translations.\n";
}
