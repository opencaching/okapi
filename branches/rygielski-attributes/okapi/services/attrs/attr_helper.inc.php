<?php

namespace okapi\services\attrs;

use Exception;
use ErrorException;
use okapi\Okapi;
use okapi\Settings;
use okapi\Cache;
use okapi\OkapiRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\OkapiServiceRunner;
use okapi\OkapiInternalRequest;
use okapi\OkapiLock;
use SimpleXMLElement;


class AttrHelper
{
	/** URL of the source attributes.xml. */
	//private static $SOURCE_URL = "http://opencaching-api.googlecode.com/svn/trunk/etc/attributes.xml";
	private static $SOURCE_URL = "http://opencaching-api.googlecode.com/svn/branches/rygielski-attributes/etc/attributes.xml";

	/**
	 * Increase this if you need the attributes.xml to be refreshed immediatelly
	 * after the code update. Usually you won't need that (the attributes are
	 * refreshed periodically by a cronjob), but it can be handy for testing
	 * recently commited changes to the attributes.xml file.
	 */
	private static $VERSION = 8;

	private static $attr_dict = null;
	private static $last_refreshed = null;

	/**
	 * Forces the download of the new attributes from Google Code.
	 */
	public static function refresh_now()
	{
		$lock = OkapiLock::get("downloader");
		if (!$lock->try_acquire())
		{
			# Other thread is already doing that. We will continue WITHOUT
			# refreshing. This may cause the current thread to be somewhat
			# "misinformed", but it is safer.

			return;
		}
		try
		{
			$opts = array(
				'http' => array(
					'method' => "GET",
					'timeout' => 5.0
				)
			);
			$context = stream_context_create($opts);
			$xml = file_get_contents(self::$SOURCE_URL, false, $context);
			self::refresh_from_string($xml);
			$lock->release();
		}
		catch (Exception $e)
		{
			# Failure. We can't update the cached attributes. Let's check when
			# the last successful download occured.

			self::init_from_cache(false);
			$lock->release();

			if (self::$last_refreshed < time() - 36 * 3600)
			{
				Okapi::mail_admins(
					"OKAPI is unable to refresh attributes",
					"OKAPI periodically refreshes all cache attributes from the list\n".
					"kept in global repository. OKAPI tried to contact the repository,\n".
					"but it failed. Your list of attributes might be stale.\n\n".
					"You should probably update OKAPI or contact OKAPI developers.\n\n".
					"Debug info:\n".print_r($e, true)
				);
			}

			if (self::$last_refreshed == 0)
			{
				# That's bad! We don't have ANY copy of the data AND we failed to
				# download it. We will want OKAPI to keep trying, but we'll want
				# it to wait a little between retries. We need to cache the fake,
				# empty data, temporarilly.

				$cache_key = "attrhelper/dict#".self::$VERSION;
				$cachedvalue = array(
					'attr_dict' => array(),
					'last_refreshed' => 0,
				);
				Cache::set($cache_key, $cachedvalue, 60);
			}

			return;
		}

		self::refresh_from_string($xml);
	}

	/**
	 * Refreshed all attributes from the given XML. Usually, this file is
	 * downloaded from Google Code (using refresh_now).
	 */
	public static function refresh_from_string($xml)
	{
		/* attribute.xml file defines attribute relationships between various OC
		 * installations. Each installation uses its own internal ID schema.
		 * We will temporarily assume that "oc.pl" codebranch uses OCPL's schema
		 * and "oc.de" codebranch - OCDE's. This is wrong for OCUS and OCORGUK
		 * nodes, which use "oc.pl" codebranch, but have a schema of their own
		 * WRTODO. */

		if (Settings::get('OC_BRANCH') == 'oc.pl')
			$my_schema = "OCPL";
		else
			$my_schema = "OCDE";

		$doc = simplexml_load_string($xml);
		$cachedvalue = array(
			'attr_dict' => array(),
			'last_refreshed' => time(),
		);
		$all_internal_ids = array();
		foreach ($doc->attr as $attrnode)
		{
			$attr = array(
				'id' => (string)$attrnode['okapi_attr_id'],
				'gs_equivs' => array(),
				'internal_ids' => array(),
				'primary_internal_id' => null,
				'names' => array(),
				'descriptions' => array(),
				'search_inc_captions' => array(),
				'search_exc_captions' => array(),
			);
			foreach ($attrnode->groundspeak as $gsnode)
			{
				$attr['gs_equivs'][] = array(
					'id' => (int)$gsnode['id'],
					'inc' => in_array((string)$gsnode['inc'], array("true", "1")) ? 1 : 0,
					'name' => (string)$gsnode['name']
				);
			}
			foreach ($attrnode->opencaching as $ocnode)
			{
				if ((string)$ocnode['schema'] == $my_schema)
				{
					$internal_id = (int)$ocnode['id'];
					if (isset($all_internal_ids[$internal_id]))
						throw new Exception("The internal ID".$internal_id." is assigned to multiple attributes. Primary and secondary attribute relations needs to be defined.");
						# If this exception is thrown, geocaches.primary_attr_ids implementation needs to be changed, too.
					$all_internal_ids[$internal_id] = true;
					if (!is_null($attr['primary_internal_id']))
						throw new Exception("There are multiple internal IDs for the ".$attr['id']."attribute. Primary and secondary attribute relations needs to be defined.");
					$attr['primary_internal_id'] = $internal_id;
					$attr['internal_ids'][] = $internal_id;
				}
			}
			foreach ($attrnode->lang as $langnode)
			{
				$lang = (string)$langnode['id'];
				foreach ($langnode->name as $namenode)
				{
					$attr['names'][$lang] = (string)$namenode;
				}
				foreach ($langnode->search as $searchnode)
				{
					foreach ($searchnode->inc as $captionnode)
						$attr['search_inc_captions'][$lang] = (string)$captionnode;
					foreach ($searchnode->exc as $captionnode)
						$attr['search_exc_captions'][$lang] = (string)$captionnode;
				}
				foreach ($langnode->desc as $descnode)
				{
					$xml = $descnode->asxml(); /* contains "<desc>" and "</desc>" */
					$innerxml = preg_replace("/(^[^>]+>)|(<[^<]+$)/us", "", $xml);
					$attr['descriptions'][$lang] = self::cleanup_string($innerxml);
				}
			}
			$cachedvalue['attr_dict'][$attr['id']] = $attr;
		}

		# Cache it for a month (just in case, usually it will be refreshed every day).

		$cache_key = "attrhelper/dict#".self::$VERSION;
		Cache::set($cache_key, $cachedvalue, 30*86400);
		self::$attr_dict = $cachedvalue['attr_dict'];
		self::$last_refreshed = $cachedvalue['last_refreshed'];
	}

