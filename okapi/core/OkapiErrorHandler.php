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
        # Register handlers. Errors will now throw exceptions, and all exceptions
        # will be properly handled. Fatal PHP errors cannot be caught this way
        # and will be handled by a shutdown handler.

        register_shutdown_function([__CLASS__, 'handle_shutdown']);
        self::enable();
    }

    public static function enable()
    {
        if (!self::$enabled) {
            set_exception_handler([OkapiExceptionHandler::class, 'handle']);
            set_error_handler([__CLASS__, 'handle']);
            self::$enabled = true;
        }
    }

    public static function disable()
    {
        if (self::$enabled) {
            restore_error_handler();
            restore_exception_handler();
            self::$enabled = false;
        }
    }

    /** Handle error encountered while executing OKAPI request. */
    public static function handle($severity, $message, $filename, $lineno)
    {
        if ($severity != E_STRICT && $severity != E_DEPRECATED &&
            error_reporting() > 0  // is 0 if suppressed by @ operator
        ) {
            throw new \ErrorException($message, 0, $severity, $filename, $lineno);
        }
    }

    /** Handle FATAL errors (not catchable, report only). */
    public static function handle_shutdown()
    {
        if (self::$enabled)
        {
            $error = error_get_last();

            # We don't know whether this error has been already handled. The error_get_last
            # function will return E_NOTICE or E_STRICT errors if the script has shut down
            # correctly. The only error which cannot be recovered from is E_ERROR, we have
            # to check the type then.

            if ($error !== null &&
                in_array($error['type'],  [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])
            ) {
                $e = new FatalError($error['message'], 0, $error['type'], $error['file'], $error['line']);
                OkapiExceptionHandler::handle($e);
            }
        }
    }
}
