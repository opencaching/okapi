<?php

namespace okapi\core\CronJob;

use okapi\core\Okapi;
use okapi\locale\Locales;

/**
 * Once per week, check if all required locales are installed. If not,
 * keep nagging the admins to do so.
 */
class LocaleChecker extends Cron5Job
{
    public function get_period()
    {
        return 7 * 86400;
    }

    public function execute()
    {
        $required = Locales::get_required_locales();
        $installed = Locales::get_installed_locales();
        $missing = array();
        foreach ($required as $locale) {
            if (!in_array($locale, $installed)) {
                $missing[] = $locale;
            }
        }
        if (count($missing) == 0) {
            return;
        } // okay!
        ob_start();
        echo "Hi!\n\n";
        echo "Your system is missing some locales required by OKAPI for proper\n";
        echo "internationalization support. OKAPI comes with support for different\n";
        echo "languages. This number (hopefully) will be growing.\n\n";
        echo "Please take a moment to install the following missing locales:\n\n";
        $prefixes = array();
        foreach ($missing as $locale) {
            echo ' - '.$locale."\n";
            $prefixes[substr($locale, 0, 2)] = true;
        }
        $prefixes = array_keys($prefixes);
        echo "\n";
        if ((count($missing) == 1) && ($missing[0] == 'POSIX')) {
            // I don't remember how to install POSIX, probably everyone has it anyway.
        } else {
            echo "On Debian, try the following:\n\n";
            foreach ($prefixes as $lang) {
                if ($lang != 'PO') { // Two first letters cut from POSIX.
                    echo 'sudo apt-get install language-pack-'.$lang."-base\n";
                }
            }
            echo "sudo service apache2 restart\n";
            echo "\n";
        }
        echo "Thanks!\n\n";
        echo "-- \n";
        echo 'OKAPI Team';
        Okapi::mail_admins('Additional setup needed: Missing locales.', ob_get_clean());
    }
}
