<?

namespace okapi;

class Locales
{
	private static function get_locale_for_language($lang)
	{
		if ($lang == 'pl') return 'pl_PL.utf8';
		if ($lang == 'en') return 'en_EN.utf8'; // will fall back to the default translation
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
		return 'POSIX';
	}
}
