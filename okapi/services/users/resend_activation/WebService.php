<?php

namespace okapi\services\users\resend_activation;

use okapi\core\Exception\InvalidParam;
use okapi\core\Okapi;
use okapi\core\Request\OkapiRequest;
use okapi\services\users\RegistrationRefused;
use okapi\services\users\UserRegistration;

class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 1
        );
    }

    public static function call(OkapiRequest $request)
    {
        UserRegistration::require_allowed($request);
        $email = trim((string)$request->get_parameter('email'));
        if ($email === '')
            throw new InvalidParam('email', 'Missing.');
        $user = UserRegistration::user_by_email($email);
        if (!$user)
            throw new RegistrationRefused('email', 'unknown_email', 'No account with this email address.');
        if ($user['is_active_flag'])
            throw new RegistrationRefused('email', 'already_active', 'This account is already active. Log in with the username and password.');

        UserRegistration::check_resend_allowed($user['user_id']);

        // The same code again, so an older mail stays valid.
        UserRegistration::send_activation_mail($user, UserRegistration::mail_lang($request));
        UserRegistration::note_mail_sent($user['user_id'], true);
        $result = array('email' => $user['email']);
        return Okapi::formatted_response($request, $result);
    }
}
