<?

namespace okapi;

use Exception;

# DO NOT MODIFY THIS FILE. This file should always look like the original here:
# http://code.google.com/p/opencaching-api/source/browse/trunk/okapi/settings.php
#
# HOW TO MODIFY OKAPI SETTINGS: I you want a setting X to have a value of Y,
# add following lines to your OC's lib/settings.inc.php file:
#
#     $OKAPI_SETTINGS = array(
#         'X' => 'Y',
#         // ... other settings may come here ...
#     );
#
#     // E.g. $OKAPI_SETTINGS = array('OC_BRANCH', 'oc.de');
#
# This file provides documentation and DEFAULT values for those settings.
#
# Please note: These settings WILL mutate. Some of them might get deprecated,
# others might change their meaning and/or possible values.

final class Settings
{
	/** Default values for setting keys. */
	private static $DEFAULT_SETTINGS = array(
	
		/**
		 * Currently there are two mainstream branches of OpenCaching code.
		 * Which branch is you installation using?
		 * 
		 * Possible values: "oc.pl" or "oc.de". (As far as we know, oc.us and
		 * oc.org.uk use "oc.pl" branch, the rest uses "oc.de" branch.)
		 */
		'OC_BRANCH' => "oc.pl",
		
		/**
		 * Each OpenCaching site has a default language. I.e. the language in
		 * which all the names of caches are entered. What is the ISO 639-1 code
		 * of this language? Note: ISO 639-1 codes are always lowercase.
		 * 
		 * E.g. "pl", "en" or "de".
		 */
		'SITELANG' => "en",
		
		/**
		 * Locale to be used with gettext. For example "pl_PL.utf8".
		 */
		'LOCALE' => "POSIX",
		
		/**
		 * All OKAPI documentation pages should remain English-only, but some
		 * other pages (these viewable by regular users) should be translated
		 * to your local language. We try to catch up to all OKAPI instances and
		 * fill our default translation tables with all the languages of all
		 * OKAPI installations. But we also give you an option to use your own
		 * translation table if you want to. Use this variable to pass your
		 * own gettext initialization function/method.
		 */
		'GETTEXT_INIT' => array('\okapi\Settings', 'default_gettext_init'),
		
		/**
		 * By default, OKAPI uses "okapi_messages" domain file for translations.
		 * Use this variable when you want it to use your own domain.
		 */
		'GETTEXT_DOMAIN' => 'okapi_messages',
	);
	
	/** 
	 * Final values for settings keys (defaults + local overrides).
	 * (Loaded upon first access.)
	 */
	private static $SETTINGS = null;
	
	/**
	 * Initialize self::$SETTINGS.
	 */
	private static function load_settings()
	{
		# Check the settings.inc.php for overrides.
		
		self::$SETTINGS = self::$DEFAULT_SETTINGS;
		
		if (!isset($GLOBALS['OKAPI_SETTINGS']))
			return;

		foreach (self::$SETTINGS as $key => $_)
			if (isset($GLOBALS['OKAPI_SETTINGS'][$key]))
				self::$SETTINGS[$key] = $GLOBALS['OKAPI_SETTINGS'][$key];
	}
	
	/** 
	 * Get the value for the $key setting.
	 */
	public static function get($key)
	{
		if (self::$SETTINGS == null)
			self::load_settings();
		
		if (!isset(self::$SETTINGS[$key]))
			throw new Exception("Tried to access an invalid settings key: '$key'");
		
		return self::$SETTINGS[$key];
	}
	
	/**
	 * Bind "okapi_messages" with our local i18n database. Set proper locale
	 * based on settings.
	 */
	public static function default_gettext_init()
	{
		$locale = self::get("LOCALE");
		putenv("LC_ALL=$locale");
		setlocale(LC_ALL, $locale);
		setlocale(LC_NUMERIC, "POSIX"); # We don't one *this one* to get out of control.
		bindtextdomain("okapi_messages", $GLOBALS['rootpath'].'okapi/locale');
	}
}