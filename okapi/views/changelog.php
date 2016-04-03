<?php

namespace okapi\views\changelog;

use Exception;
use okapi\Okapi;
use okapi\Settings;
use okapi\OkapiRequest;
use okapi\OkapiHttpResponse;
use okapi\views\menu\OkapiMenu;

class View
{
    public static function call()
    {
        require_once($GLOBALS['rootpath'].'okapi/views/menu.inc.php');

        # TODO: load and cache from OKAPI repo
        # TODO: verify XML scheme
        $changes = simplexml_load_file('https://raw.githubusercontent.com/following5/okapi/feature/changelog/etc/changes.xml');

        $unavailable_changes = array();
        $available_changes = array();

        foreach ($changes->change as $change) {
            if ($change['version'] > Okapi::$version_number)
                $unavailable_changes[] = $change;
            else
                $available_changes[] = $change;
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
}
