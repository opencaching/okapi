<?php

namespace okapi\services\caches\reports;

use okapi\Settings;
use okapi\Db;


class CacheReports
{
    public function getReasonsWithoutInternalIds()
    {
        $result = [];
        foreach (self::getReasons() as $reason) {
            unset($reason['internal_id']);
            $result[] = $reason;
        }
        return $result;
    }

    public function getReasonInternalId($reasonParam)
    {
        foreach (self::getReasons() as $reason)
            if ($reason['reason'] == $reasonParam)
                return $reason['internal_id'];
        return null;
    }

    private function getReasons()
    {
        static $reasons = [];

        if (!$reasons)
        {
            $branch = Settings::get('OC_BRANCH');

            # reasons are orders by "severity" here (descending)

            if ($branch == 'oc.de')
                $reasons[] = [
                    'reason' => 'Cache on private property',
                    'title' => _('Cache on private property'),
                    'explanation' => _('The geocache has been placed on private property, and the description does not state that the property owner has given permission to enter the place for geocaching.'),
                    'internal_id' => 1
                ];
            else
                $reasons[] = [
                    'reason' => 'Incorrect cache location',
                    'title' => _('Incorrect cache location'),
                    'explanation' => _('The geocache has been placed at a location where geocaching is not allowed.'),
                    'internal_id' => 1
                ];
            $reasons[] = [
                    'reason' => 'Copyright violation',
                    'title' => _('Copyright violation'),
                    'explanation' => _('Parts of the geocache description are copied or derived from other work without proper licensing.'), 
                    'internal_id' => ($branch == 'oc.de' ? 2 : 3)
            ];
            if ($branch == 'oc.pl')
                $reasons[] = [
                    'reason' => 'Needs to be achived',
                    'title' =>  _('Needs to be achived'),
                    'explanation' => _('The geocache should be archived, because it cannot be found or logged.'),
                    'internal_id' => 2
                ];
            else {
                $reasons[] = [
                    'reason' => 'Cache is gone',
                    'title' => _('Cache is gone'),
                    'explanation' => _('There is no doubt as to where the geocache was hidden, and the stash is empty.'),
                    'internal_id' => 3
                ];
                $reasons[] = [
                    'reason' => 'Description is unusable',
                    'title' => _('Description is unusable'),
                    'explanation' => _('The geocache description is flawed or outdated, so that the cache cannot be found. E.g. the coordinates are wrong, or a mystery image is broken.'),
                    'internal_id' => 5
                ];
            }
            $reasons[] = [
                    'reason' => 'Other',
                    'title' => _('Other'),
                    'explanation' => _('The geocache does not comply to the Opencaching site\'s terms of use for some other reason.'), 
                    'internal_id' => 4
                ];
        }

        return $reasons;
    }

    public function getReportsTableName()
    {
        return Settings::get('OC_BRANCH') == 'oc.de' ? 'cache_reports' : 'reports';
    }

    public function getClosedReportStatusId()
    {
        return Settings::get('OC_BRANCH') == 'oc.de' ? 3 : 2;
    }
}
