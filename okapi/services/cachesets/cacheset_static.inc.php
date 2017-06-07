<?php

namespace okapi\services\cachesets;

use okapi\Settings;
use Exception;

/** Collection of static methods related to cache sets. */
class CachesetStatics
{
    private static $cacheset_types = array(
        #
        # OKAPI does not expose cacheset type IDs.
        # Instead, it uses the following "code words".
        # Changing this may introduce nasty bugs
        # CONTACT ME BEFORE YOU MODIFY THIS!
        #
        'oc.pl' => array(
            'Geo-drawing' => 1,
            'Sightseeing' => 2,
            'Nature' => 3,
            'Thematic' => 4
        )
    );

    /** E.g. 'Traditional' => 2. For unknown names throw an Exception. */
    public static function cacheset_type_name2id($name)
    {
        $ref = &self::$cacheset_types[Settings::get('OC_BRANCH')];
        if (isset($ref[$name]))
            return $ref[$name];

        throw new Exception("Method cacheset_type_name2id called with unsupported cacheset ".
            "type name '$name'.");
    }

    /** E.g. 2 => 'Traditional'. For unknown names throw an Exception. */
    public static function cacheset_type_id2name($id)
    {
        static $reversed = null;
        if ($reversed == null)
        {
            $reversed = array();
            foreach (self::$cacheset_types[Settings::get('OC_BRANCH')] as $key => $value)
                $reversed[$value] = $key;
        }
        if (isset($reversed[$id]))
            return $reversed[$id];

        throw new Exception("Method cacheset_type_id2name called with unsupported cacheset ".
            "type id '$id'.");
    }

    private static $cacheset_statuses = array(
        'Available' => 1, 'Temporarily unavailable' => 4, 'Archived' => 3
    );

    /** E.g. 'Available' => 1. For unknown names throws an Exception. */
    public static function cacheset_status_name2id($name)
    {
        if (isset(self::$cacheset_statuses[$name]))
            return self::$cacheset_statuses[$name];

        throw new Exception("Method cacheset_status_name2id called with invalid name '$name'.");
    }

    /** E.g. 1 => 'Available'. For unknown ids returns 'Archived'. */
    public static function cacheset_status_id2name($id)
    {
        static $reversed = null;
        if ($reversed == null)
        {
            $reversed = array();
            foreach (self::$cacheset_statuses as $key => $value)
                $reversed[$value] = $key;
        }
        if (isset($reversed[$id]))
            return $reversed[$id];

        return 'Archived';
    }
}

/** Collection of static methods related to cache set log entries. */
class CsLogStatics
{
    private static $csLog_types = array(
        #
        # OKAPI does not expose cacheset logs IDs.
        # Instead, it uses the following "code words".
        # Changing this may introduce nasty bugs
        # CONTACT ME BEFORE YOU MODIFY THIS!
        'oc.pl' => array(
            'Comment' => 1, 'Completed' => 2
        )
    );

    /** E.g. 'Traditional' => 2. For unknown names throw an Exception. */
    public static function cslog_type_name2id($name)
    {
        $ref = &self::$csLog_types[Settings::get('OC_BRANCH')];
        if (isset($ref[$name]))
            return $ref[$name];

        throw new Exception("Method cslog_type_name2id called with unsupported cacheset log ".
            "type name '$name'.");
    }

    /** E.g. 2 => 'Traditional'. For unknown names return type 'Other'. */
    public static function cslog_type_id2name($id)
    {
        static $reversed = null;
        if ($reversed == null)
        {
            $reversed = array();
            foreach (self::$csLog_types[Settings::get('OC_BRANCH')] as $key => $value)
                $reversed[$value] = $key;
        }
        if (isset($reversed[$id]))
            return $reversed[$id];

        return 'Other';
    }
}
