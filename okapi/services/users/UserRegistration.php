<?php

namespace okapi\services\users;

use okapi\core\Cache;
use okapi\core\Consumer\OkapiConsumer;
use okapi\core\Db;
use okapi\core\Exception\BadRequest;
use okapi\core\Okapi;
use okapi\core\Request\OkapiRequest;
use okapi\Settings;

/**
 * Account creation for services/users/register, activate and resend_activation (OCDE).
 *
 * The rules are a copy of the website's own registration (oc-server3: htdocs/register.php,
 * lib2/logic/user.class.php, lib2/logic/cracklib.inc.php, lib2/logic/const.inc.php,
 * src/OcLegacy/Util/PasswordCrypt.php), so an account made here is the same as one made on
 * the website: inactive until the code from the activation mail is entered.
 */
class UserRegistration
{
    /** const.inc.php EUROPEAN_LETTERS */
    const LETTERS = 'A-Za-zÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖ×ØÙÚÛÜÝÞßàáâãäåæçèéêëìíîïðñòóôõö÷øùúûüýþÿ';

    /** The installation's registration settings, or null when registration through OKAPI is off. */
    public static function config()
    {
        if (Settings::get('OC_BRANCH') != 'oc.de')
            return null;
        $config = Settings::get('OCDE_REGISTRATION');
        return is_array($config) ? $config : null;
    }

