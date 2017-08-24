<?php

#
# All HTTP requests within the /okapi/ path are redirected through this
# controller. From here we'll pass them to the right entry point (or
# display an appropriate error message).
#
# To learn more about OKAPI, see core.php.
#

# -------------------------

#
# Set up the rootpath. If OKAPI is called via its Facade entrypoint, then this
# variable is being set up by the OC site. If it is called via the controller
# endpoint (this one!), then we need to set it up ourselves.
#

use okapi\Exception\OkapiExceptionHandler;
use okapi\lib\OkapiScriptEntryPointController;
use okapi\Okapi;
use okapi\OkapiErrorHandler;
use okapi\Settings;

$GLOBALS['rootpath'] = __DIR__.'/../';

error_reporting(E_ALL);
ini_set('display_errors', 'on');

require_once __DIR__ . '/vendor/autoload.php';

OkapiErrorHandler::$treat_notices_as_errors = true;

if (ob_list_handlers() === ['default output handler']) {
    # We will assume that this one comes from "output_buffering" being turned on
    # in PHP config. This is very common and probably is good for most other OC
    # pages. But we don't need it in OKAPI. We will just turn this off.
    ob_end_clean();
}

/** Return an array of email addresses which always get notified on OKAPI errors. */
function get_admin_emails()
{
    $emails = array();
    if (class_exists(Settings::class))
    {
        try
        {
            foreach (Settings::get('ADMINS') as $email)
                if (!in_array($email, $emails))
                    $emails[] = $email;
        }
        catch (Exception $e) { /* pass */ }
    }
    if (count($emails) == 0)
        $emails[] = 'root@localhost';
    return $emails;
}

# Setting handlers. Errors will now throw exceptions, and all exceptions
# will be properly handled. (Unfortunately, only SOME errors can be caught
# this way, PHP limitations...)

set_exception_handler(array(OkapiExceptionHandler::class, 'handle'));
set_error_handler(array(OkapiErrorHandler::class, 'handle'));
register_shutdown_function(array(OkapiErrorHandler::class, 'handle_shutdown'));

Okapi::gettext_domain_init();
OkapiScriptEntryPointController::dispatch_request($_SERVER['REQUEST_URI']);
Okapi::gettext_domain_restore();
