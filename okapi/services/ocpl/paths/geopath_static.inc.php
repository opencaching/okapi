<?php

namespace okapi\services\OCPL\paths;

use okapi\Settings;
use Exception;

class GeopathStatics
{

    private static $geocache_types = array(
        #
        # OKAPI does not expose type IDs. Instead, it uses the following
        # "code words".
        # Changing this may introduce nasty bugs (e.g. in the replicate module).
        # CONTACT ME BEFORE YOU MODIFY THIS!
        #
        'oc.pl' => array(
            'Geodraw' => 1, 'Touring' => 2, 'Nature' => 3, 'Thematic' => 4
        )
    );

    /** E.g. 'Traditional' => 2. For unknown names throw an Exception. */
    public static function geopath_type_name2id($name)
    {
        $ref = &self::$geocache_types[Settings::get('OC_BRANCH')];
        if (isset($ref[$name]))
            return $ref[$name];

        throw new Exception("Method geopath_type_name2id called with unsupported geopath ".
            "type name '$name'.");
    }

    /** E.g. 2 => 'Traditional'. For unknown names throw an Exception. */
    public static function geopath_type_id2name($id)
    {
        static $reversed = null;
        if ($reversed == null)
        {
            $reversed = array();
            foreach (self::$geocache_types[Settings::get('OC_BRANCH')] as $key => $value)
                $reversed[$value] = $key;
        }
        if (isset($reversed[$id]))
            return $reversed[$id];

        throw new Exception("Method geopath_type_id2name called with unsupported geopath ".
            "type id '$id'.");
    }

    private static $geopath_statuses = array(
        'Available' => 1, 'Temporarily unavailable' => 4, 'Archived' => 3
    );

    /** E.g. 'Available' => 1. For unknown names throws an Exception. */
    public static function geopath_status_name2id($name)
    {
        if (isset(self::$geopath_statuses[$name]))
            return self::$geopath_statuses[$name];

        throw new Exception("Method geopath_status_name2id called with invalid name '$name'.");
    }

    /** E.g. 1 => 'Available'. For unknown ids returns 'Archived'. */
    public static function geopath_status_id2name($id)
    {
        static $reversed = null;
        if ($reversed == null)
        {
            $reversed = array();
            foreach (self::$geopath_statuses as $key => $value)
                $reversed[$value] = $key;
        }
        if (isset($reversed[$id]))
            return $reversed[$id];

        return 'Archived';
    }
}

class GpLogStatics
{
    private static $geocache_types = array(
        #
        # OKAPI does not expose type IDs. Instead, it uses the following
        # "code words".
        # Changing this may introduce nasty bugs (e.g. in the replicate module).
        # CONTACT ME BEFORE YOU MODIFY THIS!
        #
        'oc.pl' => array(
            'Comment' => 1, 'Completed' => 2
        )
    );

    /** E.g. 'Traditional' => 2. For unknown names throw an Exception. */
    public static function gplog_type_name2id($name)
    {
        $ref = &self::$geocache_types[Settings::get('OC_BRANCH')];
        if (isset($ref[$name]))
            return $ref[$name];

            throw new Exception("Method gplog_type_name2id called with unsupported geopath log ".
                "type name '$name'.");
    }

    /** E.g. 2 => 'Traditional'. For unknown names return type 'Other'. */
    public static function gplog_type_id2name($id)
    {
        static $reversed = null;
        if ($reversed == null)
        {
            $reversed = array();
            foreach (self::$geocache_types[Settings::get('OC_BRANCH')] as $key => $value)
                $reversed[$value] = $key;
        }
        if (isset($reversed[$id]))
            return $reversed[$id];

        return 'Other';
    }

}

