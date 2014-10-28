<?php

namespace okapi\services\caches\formatters\ggz;

use okapi\Okapi;
use okapi\Cache;
use okapi\Settings;
use okapi\OkapiRequest;
use okapi\OkapiHttpResponse;
use okapi\OkapiInternalRequest;
use okapi\OkapiServiceRunner;
use okapi\BadRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\OkapiAccessToken;
use okapi\services\caches\search\SearchAssistant;

use \ZipArchive;
use \Exception;

require_once($GLOBALS['rootpath']."okapi/services/caches/formatters/gpx.php");

class WebService
{
    private static $shutdown_function_registered = false;
    private static $files_to_unlink = array();

    public static function options()
    {
        return array(
            'min_auth_level' => 1
        );
    }

    public static function call(OkapiRequest $request)
    {
        $gpx_result = \okapi\services\caches\formatters\gpx\WebService::create_gpx(
                $request,
                \okapi\services\caches\formatters\gpx\WebService::FLAG_CREATE_GGZ_IDX
        );

        $tempfilename = Okapi::get_var_dir()."/garmin".time().rand(100000,999999).".zip";
        $zip = new ZipArchive();
        if ($zip->open($tempfilename, ZIPARCHIVE::CREATE) !== true)
            throw new Exception("ZipArchive class could not create temp file $tempfilename. Check permissions!");

        # Include a GPX file compatible with Garmin devices. It should include all
        # Geocaching.com (groundspeak:) and Opencaching.com (ox:) extensions. It will
        # also include personal data (if the method was invoked using Level 3 Authentication).

        $file_item_name = "data_".time()."_".rand(100000,999999).".gpx";
        $ggz_file = array(
            'name' => $file_item_name,
            'crc32' => sprintf('%08X', crc32($gpx_result['gpx'])),
            'caches' => $gpx_result['ggz_entries']
        );

        $vars = array();
        $vars['files'] = array($ggz_file);

        ob_start();
        include 'ggzindex.tpl.php';
        $index_content = ob_get_clean();

        //$zip->addEmptyDir("index");
        //$zip->addEmptyDir("index/com");
        //$zip->addEmptyDir("index/com/garmin");
        //$zip->addEmptyDir("index/com/garmin/geocaches");
        //$zip->addEmptyDir("index/com/garmin/geocaches/v0");
        $zip->addFromString("index/com/garmin/geocaches/v0/index.xml", $index_content);

        //$zip->addEmptyDir("data");
        $zip->addFromString("data/".$file_item_name, $gpx_result['gpx']);

        $zip->close();

        # The result could be big. Bigger than our memory limit. We will
        # return an open file stream instead of a string. We also should
        # set a higher time limit, because downloading this response may
        # take some time over slow network connections (and I'm not sure
        # what is the PHP's default way of handling such scenario).

        set_time_limit(600);
        $response = new OkapiHttpResponse();
        $response->content_type = "application/x-ggz; charset=utf-8";
        $response->content_disposition = 'attachment; filename="geocaches.ggz"';
        $response->stream_length = filesize($tempfilename);
        $response->body = fopen($tempfilename, "rb");
        $response->allow_gzip = false;
        self::add_file_to_unlink($tempfilename);
        return $response;
    }

    private static function add_file_to_unlink($filename)
    {
        if (!self::$shutdown_function_registered)
            register_shutdown_function(array("okapi\\services\\caches\\formatters\\ggz\\WebService", "unlink_temporary_files"));
        self::$files_to_unlink[] = $filename;
    }

    public static function unlink_temporary_files()
    {
        foreach (self::$files_to_unlink as $filename)
            @unlink($filename);
        self::$files_to_unlink = array();
    }
}
