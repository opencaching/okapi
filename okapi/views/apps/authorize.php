<?php

namespace okapi\views\apps\authorize;

use okapi\Db;
use okapi\lib\OCSession;
use okapi\locale\Locales;
use okapi\Okapi;
use okapi\OkapiHttpResponse;
use okapi\OkapiRedirectResponse;
use okapi\Settings;

class View
{
    public static function call()
    {
        $token_key = isset($_GET['oauth_token']) ? $_GET['oauth_token'] : '';
        $langpref = isset($_GET['langpref']) ? $_GET['langpref'] : Settings::get('SITELANG');
        $langprefs = explode("|", $langpref);
        $locales = [];
        foreach (Locales::$languages as $lang => $attrs) {
            $locales[$attrs['locale']] = $attrs;
        }

        # Current implementation of the "interactivity" parameter is: If developer
        # wants to "confirm_user", then just log out the current user before we
        # continue.

        $force_relogin = (isset($_GET['interactivity']) && $_GET['interactivity'] == 'confirm_user');

        $token = Db::select_row(
            "
            SELECT
                t.`key` AS `key`,
                c.`key` AS consumer_key,
                c.name AS consumer_name,
                c.url AS consumer_url,
                t.callback,
                t.verifier
            FROM
                okapi_consumers c,
                okapi_tokens t
            WHERE
                t.`key` = '" . Db::escape_string($token_key) . "'
                AND t.consumer_key = c.`key`
                AND t.user_id IS NULL
        "
        );

        $callback_concat_char = (strpos($token['callback'], '?') === false) ? "?" : "&";

        if (!$token) {
            # Probably Request Token has expired. This will be usually viewed
            # by the user, who knows nothing on tokens and OAuth. Let's be nice then!

            $vars = [
                'okapi_base_url' => Settings::get('SITE_URL') . "okapi/",
                'token' => $token,
                'token_expired' => true,
                'site_name' => Okapi::get_normalized_site_name(),
                'site_url' => Settings::get('SITE_URL'),
                'site_logo' => Settings::get('SITE_LOGO'),
                'locales' => $locales,
            ];
            $response = new OkapiHttpResponse();
            $response->content_type = "text/html; charset=utf-8";
            ob_start();
            $vars['locale_displayed'] = Okapi::gettext_domain_init($langprefs);
            include 'authorize.tpl.php';
            $response->body = ob_get_clean();
            Okapi::gettext_domain_restore();

            return $response;
        }

        # Determine which user is logged in to OC.

        $OC_user_id = OCSession::get_user_id();

        # Ensure a user is logged in (or force re-login).

        if ($force_relogin || ($OC_user_id == null)) {
            # TODO: confirm_user should first ask the user if he's "the proper one",
            # and then offer to sign in as a different user.

            $login_page = 'login.php?';

            if ($OC_user_id !== null) {
                if (Settings::get('OC_BRANCH') == 'oc.de') {
                    # OCDE login.php?action=logout&target=... will NOT logout and
                    # then redirect to the target, but it will log out, prompt for
                    # login and then redirect to the target after logging in -
                    # that's exactly the relogin that we want.

                    $login_page .= 'action=logout&';
                } else {
                    # OCPL uses REAL MAGIC for session handling. I don't get ANY of it.
                    # The logout.php DOES NOT support the "target" parameter, so we
                    # can't just call it. The only thing that comes to mind is...
                    # Try to destroy EVERYTHING. (This still won't necessarilly work,
                    # because OC may store cookies in separate paths, but hopefully
                    # they won't).

                    if (isset($_SERVER['HTTP_COOKIE'])) {
                        $cookies = explode(';', $_SERVER['HTTP_COOKIE']);
                        foreach ($cookies as $cookie) {
                            $parts = explode('=', $cookie);
                            $name = trim($parts[0]);
                            setcookie($name, '', time() - 1000);
                            setcookie($name, '', time() - 1000, '/');
                            foreach (self::getPossibleCookieDomains() as $domain) {
                                setcookie($name, '', time() - 1000, '/', $domain);
                            }
                        }
                    }

                    # We should be logged out now. Let's login again.
                }
            }

            $after_login = "okapi/apps/authorize?oauth_token=$token_key" . (($langpref != Settings::get(
                        'SITELANG'
                    )) ? "&langpref=" . $langpref : "");
            $login_url = Settings::get('SITE_URL') . $login_page . "target=" . urlencode($after_login)
                . "&langpref=" . $langpref;

            return new OkapiRedirectResponse($login_url);
        }

        # Check if this user has already authorized this Consumer. If he did,
        # then we will automatically authorize all subsequent Request Tokens
        # from this Consumer.

        $authorized = Db::select_value(
            "
            SELECT 1
            FROM okapi_authorizations
            WHERE
                user_id = '" . Db::escape_string($OC_user_id) . "'
                AND consumer_key = '" . Db::escape_string($token['consumer_key']) . "'
        "
        );

        if (!$authorized) {
            if (isset($_POST['authorization_result'])) {
                # Not yet authorized, but user have just submitted the authorization form.
                # Note, that currently there is no CSRF protection here.

                if ($_POST['authorization_result'] == 'granted') {
                    Db::execute(
                        "
                        INSERT IGNORE INTO okapi_authorizations (consumer_key, user_id)
                        VALUES (
                            '" . Db::escape_string($token['consumer_key']) . "',
                            '" . Db::escape_string($OC_user_id) . "'
                        );
                    "
                    );
                    $authorized = true;
                } else {
                    # User denied access. Nothing sensible to do now. Will try to report
                    # back to the Consumer application with an error.

                    if ($token['callback']) {
                        return new OkapiRedirectResponse(
                            $token['callback'] . $callback_concat_char . "error=access_denied" .
                            "&oauth_token=" . $token['key']
                        );
                    } else {
                        # Consumer did not provide a callback URL (oauth_callback=oob).
                        # We'll have to redirect to the Opencaching main page then...
                        return new OkapiRedirectResponse(Settings::get('SITE_URL') . "index.php");
                    }
                }
            } else {
                # Not yet authorized. Display an authorization request.
                $vars = [
                    'okapi_base_url' => Settings::get('SITE_URL') . "okapi/",
                    'token' => $token,
                    'site_name' => Okapi::get_normalized_site_name(),
                    'site_url' => Settings::get('SITE_URL'),
                    'site_logo' => Settings::get('SITE_LOGO'),
                    'locales' => $locales,
                ];
                $response = new OkapiHttpResponse();
                $response->content_type = "text/html; charset=utf-8";
                ob_start();
                $vars['locale_displayed'] = Okapi::gettext_domain_init($langprefs);
                include __DIR__ . '/authorize.tpl.php';
                $response->body = ob_get_clean();
                Okapi::gettext_domain_restore();

                return $response;
            }
        }

        # User granted access. Now we can authorize the Request Token.

        Db::execute(
            "
            UPDATE okapi_tokens
            SET user_id = '" . Db::escape_string($OC_user_id) . "'
            WHERE `key` = '" . Db::escape_string($token_key) . "';
        "
        );

        # Redirect to the callback_url.

        if ($token['callback']) {
            return new OkapiRedirectResponse(
                $token['callback'] . $callback_concat_char . "oauth_token=" . $token_key . "&oauth_verifier=" . $token['verifier']
            );
        } else {
            # Consumer did not provide a callback URL (probably the user is using a desktop
            # or mobile application). We'll just have to display the verifier to the user.
            return new OkapiRedirectResponse(
                Settings::get('SITE_URL') . "okapi/apps/authorized?oauth_token=" . $token_key
                . "&oauth_verifier=" . $token['verifier'] . "&langpref=" . $langpref
            );
        }
    }

    /**
     * Return a list of all plausible cookie domains which OC developers might
     * have used to store user session data.
     */
    private static function getPossibleCookieDomains()
    {
        $site_url = Settings::get('SITE_URL');
        $domain = parse_url($site_url, PHP_URL_HOST);
        $segments = explode(".", $domain);
        $results = [];
        for ($to_skip = 0; $to_skip <= count($segments) - 2; $to_skip++) {
            $results[] = "." . implode(".", array_slice($segments, $to_skip));
            $results[] = implode(".", array_slice($segments, $to_skip));
        }

        return $results;
    }
}
