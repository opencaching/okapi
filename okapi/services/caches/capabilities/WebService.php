<?php

namespace okapi\services\caches\capabilities;

use okapi\core\Db;
use okapi\core\Exception\InvalidParam;
use okapi\core\Okapi;
use okapi\core\Request\OkapiRequest;
use okapi\Settings;

class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 1
        );
    }

    private static $valid_field_names = [
        'types', 'sizes', 'statuses', 'has_ratings', 'password_max_length'
    ];

    public static function call(OkapiRequest $request)
    {
        $result = array();

        $fields = $request->get_parameter('fields');
        if (!$fields) $fields = "types|sizes|statuses|has_ratings";
        $fields = explode("|", $fields);
        foreach ($fields as $field)
            if (!in_array($field, self::$valid_field_names))
                throw new InvalidParam('fields', "'$field' is not a valid field code.");

        if (in_array('types', $fields)) {
            $result['types'] = Okapi::get_local_cachetypes();
        }
        if (in_array('sizes', $fields)) {
            $result['sizes'] = Okapi::get_local_cachesizes();
        }
        if (in_array('statuses', $fields)) {
            $result['statuses'] = Okapi::get_local_statuses();
        }
        if (in_array('has_ratings', $fields)) {
            $result['has_ratings'] = (Settings::get('OC_BRANCH') == 'oc.pl');
        }
        if (in_array('password_max_length', $fields)) {
            $result['password_max_length'] = Db::field_length('caches', 'logpw') + 0;
        }

        return Okapi::formatted_response($request, $result);
    }
}