    /**
     * Checks that this installation offers registration, that the request came over HTTPS (it
     * carries a password; DEBUG installations are exempt) and that the site's admins allowed the
     * consumer to register users.
     */
    public static function require_allowed(OkapiRequest $request)
    {
        if (self::config() === null)
            throw new BadRequest('This installation does not offer registration through OKAPI. See has_registration in services/apisrv/installation.');
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) == 'https');
        if (!$https && !Settings::get('DEBUG'))
            throw new BadRequest('This method is allowed only over HTTPS.');
        if (!$request->consumer->hasFlag(OkapiConsumer::FLAG_REGISTER_USERS))
            throw new BadRequest("This application may not register users. The site's administrators decide which applications may.");
    }

    /** const.inc.php REGEX_USERNAME; user.class.php setUsername (not an email address). */
    public static function check_username($username)
    {
        $l = self::LETTERS;
        $first = "[{$l}0-9\\.\\-_@=)(\\/\\\\&*+~#]";
        $middle = "[{$l}0-9\\.\\-_ @=)(\\/\\\\&*+~#]";
        if (!preg_match("/^{$first}{$middle}{1,58}{$first}$/u", $username))
            throw new RegistrationRefused('username', 'username_invalid',
                'Use 3 to 60 letters, digits or . - _ @ = ( ) / \\ & * + ~ #; spaces only inside the name.');
        if (self::is_email($username))
            throw new RegistrationRefused('username', 'username_invalid', 'The username must not be an email address.');
        if (Db::select_value("select 1 from user where username = '".Db::escape_string($username)."'"))
            throw new RegistrationRefused('username', 'username_taken', 'This username is already taken.');
    }

    /** util.inc.php is_valid_email_address */
    public static function is_email($value)
    {
        return $value === filter_var($value, FILTER_VALIDATE_EMAIL);
    }

    public static function check_email($email)
    {
        if (!self::is_email($email) || strlen($email) > 60)
            throw new RegistrationRefused('email', 'email_invalid', 'This is not a valid email address.');
        if (Db::select_value("select 1 from user where email = '".Db::escape_string($email)."'"))
            throw new RegistrationRefused('email', 'email_taken', 'An account with this email address already exists.');
    }

    /** const.inc.php REGEX_PASSWORD and cracklib.inc.php cracklib_checkpw (without the optional crack extension). */
    public static function check_password($password, $username)
    {
        $l = self::LETTERS;
        if (!preg_match("/^[{$l}0-9\\.\\-_ @=)(\\/\\\\&*+~#]{3,60}$/u", $password))
            throw new RegistrationRefused('password', 'password_invalid',
                'Use 6 to 60 letters, digits, spaces or . - _ @ = ( ) / \\ & * + ~ #.');
        $weak = function ($why) { return new RegistrationRefused('password', 'password_weak', $why); };
        if (strlen($password) < 6)
            throw $weak('The password needs at least 6 characters.');
        $chars = array();
        $len = mb_strlen($password, 'UTF-8');
        for ($i = 0; $i < $len; $i++)
            $chars[mb_substr($password, $i, 1, 'UTF-8')] = true;
        if (count($chars) <= 4)
            throw $weak('The password needs at least 5 different characters.');
        $words = array();
        foreach (array('open', 'caching', 'cache', $username) as $word) {
            $word = mb_strtolower($word, 'UTF-8');
            $word = str_replace(array('?', ')', '(', '.', '´', '`', "'", '/', ':', '-', ',', "\r\n", "\n", "\r"), ' ', $word);
            foreach (explode(' ', $word) as $w)
                if (mb_strlen($w, 'UTF-8') >= 3)
                    $words[] = $w;
        }
        $pw = mb_strtolower($password, 'UTF-8');
        foreach ($words as $w)
            if (mb_strpos($pw, $w, 0, 'UTF-8') !== false || mb_strpos($w, $pw, 0, 'UTF-8') !== false)
                throw $weak('The password must not contain the username, "open", "cache" or "caching".');
        if (Db::select_value("select 1 from pw_dict where pw = '".Db::escape_string($password)."'"))
            throw $weak('This password is too common.');
    }

    /** PasswordCrypt::encryptPassword: md5, then HMAC-SHA512 with the site's salt if the site uses it. */
    public static function password_hash($password)
    {
        $config = self::config();
        $md5 = md5($password);
        if (!empty($config['password_hash']))
            return hash_hmac('sha512', $md5, isset($config['password_salt']) ? $config['password_salt'] : '');
        return $md5;
    }

    /** Resends per account, and the time between two activation mails (seconds). */
    const MAX_RESENDS = 3;
    const MAIL_INTERVAL = 300;

    /**
     * Refuses a resend that comes too soon after the last mail or after MAX_RESENDS resends.
     * The website sends the activation mail once (only its admins can send it again), so this
     * keeps resend_activation from being used to flood an address with mail from the site.
     */
    public static function check_resend_allowed($user_id)
    {
        $sent = Cache::get('users/mails/'.$user_id);
        if ($sent === null)
            return;
        if ($sent['resends'] >= self::MAX_RESENDS)
            throw new RegistrationRefused('email', 'too_many_mails',
                'The activation mail was sent '.(self::MAX_RESENDS + 1).' times already. Check the spam folder, or ask the site\'s team.');
        $wait = $sent['last'] + self::MAIL_INTERVAL - time();
        if ($wait > 0)
            throw new RegistrationRefused('email', 'too_soon',
                'The activation mail was sent a moment ago. Try again in '.ceil($wait / 60).' minutes.');
    }

    /** Notes a sent activation mail; kept for the 14 days an inactive account lives. */
    public static function note_mail_sent($user_id, $is_resend)
    {
        $sent = $is_resend ? Cache::get('users/mails/'.$user_id) : null;
        Cache::set('users/mails/'.$user_id, array(
            'resends' => ($sent === null ? 0 : $sent['resends']) + ($is_resend ? 1 : 0),
            'last' => time(),
        ), 15 * 86400);
    }

    /** user.class.php createCode */
    public static function new_code()
    {
        return strtoupper(substr(md5(uniqid('', true).Okapi::generate_key(16)), 0, 13));
    }

    /** The user row of an email address (any case of the address, as MySQL compares it). */
    public static function user_by_email($email)
    {
        return Db::select_row("
            select user_id, username, email, is_active_flag, activation_code
            from user
            where email = '".Db::escape_string($email)."'
        ");
    }

    /** The website's registration mail (templates2/mail/register.tpl), in German or English. */
    public static function send_activation_mail($user, $lang)
    {
        if (Settings::get('DEBUG_PREVENT_EMAILS'))
            return;
        $site = Okapi::get_normalized_site_name();
        $site_url = Settings::get('SITE_URL');
        $page = $site_url.'activation.php';
        $link = $site_url.'a.php?e='.urlencode($user['email']).'&c='.$user['activation_code'];
        if ($lang == 'de') {
            $subject = 'Registrierungsbestätigung';
            $text = "herzlich willkommen bei $site!\n\n"
                ."Folgende Daten wurden für dich eingetragen:\n\n"
                ."Benutzername: ".$user['username']."\n"
                ."E-Mail-Adresse: ".$user['email']."\n\n"
                ."Vor dem ersten Einloggen musst du auf folgenden Link klicken, um deinen Account zu aktivieren:\n\n"
                ."$link\n\n"
                ."Wahlweise kannst du auch auf $page den folgenden Code von Hand eingeben:\n\n"
                .$user['activation_code']."\n\n"
                ."Solltest du dich innerhalb der nächsten 14 Tage nicht einloggen, wird der Benutzeraccount wieder gelöscht.\n";
        } else {
            $subject = 'Registration confirmation';
            $text = "welcome at $site!\n\n"
                ."The following data has been stored for your account:\n\n"
                ."Username: ".$user['username']."\n"
                ."E-Mail address: ".$user['email']."\n\n"
                ."Before you can login for the first time, you have to activate your account by clicking on the following link:\n\n"
                ."$link\n\n"
                ."Alternatively, you can visit $page and enter the following code manually:\n\n"
                .$user['activation_code']."\n\n"
                ."If you don't login in the next 14 days, your account will be deleted.\n";
        }
        $from = Settings::get('FROM_FIELD');
        mail($user['email'], '=?UTF-8?B?'.base64_encode($subject).'?=', $text,
            "Content-Type: text/plain; charset=utf-8\n"
            ."From: $site <$from>\n"
            ."Reply-To: $from\n"
        );
    }

    /** "de" for German, else "en" (the mail exists in these two). */
    public static function mail_lang(OkapiRequest $request)
    {
        $langpref = $request->get_parameter('langpref');
        if ($langpref === null)
            $langpref = Settings::get('SITELANG');
        $first = strtolower(substr(trim(explode('|', $langpref)[0]), 0, 2));
        return $first == 'de' ? 'de' : 'en';
    }
}
