<?php

namespace okapi\services\caches\reports\status;

use okapi\Okapi;
use okapi\Db;
use okapi\Request\OkapiRequest;
use okapi\Exception\ParamMissing;
use okapi\services\caches\reports\CacheReports;


class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 1
        );
    }

    public static function call(OkapiRequest $request)
    {
        $report_uuids = $request->get_parameter('report_uuids');
        if ($report_uuids === null)
            throw new ParamMissing('report_uuids');
        if ($report_uuids === '')
            $report_uuids = [];
        else
            $report_uuids = explode('|', $report_uuids);
        $report_uuids_escaped = array_map('\okapi\Db::escape_string', $report_uuids);

        $reports_status_is_closed = Db::select_group_by('uuid', "
            select uuid, status = ".CacheReports::getClosedReportStatusId()." as is_closed
            from ".CacheReports::getReportsTableName()."
            where uuid in ('" . implode("','", $report_uuids) . "')
        ");

        $result = [];
        foreach ($report_uuids as $report_uuid) {
            if (array_key_exists($report_uuid, $reports_status_is_closed))
                $status = $reports_status_is_closed[$report_uuid][0]['is_closed'] ? 'Closed' : 'Open';
            else
                $status = null;
            $result[] = [$report_uuid => $status];
        }

        return Okapi::formatted_response($request, $result);
    }
}
