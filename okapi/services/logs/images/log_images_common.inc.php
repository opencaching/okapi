<?php

/**
 * Common code of add.php and edit.php.
 */

namespace okapi\services\logs\images;

use Exception;
use okapi\Okapi;
use okapi\Db;
use okapi\OkapiRequest;
use okapi\InvalidParam;
use okapi\Settings;
use okapi\BadRequest;


class LogImagesCommon
{
    /**
     * OCDE supports arbitrary ordering of log images. The pictures table
     * contains sequence numbers, which are always > 0 and need not to be
     * consecutive (may have gaps). There is a unique index which prevents
     * inserting duplicate seq numbers for the same log.
     *
     * OCPL sequence numbers currently are always = 1.
     *
     * The purpose of this function is to bring the supplied 'position'
     * parameter into bounds, and to calculate an appropriate sequence number
     * from it.
     *
     * This function is always called when adding images. When editing images,
     * it is called only for OCDE and if the position parameter was supplied.
     */

    static function prepare_position($log_internal_id, $position, $end_offset)
    {
        if (Settings::get('OC_BRANCH') == 'oc.de' && $position !== null)
        {
            # Prevent race conditions when creating sequence numbers if a
            # user tries to upload multiple images simultaneously. With a
            # few picture uploads per hour - most of them probably witout
            # a 'position' parameter - the lock is neglectable.

            Db::execute('lock tables pictures write');
        }

        $log_images_count =  Db::select_value("
            select count(*)
            from pictures
            where object_type = 1 and object_id = '".Db::escape_string($log_internal_id)."'
        ");

        if (Settings::get('OC_BRANCH') == 'oc.pl')
        {
            # Ignore the position parameter, always insert at end.
            # Remember that this function is NOT called when editing OCPL images.

            $position = $log_images_count;
            $seq = 1;
        }
        else
        {
            if ($position === null || $position >= $log_images_count) {
                $position = $log_images_count - 1 + $end_offset;
                $seq = Db::select_value("
                    select max(seq)
                    from pictures
                    where object_type = 1 and object_id = '".Db::escape_string($log_internal_id)."'
                ") + $end_offset;
            } else if ($position <= 0) {
                $position = 0;
                $seq = 1;
            } else {
                $seq = Db::select_value("
                    select seq
                    from pictures
                    where object_type = 1 and object_id = '".Db::escape_string($log_internal_id)."'
                    order by seq
                    limit ".($position+0).", 1
                ");
            }
        }

        # $position may have become a string, as returned by database queries
        return array($position + 0, $seq, $log_images_count);
    }
}
