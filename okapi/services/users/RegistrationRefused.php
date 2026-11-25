<?php

namespace okapi\services\users;

use okapi\core\Exception\InvalidParam;

/**
 * A registration parameter the platform refuses, with a fixed reason code (e.g. "username_taken")
 * next to the readable message, so a client can show the problem at the right field.
 */
class RegistrationRefused extends InvalidParam
{
    public $reason;

    public function __construct($paramName, $reason, $whats_wrong_about_it)
    {
        $this->reason = $reason;
        parent::__construct($paramName, $whats_wrong_about_it);
    }

    protected function provideExtras(&$extras)
    {
        parent::provideExtras($extras);
        $extras['reason'] = $this->reason;
    }
}