	/**
	 * Initialize all the internal attributes (if not yet initialized). This
	 * loads attribute values from the cache. If they are not present in the
	 * cache (very rare), it will acquire a lock and download them from Google
	 * Code.
	 */
	private static function init_from_cache($allow_download=true)
	{
		if (self::$attr_dict !== null)
		{
			/* Already initialized. */
			return;
		}
		$cache_key = "attrhelper/dict#".self::$VERSION;
		$cachedvalue = Cache::get($cache_key);
		if ($cachedvalue === null)
		{
			# This may happen, for example, when the self::$VERSION is changed.

			if ($allow_download)
			{
				self::refresh_now();
				self::init_from_cache(false);
				return;
			}
			else
			{
				$cachedvalue = array(
					'attr_dict' => array(),
					'last_refreshed' => 0,
				);
			}
		}
		self::$attr_dict = $cachedvalue['attr_dict'];
		self::$last_refreshed = $cachedvalue['last_refreshed'];
	}

	/**
	 * Return a dictionary of all attributes. The format is the same as in the "attributes"
	 * key returned by the "services/attrs/attrlist" method.
	 */
	public static function get_attrdict()
	{
		self::init_from_cache();
		return self::$attr_dict;
	}

	/** "\n\t\tBla   blabla\n\t\t<b>bla</b>bla.\n\t" => "Bla blabla <b>bla</b>bla." */
	private static function cleanup_string($s)
	{
		return preg_replace('/(^\s+)|(\s+$)/us', "", preg_replace('/\s+/us', " ", $s));
	}

	/**
	 * Get the mapping between internal attribute id => the list of OKAPI A-codes
	 * to which the internal ID is mapped to. The result is cached!
	 */
	public static function get_internal_id_to_acodes_mapping()
	{
		static $mapping = null;
		if ($mapping !== null)
			return $mapping;

		$cache_key = "attrhelper/id2acodes/".self::$VERSION;
		$mapping = Cache::get($cache_key);
		if (!$mapping)
		{
			self::init_from_cache();
			$mapping = array();
			foreach (self::$attr_dict as $acode => &$attr_ref)
			{
				foreach ($attr_ref['internal_ids'] as $internal_id)
					$mapping[$internal_id][] = $acode;
			}
			Cache::set($cache_key, $mapping, 3600);
		}
		return $mapping;
	}

	/**
	 * Get the mapping: acode => the list of internal attribute IDs to which
	 * the acode is mapped to. The result is cached!
	 */
	public static function get_acode_to_internal_ids_mapping()
	{
		static $mapping = null;
		if ($mapping !== null)
			return $mapping;

		$cache_key = "attrhelper/acode2ids/".self::$VERSION;
		$mapping = Cache::get($cache_key);
		if (!$mapping)
		{
			self::init_from_cache();
			$mapping = array();
			foreach (self::$attr_dict as $acode => &$attr_ref)
			{
				$mapping[$acode] = array();
				foreach ($attr_ref['internal_ids'] as $internal_id)
					$mapping[$acode][] = $internal_id;
			}
			Cache::set($cache_key, $mapping, 3600);
		}
		return $mapping;
	}


	/**
	 * Get the mapping: A-codes => attribute name. The language for the name
	 * is selected based on the $langpref parameter. The result is cached!
	 */
	public static function get_acode_to_name_mapping($langpref)
	{
		static $mapping = null;
		if ($mapping !== null)
			return $mapping;

		$cache_key = md5(serialize(array("attrhelper/acode2name", $langpref, self::$VERSION)));
		$mapping = Cache::get($cache_key);
		if (!$mapping)
		{
			self::init_from_cache();
			$mapping = array();
			foreach (self::$attr_dict as $acode => &$attr_ref)
			{
				$mapping[$acode] = Okapi::pick_best_language($attr_ref['names'], $langpref);
			}
			Cache::set($cache_key, $mapping, 3600);
		}
		return $mapping;
	}

}
