<?php

namespace okapi\services\caches\edit;

use Exception;
use okapi\core\Okapi;
use okapi\core\Db;
use okapi\core\Exception\BadRequest;
use okapi\core\Exception\InvalidParam;
use okapi\core\Exception\ParamMissing;
use okapi\core\Request\OkapiRequest;
use okapi\core\Request\OkapiInternalRequest;
use okapi\core\OkapiServiceRunner;
use okapi\Settings;


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
        $cache_code = $request->get_parameter('cache_code');
        if ($cache_code == null)
            throw new ParamMissing('cache_code');
        $geocache = OkapiServiceRunner::call(
            'services/caches/geocache',
            new OkapiInternalRequest(
                $request->consumer,
                $request->token,
                array('cache_code' => $cache_code, 'fields' => 'internal_id|type|date_created')
            )
        );
        $internal_id_escaped = Db::escape_string($geocache['internal_id']);
        $geocache_internal = Db::select_row("
            select node, user_id from caches where cache_id='".$internal_id_escaped."'
        ");
        if ($geocache_internal['node'] != Settings::get('OC_NODE_ID')) {
            throw new Exception(
                "This site's database contains the geocache '$cache_code' which has been"
                . " imported from another OC node. OKAPI is not prepared for that."
            );
        }
        if ($geocache_internal['user_id'] != $request->token->user_id)
            throw new BadRequest("Only own caches may be edited.");

        $problems = [];
        $change_sqls_escaped = [];

        $langpref = $request->get_parameter('langpref');
        if (!$langpref) $langpref = "en";
        $langprefs = explode("|", $langpref);

        Okapi::gettext_domain_init($langprefs);
        try
        {
            /**
             * Note on attributes:
             *
             * At OCDE there is the feature to deprecate attributes. This means
             * that they are still displayed in geocaches and are retained when
             * editing, but can no longer be added to geocaches (and no longer
             * be searched for).
             *
             * Currently there are two deprecated OCDE attribs: Aircraft required
             * (A75) and External listing (no A-code yet). When editing attributes
             * is implemented in Okapi, developers will need to know which
             * attribs are deprecated; so this may need some "deprecated" flag
             * in attribute-definitions.xml and services/attrs/attributes.
             * Depending on the implementation, an A-code for "External listing"
             * may be needed.
             */

            # passwd
            $newpw = $request->get_parameter('passwd');
            if ($newpw !== null)
            {
                $capabilities = OkapiServiceRunner::call(
                    'services/caches/capabilities',
                    new OkapiInternalRequest($request->consumer, $request->token, [])
                );
                if (strlen($newpw) > $capabilities['password_max_length']) {
                    $problems['passwd'] = sprintf(
                        _('The password must not be longer than %d characters.'),
                        $capabilities['password_max_length']
                    );
                } elseif (
                    Settings::get('OC_BRANCH') == 'oc.pl' &&
                    $geocache['type'] == 'Traditional' &&
                    $geocache['date_created'] > '2010-06-18 20:03:18'
                ) {
                    # We won't bother the user with the creation date thing here.
                    # The *current* rule is that OCPL sites do not allow tradi passwords.
                    # For older caches, the user won't see this message.

                    $problems['passwd'] = sprintf(
                        _('%s does not allow log passwords for traditional caches.'),
                        Okapi::get_normalized_site_name()
                    );
                } else {
                    $oldpw = Db::select_value("select logpw from caches where cache_id='".$internal_id_escaped."'");
                    if ($newpw != $oldpw)
                        $change_sqls_escaped[] = "logpw = '".Db::escape_string($newpw)."'";
                    unset($oldpw);
                }
            }
            unset($newpw);

            Okapi::gettext_domain_restore();
        }
        catch (Exception $e)
        {
            Okapi::gettext_domain_restore();
            throw $e;
        }

        # save changes
        if (count($problems) == 0 && count($change_sqls_escaped) > 0) {
            Db::execute("
                update caches
                set " . implode(', ', $change_sqls_escaped) . ", last_modified=NOW()
                where cache_id = '".$internal_id_escaped."'
            ");
        }

        $result = ['success' => count($problems) == 0, 'messages' => $problems];

        return Okapi::formatted_response($request, $result);
    }
}
