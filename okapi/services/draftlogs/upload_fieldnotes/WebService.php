<?php

namespace okapi\services\draftlogs\upload_fieldnotes;

use okapi\core\Exception\BadRequest;
use okapi\core\Exception\InvalidParam;
use okapi\core\Exception\ParamMissing;
use okapi\core\Db;
use okapi\core\Okapi;
use okapi\core\OkapiServiceRunner;
use okapi\core\Request\OkapiInternalRequest;
use okapi\core\Request\OkapiRequest;
use okapi\Settings;

class WebService
{
    # Log type names in field notes => field_note.type, as the OCDE website's own field
    # notes upload maps them (oc-server3: Oc\FieldNotes\Enum\LogType). The website handles
    # exactly these four types when a draft is turned into a log. Names are matched
    # case-insensitively; both the geocaching.com names used in field note files (Garmin,
    # cgeo, ...) and the OKAPI names are accepted. Records with other types are skipped.
    private static $fieldnote_types = [
        'found it'          => 1,
        'attended'          => 1,     # the website logs "Found" on an event as "Attended"
        "didn't find it"    => 2,
        'write note'        => 3,
        'comment'           => 3,
        'owner maintenance' => 3,
        'needs maintenance' => 1000,  # a note with "needs maintenance" set
    ];

    # field_note.text is varchar(255)
    const MAX_TEXT_LENGTH = 255;

    public static function options()
    {
        return array(
            'min_auth_level' => 3
        );
    }

