<?

namespace okapi;

class Locales
{
	public static $languages = array(
		'pl' => array('lang' => 'pl', 'locale' => 'pl_PL.utf8', 'name' => 'Polish'),
		'en' => array('lang' => 'en', 'locale' => 'POSIX',      'name' => 'English'),
	);
	
	private static function get_locale_for_language($lang)
	{
		if (isset(self::$languages[$lang]))
			return self::$languages[$lang]['locale'];
		return null;
	}
	
	public static function get_best_locale($langprefs)
	{
		foreach ($langprefs as $lang)
		{
			$locale = self::get_locale_for_language($lang);
			if ($locale != null)
				return $locale;
		}
		return self::$languages['en']['locale'];
	}
}
