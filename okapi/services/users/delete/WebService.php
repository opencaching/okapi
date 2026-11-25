<?php

namespace okapi\services\users\delete;

use okapi\core\Db;
use okapi\core\Exception\InvalidParam;
use okapi\core\Okapi;
use okapi\core\Request\OkapiRequest;
use okapi\services\users\AccountDeletion;
use okapi\services\users\RegistrationRefused;
use okapi\services\users\UserRegistration;

class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 3
        );
    }

    public static function call(OkapiRequest $request)
    {
        UserRegistration::require_allowed($request);
        $password = $request->get_parameter('password');
        if ($password === null || $password === '')
            throw new InvalidParam('password', 'Missing.');

        // The account's password, as on the website's login: a stored Access Token alone (a lost
        // phone) must not be enough to delete an account.
        $user_id = $request->token->user_id;
        $stored = Db::select_value("select password from user where user_id = '".Db::escape_string($user_id)."'");
        if ($stored === null || !hash_equals((string)$stored, UserRegistration::password_hash((string)$password)))
            throw new RegistrationRefused('password', 'wrong_password', 'This is not the password of this account.');

        $result = array(
            'result' => AccountDeletion::delete_account($user_id),
        );
        return Okapi::formatted_response($request, $result);
    }
}
