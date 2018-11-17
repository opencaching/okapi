<?php

namespace okapi\core;

use okapi\core\Exception\FatalError;
use okapi\core\Exception\OkapiExceptionHandler;

/** Container for error-handling functions. */
class OkapiErrorHandler
{
    private static $enabled = false;

    public static function init()
    {
        # Setting handlers. Errors will now throw exceptions, and all exceptions
        # will be properly handled. (Unfortunately, only SOME errors can be caught
        # this way, PHP limitations...)

        set_exception_handler(array(OkapiExceptionHandler::class, 'handle'));
        self::enable();
        register_shutdown_function(array(OkapiErrorHandler::class, 'handle_shutdown'));
    }

    /** Handle error encountered while executing OKAPI request. */
    public static function handle($severity, $message, $filename, $lineno)
    {
        if ($severity != E_STRICT && $severity != E_DEPRECATED)
            throw new \ErrorException($message, 0, $severity, $filename, $lineno);
    }

    /** Use this BEFORE calling a piece of buggy code. */
    public static function disable()
    {
        if (self::$enabled) {
            restore_error_handler();
            self::$enabled = false;
        }
    }

    /** Use this AFTER calling a piece of buggy code. */
    public static function enable()
    {
        if (!self::$enabled) {
            set_error_handler(array(__CLASS__, 'handle'));
            self::$enabled = true;
        }
    }

    /** Handle FATAL errors (not catchable, report only). */
    public static function handle_shutdown()
    {
        $error = error_get_last();

        # We don't know whether this error has been already handled. The error_get_last
        # function will return E_NOTICE or E_STRICT errors if the script has shut down
        # correctly. The only error which cannot be recovered from is E_ERROR, we have
        # to check the type then.

        if (($error !== null) && ($error['type'] == E_ERROR))
        {
            $e = new FatalError($error['message'], 0, $error['type'], $error['file'], $error['line']);
            OkapiExceptionHandler::handle($e);
        }
    }
}
