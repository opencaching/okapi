<?php

namespace okapi\services\OCPL\paths\geopaths;

use okapi\Settings;

class GeopathStatics
{

    private static $geocache_types = array(
        #
        # OKAPI does not expose type IDs. Instead, it uses the following
        # "code words". Only the "primary" cache types are documented.
        # This means that all other types may (in theory) be altered.
        # Cache type may become "primary" ONLY when *all* OC servers recognize
        # that type.
        #
        # Changing this may introduce nasty bugs (e.g. in the replicate module).
        # CONTACT ME BEFORE YOU MODIFY THIS!
        #
        'oc.pl' => array(
            # Primary types (documented, cannot change)
            'Traditional' => 2, 'Multi' => 3, 'Quiz' => 7, 'Virtual' => 4,
            'Event' => 6,
            # Additional types (may get changed)
            'Other' => 1, 'Webcam' => 5,
            'Moving' => 8, 'Podcast' => 9, 'Own' => 10,
        )
    );

    /** E.g. 'Traditional' => 2. For unknown names throw an Exception. */
    public static function geopath_type_name2id($name)
    {
        $ref = &self::$geocache_types[Settings::get('OC_BRANCH')];
        if (isset($ref[$name]))
            return $ref[$name];
            throw new Exception("Method geopath_type_name2id called with unsupported geopath ".
                "type name '$name'. You should not allow users to submit geopath ".
                "of non-primary type.");
    }

    /** E.g. 2 => 'Traditional'. For unknown ids returns "Other". */
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
            return "Other";
    }

    private static $geopath_statuses = array(
        'Available' => 1, 'Temporarily unavailable' => 2, 'Archived' => 3
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


