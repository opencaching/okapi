<?php

namespace okapi\services\caches\reports\reasons;

use Exception;
use okapi\Okapi;
use okapi\Db;
use okapi\OkapiRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\BadRequest;
use okapi\OkapiInternalRequest;
use okapi\OkapiServiceRunner;
use okapi\OkapiAccessToken;
use okapi\Settings;


class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 0
        );
    }

    public static function call(OkapiRequest $request)
    {
        // Mock-up implementation, needs to be outsourced to some global place
        // and include the internal report reason IDs.

        $result = [
            [
                'reason' => 'Cache on private property',
                'title' => _('Cache on private property'),
                'explanation' => _('The geocache has been placed on private property, and the description does not state that the property owner has given permission to enter the place for geocaching.')
            ],
            [
                'reason' => 'Copyright violation',
                'title' => _('Copyright violation'),
                'explanation' => _('Parts of the geocache description are copied or derived from other work without proper licensing.') 
            ]
        ];
        if (Settings::get('OC_BRANCH') == 'oc.de') {
            $result[] = [
                'reason' => 'Cache is gone',
                'title' => _('Cache is gone'),
                'explanation' => _('There is no doubt as to where the geocache was hidden, and the stash is empty.')
            ];
            $result[] = [
                'reason' => 'Description is unusable',
                'title' => _('Description is unusable'),
                'explanation' => _('The geocache description is flawed or outdated, so that the cache cannot be found. E.g. the coordinates are wrong, or a mystery image is broken.')
            ];
        } else {
            $result[] = [
                'reason' => 'Needs to be achived',
                'title' =>  _('Needs to be achived'),
                'explanation' => _('The geocache should be archived, because it cannot be found or logged.')
            ];
        }
        $result[] = [
            'reason' => 'Other',
            'title' => _('Other'),
            'explanation' => _('The geocache does not comply to the Opencaching site\'s terms of use for some other reason.') 
        ];

        return Okapi::formatted_response($request, $result);
    }
}
