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
        $cache_codes = $request->get_parameter('cache_codes');
        if ($cache_codes === null) throw new ParamMissing('cache_codes');

        # Issue 106 requires us to allow empty list of cache codes to be passed into this method.
        # All of the queries below have to be ready for $cache_codes to be empty!

        $langpref = $request->get_parameter('langpref');
        if (!$langpref) $langpref = "en";
        $location_source = $request->get_parameter('location_source');
        $location_change_prefix = $request->get_parameter('location_change_prefix');

        # Start creating ZIP archive.

        $tempfilename = Okapi::get_var_dir()."/garmin".time().rand(100000,999999).".zip";
        $zip = new ZipArchive();
        if ($zip->open($tempfilename, ZIPARCHIVE::CREATE) !== true)
            throw new Exception("ZipArchive class could not create temp file $tempfilename. Check permissions!");

        # Create basic structure
        $zip->addEmptyDir("data");
        $zip->addEmptyDir("index");
        $zip->addEmptyDir("index/com");
        $zip->addEmptyDir("index/com/garmin");
        $zip->addEmptyDir("index/com/garmin/geocaches");
        $zip->addEmptyDir("index/com/garmin/geocaches/v0");

        # Include a GPX file compatible with Garmin devices. It should include all
        # Geocaching.com (groundspeak:) and Opencaching.com (ox:) extensions. It will
        # also include personal data (if the method was invoked using Level 3 Authentication).

        $gpx_response = OkapiServiceRunner::call('services/caches/formatters/gpx', new OkapiInternalRequest(
            $request->consumer, $request->token, array(
                'cache_codes' => $cache_codes,
                'langpref' => $langpref,
                'ns_ground' => 'true',
                'ns_ox' => 'true',
                'images' => 'none',
                'attrs' => 'ox:tags',
                'trackables' => 'desc:count',
                'alt_wpts' => 'true',
                'recommendations' => 'desc:count',
                'latest_logs' => 'true',
                'lpc' => 'all',
                'my_notes' => ($request->token != null) ? "desc:text" : "none",
                'location_source' => $location_source,
                'location_change_prefix' => $location_change_prefix
            ))); 

        $file_data = $gpx_response->get_body();
        $file_item_name = "data_".time()."_".rand(100000,999999).".gpx";
        $ggz_file = array(
        		'name' => $file_item_name,
        		'crc32' => sprintf('%08X', crc32($file_data)),
                'caches' => $gpx_response->ggz_index
        );
        
        $zip->addFromString("data/".$file_item_name, $file_data);
        unset($file_data);
        unset($gpx_response);
        
        $vars = array();
        $vars['files'] = array($ggz_file);
        
        ob_start();
        include 'ggzindex.tpl.php';
        $index_content = ob_get_clean();
        
        $zip->addFromString("index/com/garmin/geocaches/v0/index.xml", $index_content);
        
        $zip->close();

        # The result could be big. Bigger than our memory limit. We will
        # return an open file stream instead of a string. We also should
        # set a higher time limit, because downloading this response may
        # take some time over slow network connections (and I'm not sure
        # what is the PHP's default way of handling such scenario).

        set_time_limit(600);
        $response = new OkapiHttpResponse();
        $response->content_type = "application/zip";
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
