<?php

namespace okapi\services\users;

use Throwable;
use okapi\core\Cache;
use okapi\core\Db;
use okapi\Settings;

/**
 * Account deletion for services/users/delete (OCDE).
 *
 * The rules are a copy of the website's own: an account without caches, logs or reports is
 * deleted as user.class.php delete() does it (the userBeforeDelete trigger clears the dependent
 * tables); any other account is anonymized as the admins' GDPR deletion does it
 * (src/OcLegacy/Admin/Gdpr/GdprHandler.php): personal data removed, the user's caches locked and
 * neutralized, the log texts replaced, so the game's history stays intact for others. This is
 * what the site's terms promise when a user ends the agreement.
 */
class AccountDeletion
{
    /** user.class.php canDelete */
    public static function can_delete_completely($user_id)
    {
        $id = "'".Db::escape_string($user_id)."'";
        return Db::select_value("
            select
                (select count(*) from caches where user_id = $id)
                + (select count(*) from cache_logs where user_id = $id)
                + (select count(*) from cache_logs_archived where user_id = $id)
                + (select count(*) from cache_reports where userid = $id)
        ") == 0;
    }

    /** Deletes or anonymizes the account; returns "deleted" or "anonymized". */
    public static function delete_account($user_id)
    {
        if (self::can_delete_completely($user_id)) {
            self::delete_completely($user_id);
            return 'deleted';
        }
        $files = self::anonymize($user_id);
        foreach ($files as $file)
            @unlink($file);
        return 'anonymized';
    }

    /** user.class.php delete(), plus the logins the website and OKAPI keep for the user. */
    private static function delete_completely($user_id)
    {
        $id = "'".Db::escape_string($user_id)."'";
        self::transaction(function () use ($id) {
            // The website writes the old record (name, email) into logentries; for a deletion the
            // user asked for, only the fact is kept.
            Db::execute("
                insert into logentries (date_created, module, eventid, userid, objectid1, objectid2, logtext, details)
                values (now(), 'user', 7, $id, $id, 0, concat('User ', $id, ' deleted on request (OKAPI)'), '')
            ");
            self::delete_logins($id);
            Db::execute("set @allowdelete = 1");
            try {
                Db::execute("delete from user where user_id = $id");
            } finally {
                Db::execute("set @allowdelete = 0");
            }
        });
        Cache::delete('users/mails/'.$user_id);
    }

    /**
     * GdprHandler::handle. Returns the picture files to remove once the database changes are
     * committed.
     */
    private static function anonymize($user_id)
    {
        $id = "'".Db::escape_string($user_id)."'";
        $cache_ids = Db::select_column("select cache_id from caches where user_id = $id");
        $log_ids = Db::select_column("select id from cache_logs where user_id = $id");
        $files = array();

        self::transaction(function () use ($id, $user_id, $cache_ids, $log_ids, &$files) {
            // processUser
            Db::execute("
                update user set
                    username = 'delete_".Db::escape_string($user_id)."',
                    last_login = null, password = null, roles = '', email = null, is_active_flag = 0,
                    latitude = 0, longitude = 0, accept_mailing = 0, usermail_send_addr = 0,
                    last_name = '', first_name = '',
                    watchmail_mode = 0, watchmail_hour = 0, watchmail_nextmail = 0, watchmail_day = 0,
                    statpic_logo = 0, statpic_text = '', notify_radius = 0, notify_oconly = 0,
                    gdpr_deletion = 1, description = '',
                    date_created = '1970-01-01 00:00:00', last_modified = '1970-01-01 00:00:00',
                    last_email_problem = null, new_pw_code = null, new_pw_date = null,
                    permanent_login_flag = 0, admin = 0, domain = null
                where user_id = $id
            ");
            Db::execute("delete from user_options where user_id = $id");
            Db::execute("delete from cache_lists where user_id = $id");
            Db::execute("delete from cache_list_watches where user_id = $id");
            Db::execute("delete from cache_list_bookmarks where user_id = $id");
            Db::execute("delete from email_user where from_user_id = $id or to_user_id = $id");
            Db::execute("delete from queries where user_id = $id");

            // processCaches
            foreach ($cache_ids as $cache_id) {
                $c = "'".Db::escape_string($cache_id)."'";
                Db::execute("
                    update caches set
                        name = '-Cache nach DSGVO gelöscht-', show_cachelists = 0, status = 6,
                        wp_gc = '', wp_gc_maintained = '', gdpr_deletion = 1
                    where cache_id = $c
                ");
                Db::execute("
                    update cache_desc set
                        `desc` = '".Db::escape_string(self::NEUTRALIZED_DESC)."',
                        desc_html = 1, hint = '', short_desc = ''
                    where cache_id = $c
                ");
                Db::execute("delete from cache_desc_modified where cache_id = $c");
                Db::execute("delete from cache_ignore where cache_id = $c");
                Db::execute("delete from caches_modified where cache_id = $c");
            }
            Db::execute("update cache_logs set text = '-User gelöscht-', gdpr_deletion = 1 where user_id = $id");
            Db::execute("delete from cache_adoptions where from_user_id = $id or to_user_id = $id");

            // Pictures of the user's caches and logs (object_type 2 = cache, 1 = log).
            $files = array_merge(
                self::delete_pictures($cache_ids, 2),
                self::delete_pictures($log_ids, 1)
            );

            Db::execute("delete from cache_logs_archived where user_id = $id");
            Db::execute("delete from cache_logs_modified where user_id = $id");
            Db::execute("delete from cache_ignore where user_id = $id");
            Db::execute("delete from field_note where user_id = $id");
            Db::execute("delete from logentries where userid = $id");
            Db::execute("delete from watches_logqueue where user_id = $id");
            Db::execute("delete from watches_notified where user_id = $id");
            Db::execute("delete from watches_waiting where user_id = $id");

            // Beyond GdprHandler: the user's watches and personal cache notes (coordinates type 2)
            // are personal data too, and the user's sessions and OKAPI tokens must end.
            Db::execute("delete from cache_watches where user_id = $id");
            Db::execute("delete from coordinates where user_id = $id and type = 2");
            self::delete_logins($id);
        });
        Cache::delete('users/mails/'.$user_id);
        return $files;
    }

    /** The text GdprHandler puts into a neutralized listing. */
    const NEUTRALIZED_DESC = '### DSGVO Löschung ###<br>Dieses Listing wurde aufgrund der Anforderungen des Urhebers, seine Daten im Rahmen des Datenschutzes zu löschen - neutralisiert.<br>Leider können wir keine Details aus dem Listing beibehalten. Die nicht personenbezogenen Parameter des Caches wie Lage, Wertung und die Logs Dritter können zum Erhalt des Spieles aber beibehalten.<br><br>###<br>Eurer OC Team - im Auftrag des Datenschutzbeauftragen';

    /**
     * GdprHandler deletePictures + deletePicturesModified: removes the picture rows of these
     * objects and returns their files (picture and thumbnail).
     */
    private static function delete_pictures($object_ids, $object_type)
    {
        if (count($object_ids) == 0)
            return array();
        $in = "'".implode("','", array_map('\okapi\core\Db::escape_string', $object_ids))."'";
        $t = "'".Db::escape_string($object_type)."'";
        $files = array();
        $rows = array_merge(
            Db::select_all("select url, thumb_url from pictures where object_id in ($in) and object_type = $t"),
            Db::select_all("select url, '' as thumb_url from pictures_modified where object_id in ($in) and object_type = $t")
        );
        foreach ($rows as $row)
            foreach (array($row['url'], $row['thumb_url']) as $url)
                if (($file = self::image_file($url)) !== null)
                    $files[] = $file;
        Db::execute("delete from pictures where object_id in ($in) and object_type = $t");
        Db::execute("delete from pictures_modified where object_id in ($in) and object_type = $t");
        return $files;
    }

    /** The file of an image URL below IMAGES_URL, or null. */
    private static function image_file($url)
    {
        $base = rtrim((string)Settings::get('IMAGES_URL'), '/').'/';
        if ($url === null || $url === '' || strpos($url, $base) !== 0)
            return null;
        $rel = ltrim(substr($url, strlen($base)), '/');
        if ($rel === '' || strpos($rel, '..') !== false)
            return null;
        return rtrim(Settings::get('IMAGES_DIR'), '/').'/'.$rel;
    }

    /**
     * The user's website sessions and OKAPI tokens and authorizations. (GdprHandler's own OKAPI
     * step joins okapi_consumers and would delete the application's consumer key itself.)
     */
    private static function delete_logins($id)
    {
        Db::execute("delete from logins where user_id = $id");
        Db::execute("delete from sys_sessions where user_id = $id");
        Db::execute("delete from okapi_tokens where user_id = $id");
        Db::execute("delete from okapi_authorizations where user_id = $id");
    }

    private static function transaction($work)
    {
        Db::execute("start transaction");
        try {
            $work();
            Db::execute("commit");
        } catch (Throwable $e) {
            Db::execute("rollback");
            throw $e;
        }
    }
}
