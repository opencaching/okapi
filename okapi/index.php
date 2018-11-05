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

namespace okapi;

use okapi\core\Exception\OkapiExceptionHandler;
use okapi\core\Okapi;
use okapi\core\OkapiErrorHandler;

# When deployed via composer, the rootpath is already set.
if (!isset($GLOBALS['rootpath'])) {
    $GLOBALS['rootpath'] = __DIR__.'/../';
}

require_once __DIR__ . '/autoload.php';

if (ob_list_handlers() === ['default output handler']) {
    # We will assume that this one comes from "output_buffering" being turned on
    # in PHP config. This is very common and probably is good for most other OC
    # pages. But we don't need it in OKAPI. We will just turn this off.
    ob_end_clean();
}

OkapiErrorHandler::init();
Okapi::gettext_domain_init();
OkapiScriptEntryPointController::dispatch_request($_SERVER['REQUEST_URI']);
Okapi::gettext_domain_restore();
