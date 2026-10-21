<?php

namespace okapi\services\users\register;

use okapi\core\Db;
use okapi\core\Exception\InvalidParam;
use okapi\core\Okapi;
use okapi\core\Request\OkapiRequest;
use okapi\services\users\UserRegistration;
use okapi\Settings;

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
        $config = UserRegistration::config();

        // Trimmed like on the website (register.php).
        $username = trim((string)$request->get_parameter('username'));
        $email = trim((string)$request->get_parameter('email'));
        $password = (string)$request->get_parameter('password');
        if ($request->get_parameter('accept_terms') !== 'true')
            throw new InvalidParam('accept_terms', "The user has to accept the site's terms of use, privacy statement and data license ('true').");

        UserRegistration::check_email($email);
        UserRegistration::check_username($username);
        UserRegistration::check_password($password, $username);

        // The columns the website sets for a new user (user.class.php); every NOT NULL column without
        // a default is set. uuid, date_created and last_modified come from the userBeforeInsert trigger.
        $code = UserRegistration::new_code();
        Db::execute("
            insert into user (
                username, password, email, latitude, longitude, is_active_flag, last_name, first_name,
                pmr_flag, permanent_login_flag, watchmail_nextmail, activation_code, data_license, node,
                description, uuid
            ) values (
                '".Db::escape_string($username)."',
                '".Db::escape_string(UserRegistration::password_hash($password))."',
                '".Db::escape_string($email)."',
                0, 0, 0, '', '',
                0, 0, now(),
                '".Db::escape_string($code)."',
                '".Db::escape_string(isset($config['data_license']) ? (int)$config['data_license'] : 0)."',
                '".Db::escape_string(Settings::get('OC_NODE_ID'))."',
                '', ''
            )
        ");
        $user = UserRegistration::user_by_email($email);
        UserRegistration::send_activation_mail($user, UserRegistration::mail_lang($request));

        $result = array(
            'username' => $user['username'],
            'email' => $user['email'],
        );
        return Okapi::formatted_response($request, $result);
    }
}
