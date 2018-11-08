<?php

namespace okapi\core\Exception;

use Exception;

/**
 * This exception is only for internal use within services that submit data,
 * like logs/submit, logs/edit and logs/images/add. It is thrown by
 * WebService::_call method, when error is detected in user-supplied data.
 * It is not a BadRequest exception - it does not imply that the Consumer did
 * anything wrong (it's the user who did).
 */

class CannotPublishException extends Exception {}
