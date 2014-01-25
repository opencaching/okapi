<?php

namespace okapi\services\caches\save_personal_notes;

use Exception;
use okapi\Okapi;
use okapi\Db;
use okapi\OkapiRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\BadRequest;
use okapi\OkapiInternalRequest;
use okapi\OkapiServiceRunner;
use okapi\OkapiAccessToken;
use okapi\Settings;


class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 3
        );
    }

    public static function call(OkapiRequest $request)
    {
        # Get current notes, and verify cache_code
        $cache_code = $request->get_parameter('cache_code');
        if ($cache_code == null)
            throw new ParamMissing('cache_code');
        $geocache = OkapiServiceRunner::call('services/caches/geocache', new OkapiInternalRequest(
            $request->consumer, $request->token,
            array('cache_code' => $cache_code, 'fields' => 'my_notes|internal_id')));
        $cache_id = $geocache['internal_id'];

        $old_value = $request->get_parameter('old_value');
        $new_value = $request->get_parameter('new_value');
        // we do strip tags when returning data, so strip them too when saving
        $new_value = strip_tags($new_value);

        $current_value = $geocache['my_notes'];

        # Returned values
        $ret_value = null;
        $ret_replace = false;

        if ($current_value == null || trim($current_value) == '' || 
                self::str_equals($old_value, $current_value))
        {
            // current == empty, or current == old, 
            // we can directly proceed with change
            $ret_replace = true;
            if ($new_value == null || trim($new_value) == ''){
                // empty new value means delete
                $ret_value = null;
                self::remove_notes($cache_id, $request->token->user_id);
            } else {
                $ret_value = $new_value;
                self::update_notes($cache_id, $request->token->user_id, $ret_value);
            }
        } else {
            // client does not have current notes, we must merge
            $ret_value = rtrim($current_value)."\r\n\r\n".ltrim($new_value);
            self::update_notes($cache_id, $request->token->user_id, $ret_value);
        }

        $result = array(
            'saved_value' => $ret_value,
            'replaced' => $ret_replace
        );
        return Okapi::formatted_response($request, $result);
    }

    private static function str_equals($str1, $str2)
    {
        if ($str1 == null)
            $str1 = '';
        if ($str2 == null)
            $str2 = '';
        $str1 = mb_ereg_replace("[ \t\n\r\x0B]+", '', $str1);
        $str2 = mb_ereg_replace("[ \t\n\r\x0B]+", '', $str2);

        return $str1 == $str2;
    }

    private static function update_notes($cache_id, $user_id, $new_notes)
    {
        if (Settings::get('OC_BRANCH') == 'oc.de')
        {
            // See
            // https://github.com/OpencachingDeutschland/oc-server3/tree/master/htdocs/libse/CacheNote
            // http://www.opencaching.de/okapi/devel/dbstruct
            $rs = Db::query('
                    select max(`id`) as `id`
                      from `coordinates`
                     where `type` = 2  -- personal note
                       and `cache_id` = \''.mysql_real_escape_string($cache_id).'\'
                       and `user_id` = \''.mysql_real_escape_string($user_id).'\'
                ');
            $id = null;
            if($row = mysql_fetch_assoc($rs))
            {
                $id = $row['id'];
            }
            if ($id == null)
            {
                Db::query('
                    insert into `coordinates` (`type`, `latitude`, `longitude`, `cache_id`, `user_id`, `description`)
                    values (2, 0, 0,
                            \''.mysql_real_escape_string($cache_id).'\',
                            \''.mysql_real_escape_string($user_id).'\',
                            \''.mysql_real_escape_string($new_notes).'\')
                ');
            } else {
                Db::query('
                    update `coordinates`
                       set `description` = \''.mysql_real_escape_string($new_notes).'\',
                     where `id` = \''.mysql_real_escape_string($id).'\'
                       and `type` = 2
                ');
            }
        }
        elseif (Settings::get('OC_BRANCH') == 'oc.pl')
        {
            $rs = Db::query('
                    select max(`note_id`) as `id`
                      from `cache_notes`
                     where `cache_id` = \''.mysql_real_escape_string($cache_id).'\'
                       and `user_id` = \''.mysql_real_escape_string($user_id).'\'
                ');
            $id = null;
            if($row = mysql_fetch_assoc($rs))
            {
                $id = $row['id'];
            }
            if ($id == null)
            {
                Db::query('
                    insert into `cache_notes` (`cache_id`, `user_id`, `date`, `desc_html`, `desc`)
                    values (\''.mysql_real_escape_string($cache_id).'\',
                            \''.mysql_real_escape_string($user_id).'\',
                            NOW(), 0,
                            \''.mysql_real_escape_string($new_notes).'\')
                ');
            } else {
                Db::query('
                    update `cache_notes`
                       set `desc` = \''.mysql_real_escape_string($new_notes).'\',
                           `desc_html` = 0,
                           `date` = NOW()
                     where `note_id` = \''.mysql_real_escape_string($id).'\'
                ');
            }
        } else 
        {
            throw new BadRequest('This method is unimplemented in the current Opencaching node');
        }
    }

    private static function remove_notes($cache_id, $user_id)
    {
        if (Settings::get('OC_BRANCH') == 'oc.de')
        {
            Db::execute('
                    delete from `coordinates`
                    where `type` = 2  -- personal note
                      and `cache_id` = \''.mysql_real_escape_string($cache_id).'\'
                      and `user_id` = \''.mysql_real_escape_string($user_id).'\'
                ');
        }
        elseif (Settings::get('OC_BRANCH') == 'oc.pl')
        {
            Db::execute('
                    delete from `cache_notes`
                    where `cache_id` = \''.mysql_real_escape_string($cache_id).'\'
                      and `user_id` = \''.mysql_real_escape_string($user_id).'\'
                ');
        } 
        else {
            throw new BadRequest('This method is unimplemented in the current Opencaching node');
        }
    }
}