    public static function call(OkapiRequest $request)
    {
        if (Settings::get('OC_BRANCH') != 'oc.de')
            throw new BadRequest('This method is not supported in this OKAPI installation. See the has_draft_logs field in services/apisrv/installation method.');

        $field_notes = $request->get_parameter('field_notes');
        if (!$field_notes) throw new ParamMissing('field_notes');

        // In order to understand the following, some serious explanations are in order. We are dealing here with a
        // string that resembles multiple CSV records. It is important to understand, that a line, identified by a line
        // termination character /n is not a 1:1 match withe a CSV record. In fact multiple such lines can be part of one
        // CSV record so this input variable has to treated very carefully. What complicates this further is that we cannot
        // dictate the character encoding "by design" as there are legacy applictions which have a hardcoded behaviour of
        // using UTF-16LE with no BOM. This encoding has been devised by Garmin and Groundspeak a very long time ago. More
        // modern applications use UTF-8 but we're best advised if we're tolerant to the character encoding which means
        // we must reliably detect it and convert it to UTF-8 ourselves.
        //
        // Further we accept input data as a base64 encoded string. This primarily because the OKAPI Browser (a Windows application)
        // cannot  deal with multiline string inputs, however, debugging a webservice like this is hardly possible without having
        // the OKAPI browser at hands, so we just accept the input string either plain oder base64 encoded.

        //First figure out whether it is base64 or not. If it is, decode it.

        if (self::is_base64($field_notes)) {
            $input = base64_decode($field_notes, true);
        } else {
            $input = $field_notes;
        }

        // At this point we're dealing with the plain $input string, we need to figure out the encoding and convert
        // to UTF-8. There is no single library function which proved to reliably identify the character encoding
        // for instance  mb_detect_encoding() miserably failed identifying UTF-LE w/o BOM correctly, consequently
        // it is the safest approach to do this manually with just a few lines of code which can be understood
        // by looking at it at a glance.

        if (strlen($input) < 3) {
            throw new InvalidParam('field_notes', "Input data is too short to be valid.");
        }

        switch (true) {
            case $input[0] === "\xEF" && $input[1] === "\xBB" && $input[2] === "\xBF": // UTF-8 BOM
                $output = substr($input, 3);
                break;
            case $input[0] === "\xFE" && $input[1] === "\xFF": // UTF-16BE BOM
            case $input[0] === "\x00" && $input[2] === "\x00":
                $output = mb_convert_encoding($input, 'UTF-8', 'UTF-16BE');
                break;
            case $input[0] === "\xFF" && $input[1] === "\xFE": // UTF-16LE BOM
            case $input[1] === "\x00":
                $output = mb_convert_encoding($input, 'UTF-8', 'UTF-16LE');
                break;
            default:
                $output = $input;
        }

        $notes = self::parse_notes($output);
        $processed_records = 0;
        $draft_ids = array();

        foreach ($notes['records'] as $n)
        {
            try {
                $geocache = OkapiServiceRunner::call(
                    'services/caches/geocache',
                    new OkapiInternalRequest($request->consumer, $request->token, array(
                        'cache_code' => $n['code'],
                        'fields' => 'internal_id'
                    ))
                );
            } catch (\Exception $e) {
                continue;
            }

            $date_timestamp = strtotime($n['date']);
            if ($date_timestamp === false) {
                continue;
            }
            $date = date("Y-m-d H:i:s", $date_timestamp);

            $type        = $n['type'];
            $user_id     = $request->token->user_id;
            $geocache_id = $geocache['internal_id'];
            $text        = self::to_html($n['log'], self::MAX_TEXT_LENGTH);

            Db::query("
                insert into field_note (
                    user_id, geocache_id, type, date, text
                ) values (
                    '".Db::escape_string($user_id)."',
                    '".Db::escape_string($geocache_id)."',
                    '".Db::escape_string($type)."',
                    '".Db::escape_string($date)."',
                    '".Db::escape_string($text)."'
                )
            ");
            $draft_ids[] = (string)Db::last_insert_id();
            $processed_records++;
        }

        // total_records is the number of CSV records found in the input.
        // processed_records is the number actually inserted; it may be less
        // because fieldnotes from multi-platform apps contain records for
        // other platforms (GC, OP, …) and log types not supported here.

        $result = array(
            'success'           => true,
            'total_records'     => $notes['total_records'],
            'processed_records' => $processed_records,
            'draft_ids'         => $draft_ids
        );
        return Okapi::formatted_response($request, $result);
    }

    // ------------------------------------------------------------------
    // Operates on a sanitized utf-8 string of what is known as "Fieldnotes"
    // A fieldnotes are a list of CSV formatted records condensed into a
    // single string stretching across multiple "lines" where lines are marked
    // and terminated by linefeed characters \n. In its simplest form a record
    // matches a line, e.g.:
    //
    // OC1012,2023-11-27T08:27:48Z,Found it,"Thx to Retriever12 for the cache"
    //
    // This example shows that each record consist of four fields:
    // cache_code, log date, log type, and a draft log text
    //
    // What makes this challenging to parse is that the draft log can be very
    // long and it can itself contain line control characters so it stretches
    // across multiple lines in string.

    private static function parse_notes($field_notes)
    {
        $lines = self::parse_csv($field_notes);
        $records       = [];
        $total_records = 0;

        foreach ($lines as $line) {
            $total_records++;
            $line = trim($line);
            $fields = str_getcsv($line);
            if (count($fields) < 4) continue;  // not a "code,date,type,log" record

            $code = $fields[0];
            if (strpos($code, 'OC') !== 0) continue;  // other platform (GC, OP, ...); this service is OCDE-only

            $date = $fields[1];
            $type_name = mb_strtolower(trim($fields[2]), 'UTF-8');
            if (!isset(self::$fieldnote_types[$type_name])) continue;
            $type = self::$fieldnote_types[$type_name];

            $log = $fields[3];

            $records[] = [
                'code' => $code,
                'date' => $date,
                'type' => $type,
                'log'  => $log,
            ];
        }
        return ['records' => $records, 'total_records' => $total_records];
    }


    // ------------------------------------------------------------------
    // Split lines into an array of records. Each element in the $output
    // array will then contain a string, which can strech across multiple
    // lines, each terminated with a linefeed \n.
    //
    // In this process we also skip records that will not be understood
    // by the platform, where platform is one of: geocaching.com, opencaching.{de,pl,...}
    //
    // A record starts with a cache code of any platform (OC, GC, OP, ...) followed
    // by an ISO date, so records of other platforms don't get glued onto the
    // previous log text. parse_notes() then keeps only the OC records.

    private static function parse_csv($field_notes)
    {
        $output = [];
        $buffer = '';
        $start = true;

        $lines = explode("\n", $field_notes);
        $lines = array_filter($lines); // Drop empty lines

        foreach ($lines as $line) {
            if ($start) {
                $buffer = $line;
                $start = false;
            } else {
                // A new record starts with a cache code followed by an ISO date
                if (preg_match('/^[A-Z]{2}\w+,\d{4}-/', $line)) {
                    $output[] = trim($buffer);
                    $buffer = $line;
                } else {
                    $buffer .= "\n" . $line;
                }
            }
        }

        if (!$start) {
            $output[] = trim($buffer);
        }
        return $output;
    }

    // ------------------------------------------------------------------
    // The website loads a draft into its HTML log editor, so store the text
    // the way the website's own field notes upload does: line breaks as <br />.
    // Unlike the website, also escape <, > and &, so the text shows as written.
    // If the result is too long for the column, use the longest prefix of the
    // plain text (not of the HTML) that fits, so no tag or entity is cut in half.

    private static function to_html($text, $max_length)
    {
        $text = str_replace("\r\n", "\n", $text);
        $html = self::text2html($text);
        if (mb_strlen($html, 'UTF-8') <= $max_length)
            return $html;

        $fits = 0;                                # longest prefix length known to fit
        $too_long = mb_strlen($text, 'UTF-8');    # shortest prefix length known not to fit
        while ($too_long - $fits > 1) {
            $middle = intdiv($fits + $too_long, 2);
            if (mb_strlen(self::text2html(mb_substr($text, 0, $middle, 'UTF-8')), 'UTF-8') <= $max_length)
                $fits = $middle;
            else
                $too_long = $middle;
        }
        return self::text2html(mb_substr($text, 0, $fits, 'UTF-8'));
    }

    private static function text2html($text)
    {
        return nl2br(htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8'));
    }

    // ------------------------------------------------------------------
    // Check whether a string ($s) is base64 encoded or not.

    private static function is_base64($s)
    {
        $decoded = base64_decode($s, true);
        if ($decoded === false) {
            return false;
        }
        return base64_encode($decoded) === $s;
    }
}
