<?php

namespace okapi;

use Exception;

require_once 'service_runner.php';

class OkapiDocViewer
{
	private static function link($current_path, $link_path, $link_name)
	{
		return "<a href='/okapi/$link_path'".(($current_path == $link_path)
			? " class='selected'" : "").">$link_name</a><br>";
	}
	
	/** Get HTML-formatted side menu representation. */
	public static function get_menu_html($current_path = null)
	{
		$chunks = array();
		if (Okapi::$revision)
			$chunks[] = "<div class='revision'>rev. ".Okapi::$revision."</div>";
		$chunks[] = "<div class='main'>";
		$chunks[] = self::link($current_path, "introduction.html", "Introduction");
		$chunks[] = self::link($current_path, "signup.html", "Sign up");
		$chunks[] = self::link($current_path, "examples.html", "Examples");
		$chunks[] = "</div>";
		
		# We need a list of all methods. We do not need their descriptions, so
		# we won't use the apiref/method_index method to get it, the static list
		# within OkapiServiceRunner will do.
		
		$methodnames = OkapiServiceRunner::$all_names;
		sort($methodnames);
		
		# We'll break them up into modules, for readability.
		
		$module_methods = array();
		foreach ($methodnames as $methodname)
		{
			$pos = strrpos($methodname, "/");
			$modulename = substr($methodname, 0, $pos);
			$method_short_name = substr($methodname, $pos + 1);
			if (!isset($module_methods[$modulename]))
				$module_methods[$modulename] = array();
			$module_methods[$modulename][] = $method_short_name;
		}
		$modulenames = array_keys($module_methods);
		sort($modulenames);
		
		foreach ($modulenames as $modulename)
		{
			$chunks[] = "<div class='module'>$modulename</div>";
			$chunks[] = "<div class='methods'>";
			foreach ($module_methods[$modulename] as $method_short_name)
				$chunks[] = self::link($current_path, "$modulename/$method_short_name.html", "$method_short_name");
			$chunks[] = "</div>";
		}
		return implode("", $chunks);
	}
	
	/**
	 * Checks if given path is a valid document path. (For URL
	 * "http://.../okapi/xyz", doc path is "xyz".)
	 */
	public static function is_valid_doc($path)
	{
		if (in_array($path, array("", "introduction.html", "signup.html", "examples.html")))
			return true;
		if ((substr($path, 0, 9) == "services/") && (substr($path, -1) == "/"))
		{
			# Not a valid path, but probably user just stripped the last part
			# to get to the other part of the documentation. We'll redirect him.
			return true;
		}
		foreach (OkapiServiceRunner::$all_names as $methodname)
			if ($path == "$methodname.html")
				return true;
		return false;
	}
	
	public static function display_doc($path)
	{
		if ($path == "")
		{
			# Redirect to the introduction page.
			header("HTTP/1.1 303 See Other");
			header("Location: ".$GLOBALS['absolute_server_URI']."okapi/introduction.html");
		}
		elseif (in_array($path, array("introduction.html", "signup.html", "examples.html")))
		{
			self::display_static_doc($path);
		}
		elseif ((substr($path, 0, 9) == "services/") && (substr($path, -1) == "/"))
		{
			# Not a valid path, but probably user just stripped the last part
			# to get to the other part of the documentation. We'll redirect him.
			header("HTTP/1.1 303 See Other");
			header("Location: ".$GLOBALS['absolute_server_URI']."okapi/introduction.html");
		}
		else
		{
			$methodname = substr($path, 0, strlen($path) - 5);
			self::display_method_doc($methodname);
		}
	}
	
	public static function display_static_doc($path)
	{
		$vars = array(
			'menu' => self::get_menu_html($path),
			'okapi_base_url' => $GLOBALS['absolute_server_URI']."okapi/",
		);
		if ($path == 'introduction.html')
		{
			$vars['site_url'] = $GLOBALS['absolute_server_URI'];
			$vars['method_index'] = OkapiServiceRunner::call('services/apiref/method_index',
				new OkapiInternalRequest(null, null, array()));
		}
		elseif ($path == 'signup.html')
		{
			$vars['site_url'] = $GLOBALS['absolute_server_URI'];
			$vars['site_url'] = "http://opencaching.pl/";
			$matches = null;
			if (preg_match("#^https?://(www.)?opencaching.([a-z.]+)/$#", $vars['site_url'], $matches)) {
				$vars['site_name'] = "OpenCaching.".strtoupper($matches[2]);
			} else {
				$vars['site_name'] = $vars['site_url'];
			}
			if (isset($_REQUEST['posted']))
			{
				# Well... not so static after all. This should be moved out of
				# the "display_static_doc" method. Perhaps, one day...
				
				$appname = isset($_REQUEST['appname']) ? $_REQUEST['appname'] : "";
				$appname = trim($appname);
				$appurl = isset($_REQUEST['appurl']) ? $_REQUEST['appurl'] : "";
				$email = isset($_REQUEST['email']) ? $_REQUEST['email'] : "";
				$accepted_terms = isset($_REQUEST['terms']) ? $_REQUEST['terms'] : "";
				$ok = false;
				if (!$appname)
					$notice = "Please provide a valid application name.";
				elseif (mb_strlen($appname) > 100)
					$notice = "Application name should be less than 100 characters long.";
				elseif (mb_strlen($appurl) > 250)
					$notice = "Application URL should be less than 250 characters long.";
				elseif (!$email)
					$notice = "Please provide a valid email address.";
				elseif (mb_strlen($email) > 70)
					$notice = "Email address should be less than 70 characters long.";
				elseif (!$accepted_terms)
					$notice = "You have to read and accept OKAPI Terms of Use.";
				else
				{
					$ok = true;
					Okapi::register_new_consumer($appname, $appurl, $email);
					$notice = "Consumer Key generated successfully.\\nCheck your email!";
				}
				print '{"ok": '.($ok ? "true" : "false").', "notice": "'.$notice.'"}';
				return;
			}
		}
		$path = substr($path, 0, strlen($path) - 5); # strip off ".html"
		include "templates/$path.tpl.php";
	}
	
	public static function display_method_doc($methodname)
	{
		require_once 'service_runner.php';
		try
		{
			$method = OkapiServiceRunner::call('services/apiref/method', new OkapiInternalRequest(
				null, null, array('name' => $methodname)));
		}
		catch (BadRequest $e)
		{
			throw new Exception("Called display_method_doc with invalid method name: $methodname");
		}
		$vars = array(
			'method' => $method,
			'menu' => self::get_menu_html($methodname.".html"),
			'okapi_base_url' => $GLOBALS['absolute_server_URI']."okapi/",
		);
		include 'templates/method.tpl.php';
	}
	
	public static function display_404()
	{
		$vars = array(
			'menu' => self::get_menu_html(),
		);
		include "templates/404.tpl.php";
	}
}
