<?php

namespace okapi\services\users\activate;

use okapi\core\Db;
use okapi\core\Exception\BadRequest;
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
        // The mail shows the code in capitals; typed codes may have other case or spaces.
        $code = strtoupper(preg_replace('/\s+/', '', (string)$request->get_parameter('code')));
        if ($email === '')
            throw new InvalidParam('email', 'Missing.');
        if ($code === '')
            throw new InvalidParam('code', 'Missing.');

        $user = UserRegistration::user_by_email($email);
        if (!$user)
            throw new RegistrationRefused('email', 'unknown_email', 'No account with this email address.');
        if ($user['is_active_flag'])
            throw new RegistrationRefused('email', 'already_active', 'This account is already active. Log in with the username and password.');
        if ($user['activation_code'] === '' || $user['activation_code'] !== $code)
            throw new RegistrationRefused('code', 'wrong_code', 'This is not the code from the activation mail.');

        // As the website's activation.php does.
        Db::execute("
            update user
            set is_active_flag = 1, activation_code = ''
            where user_id = '".Db::escape_string($user['user_id'])."'
        ");

        // The user proved the address and chose the password in this application, so the
        // application gets its Access Token at once (the application is one the site's admins
        // allowed to register users). Same bookkeeping as the OAuth flow: one long-lived token per
        // (consumer, user), and the authorization the user would have given on the authorize page.
        $consumer_key = $request->consumer->key;
        $token = Db::select_row("
            select `key`, secret
            from okapi_tokens
            where token_type = 'access'
              and user_id = '".Db::escape_string($user['user_id'])."'
              and consumer_key = '".Db::escape_string($consumer_key)."'
        ");
        if (!$token) {
            $token = array('key' => Okapi::generate_key(20), 'secret' => Okapi::generate_key(40));
            Db::execute("
                insert into okapi_tokens (`key`, secret, token_type, timestamp, user_id, consumer_key)
                values (
                    '".Db::escape_string($token['key'])."',
                    '".Db::escape_string($token['secret'])."',
                    'access', unix_timestamp(),
                    '".Db::escape_string($user['user_id'])."',
                    '".Db::escape_string($consumer_key)."'
                )
            ");
        }
        Db::execute("
            insert ignore into okapi_authorizations (consumer_key, user_id)
            values ('".Db::escape_string($consumer_key)."', '".Db::escape_string($user['user_id'])."')
        ");

        $uuid = Db::select_value("select uuid from user where user_id = '".Db::escape_string($user['user_id'])."'");
        $result = array(
            'oauth_token' => $token['key'],
            'oauth_token_secret' => $token['secret'],
            'user_uuid' => $uuid,
            'username' => $user['username'],
        );
        return Okapi::formatted_response($request, $result);
    }
}
