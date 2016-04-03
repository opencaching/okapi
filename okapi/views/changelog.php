<?php

namespace okapi\views\changelog;

use Exception;
use okapi\Okapi;
use okapi\Settings;
use okapi\Cache;
use okapi\OkapiRequest;
use okapi\OkapiHttpResponse;
use okapi\views\menu\OkapiMenu;

class View
{
    public static function call()
    {
        require_once($GLOBALS['rootpath'].'okapi/views/menu.inc.php');

        $cache_key = 'changelog';
        $cache_backup_key = 'changelog-backup';

        $changes_xml = Cache::get($cache_key);
        $changelog = null;

        if (!$changes_xml)
        {
            # Download the current changelog.

            try
            {
                $opts = array(
                    'http' => array(
                        'method' => "GET",
                        'timeout' => 5.0
                    )
                );
                $context = stream_context_create($opts);
                $changes_xml = file_get_contents(
                    # TODO: load from OKAPI repo
                    'https://raw.githubusercontent.com/following5/okapi/feature/changelog/etc/changes.xml',
                    false, $context
                );
                $changelog = simplexml_load_string($changes_xml);
                if (!$changelog) {
                    throw new ErrorException();
                }
                Cache::set($cache_key, $changes_xml, 3600);
                Cache::set($cache_backup_key, $changes_xml, 3600*24*30);
            }
            catch (Exception $e)
            {
                # GitHub failed on us. User backup list, if available.

                $changes_xml = Cache::get($cache_backup_key);
                if ($changes_xml) {
                    Cache::set($cache_key, $changes_xml, 3600*12);
                }
            }
        }

        if (!$changelog && $changes_xml) {
            $changelog = simplexml_load_string($changes_xml);
        }
        # TODO: verify XML scheme

        $unavailable_changes = array();
        $available_changes = array();

        if (!$changelog)
        {
            # We could not retreive the changelog from Github, and there was
            # no backup key or it expired. Probably we are on a developer
            # machine. The template will output some error message.
        }
        else
        {
            $commits = array();

            foreach ($changelog->changes->change as $change) {
                if ($change['version'] == '' || $change['date'] == '') {
                    throw new Exception("Someone forgot to run update_changes.php.");
                } elseif (isset($commits[(string)$change['commit']])) {
                    throw new Exception("Duplicate commit " . $change['commit'] . " in changelog.");
                } else {
                    $change = array(
                        'commit' => (string)$change['commit'],
                        'version' => (string)$change['version'],
                        'date' => (string)$change['date'],
                        'type' => (string)$change['type'],
                        'comment' => self::get_inner_xml($change),
                    );
                    if ($change['version'] > Okapi::$version_number) {
                        $unavailable_changes[] = $change;
                    } else {
                        $available_changes[] = $change;
                    }
                    $commits[$change['commit']] = true;
                }
            }
        }

        $vars = array(
            'menu' => OkapiMenu::get_menu_html("changelog.html"),
            'okapi_base_url' => Settings::get('SITE_URL')."okapi/",
            'site_url' => Settings::get('SITE_URL'),
            'installations' => OkapiMenu::get_installations(),
            'okapi_rev' => Okapi::$version_number,
            'site_name' => Okapi::get_normalized_site_name(),
            'changes' => array(
                'unavailable' => $unavailable_changes,
                'available' => $available_changes
            ),
        );

        $response = new OkapiHttpResponse();
        $response->content_type = "text/html; charset=utf-8";
        ob_start();
        include 'changelog.tpl.php';
        $response->body = ob_get_clean();
        return $response;
    }

    private static function get_inner_xml($node)
    {
        /* Fetch as <some-node>content</some-node>, extract content. */

        $s = $node->asXML();
        $start = strpos($s, ">") + 1;
        $length = strlen($s) - $start - (3 + strlen($node->getName()));
        $s = substr($s, $start, $length);

        return $s;
    }
}
