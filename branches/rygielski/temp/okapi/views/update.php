<?php

namespace okapi\views\update;

use okapi\OkapiHttpResponse;

use Exception;
use okapi\Okapi;
use okapi\Db;
use okapi\OkapiRequest;
use okapi\OkapiRedirectResponse;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\OkapiServiceRunner;
use okapi\OkapiInternalRequest;

class View
{
	public static function get_current_version()
	{
		try {
			$db_version = Db::select_value("
				select value
				from okapi_vars
				where var = 'db_version'
			");
		} catch (Exception $e) {
			# Table okapi_vars does not exist.
			return 0;
		}
		return $db_version + 0;
	}
	
	public static function get_max_version()
	{
		$max_db_version = 0;
		foreach (get_class_methods(__CLASS__) as $name)
		{
			if (strpos($name, "ver") === 0)
			{
				$ver = substr($name, 3) + 0;
				if ($ver > $max_db_version)
					$max_db_version = $ver;
			}
		}
		return $max_db_version;
	}
	
	public static function out($str)
	{
		print $str;
		ob_flush();
		flush();
	}
	
	public static function call()
	{
		header("Content-Type: text/plain; chatset=utf-8");
		$current_ver = self::get_current_version();
		$max_ver = self::get_max_version();
		self::out("Current OKAPI database version: $current_ver\n");
		if ($max_ver == $current_ver)
		{
			self::out("It is up-to-date.");
			return;
		}
		elseif ($max_ver < $current_ver)
			throw new Exception();
		
		self::out("Updating to version $max_ver... PLEASE WAIT\n\n");
		
		while ($current_ver < $max_ver)
		{
			$version_to_apply = $current_ver + 1;
			self::out("Applying mutation #$version_to_apply...");
			try {
				call_user_func(array(__CLASS__, "ver".$version_to_apply));
				self::out(" OK!\n");
				Db::execute("
					replace into okapi_vars (var, value)
					values ('db_version', '".mysql_real_escape_string($version_to_apply)."');
				");
				$current_ver += 1;
			} catch (Exception $e) {
				self::out(" ERROR\n\n");
				throw $e;
			}
		}
		
		self::out("\nUpdated.");
	}

	private static function ver1()
	{
		Db::execute("
			CREATE TABLE okapi_vars (
				var varchar(32) charset ascii collate ascii_bin NOT NULL,
				value text,
				PRIMARY KEY  (var)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8;
		");
	}
	
	private static function ver2()
	{
		Db::execute("
			CREATE TABLE okapi_authorizations (
				consumer_key varchar(20) charset ascii collate ascii_bin NOT NULL,
				user_id int(11) NOT NULL,
				last_access_token datetime default NULL,
				PRIMARY KEY  (consumer_key,user_id)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8;
		");
	}
	
	private static function ver3()
	{
		Db::execute("
			CREATE TABLE okapi_consumers (
				`key` varchar(20) charset ascii collate ascii_bin NOT NULL,
				name varchar(100) collate utf8_general_ci NOT NULL,
				secret varchar(40) charset ascii collate ascii_bin NOT NULL,
				url varchar(250) collate utf8_general_ci default NULL,
				email varchar(70) collate utf8_general_ci default NULL,
				date_created datetime NOT NULL,
				PRIMARY KEY  (`key`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8;
		");
	}
	
	private static function ver4()
	{
		Db::execute("
			CREATE TABLE okapi_nonces (
				consumer_key varchar(20) charset ascii collate ascii_bin NOT NULL,
				`key` varchar(255) charset ascii collate ascii_bin NOT NULL,
				timestamp int(10) NOT NULL,
				PRIMARY KEY  (consumer_key, `key`, `timestamp`)
			) ENGINE=MEMORY DEFAULT CHARSET=utf8;
		");
	}
	
	private static function ver5()
	{
		Db::execute("
			CREATE TABLE okapi_tokens (
				`key` varchar(20) charset ascii collate ascii_bin NOT NULL,
				secret varchar(40) charset ascii collate ascii_bin NOT NULL,
				token_type enum('request','access') NOT NULL,
				timestamp int(10) NOT NULL,
				user_id int(10) default NULL,
				consumer_key varchar(20) charset ascii collate ascii_bin NOT NULL,
				verifier varchar(10) charset ascii collate ascii_bin default NULL,
				callback varchar(2083) character set utf8 collate utf8_general_ci default NULL,
				PRIMARY KEY  (`key`),
				KEY by_consumer (consumer_key)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8;
		");
	}
	
	private static function ver6()
	{
		Db::execute("update cache_logs set date_created = now() where date_created='0000-00-00' and user_id <> -1");
	}
}
