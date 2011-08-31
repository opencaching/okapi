<?php

namespace okapi;

# OKAPI Framework -- Wojciech Rygielski <rygielski@mimuw.edu.pl>

# Including this file will initialize OKAPI Framework with its default
# exception and error handlers. OKAPI is strict about PHP warnings and
# notices. You might need to temporarily disable the error handler in
# order to get it to work in some legacy code. Do this by calling
# OkapiErrorHandler::disable() BEFORE calling the "buggy" code, and
# OkapiErrorHandler::reenable() AFTER returning from it.

# When I was installing it for the first time on the opencaching.pl
# site, I needed to change some minor things in order to get it to work
# properly, i.e., turn some "die()"-like statements into exceptions.
# Contact me for details.

# I hope this will come in handy...  - WR.

#
# First - base exception types.
#

use Exception;
use ErrorException;
use OAuthException;
use OAuth400Exception;
use OAuth401Exception;
use OAuthConsumer;
use OAuthToken;
use OAuthServer;
use OAuthSignatureMethod_HMAC_SHA1;
use OAuthRequest;

/** Throw this when external developer does something wrong. */
class BadRequest extends Exception {}

#
# We'll try to make PHP into something more decent. Exception and
# error handling.
#

/** Container for exception-handling functions. */
class OkapiExceptionHandler
{
	/** Handle exception thrown while executing OKAPI request. */
	public static function handle($e)
	{
		if ($e instanceof OAuth400Exception)
		{
			# This is thrown on improperly constructed OAuth requests.
			header("HTTP/1.0 400 Bad Request");
			header("Access-Control-Allow-Origin: *");
			header("Content-Type: text/plain; charset=utf-8");
			
			print $e->getMessage();
		}
		elseif ($e instanceof OAuth401Exception)
		{
			# This is thrown on improperly signed OAuth requests or when
			# invalid/expired Tokens or Consumer Keys are used. See also:
			# http://oauth.net/core/1.0a/#http_codes
			
			header("HTTP/1.0 401 Unauthorized");
			header("Access-Control-Allow-Origin: *");
			header("Content-Type: text/plain; charset=utf-8");
			
			print $e->getMessage();
		}
		elseif ($e instanceof BadRequest)
		{
			# Intentionally thrown from within the OKAPI method code.
			# Consumer (aka external developer) had something wrong with his
			# request and we want him to know that.
			
			header("HTTP/1.0 400 Bad Request");
			header("Access-Control-Allow-Origin: *");
			header("Content-Type: text/plain; charset=utf-8");
			
			print $e->getMessage();
		}
		else # (ErrorException, MySQL exception etc.)
		{
			# This one is thrown on PHP notices and warnings - usually this
			# indicates an error in OKAPI method. If thrown, then something
			# must be fixed on OUR part.
			
			if (!headers_sent())
			{
				header("HTTP/1.0 500 Internal Server Error");
				header("Access-Control-Allow-Origin: *");
				header("Content-Type: text/plain; charset=utf-8");
			}
			
			print "Oops... Something went wrong on *our* part.\n\n";
			print "Message was passed on to the site administrators. We'll try to fix it.\n";
			print "Contact the developers if you think you can help!";
			
			error_log($e->getMessage());
			
			$exception_info = "*** ".$e->getMessage()." ***\n\n--- Stack trace ---\n".$e->getTraceAsString().
				(isset($_SERVER['REQUEST_URI']) ? "\n\n--- OKAPI method called ---\n".preg_replace("/([?&])/", "\n$1", $_SERVER['REQUEST_URI']) : "").
				"\n\n--- Request headers ---\n".implode("\n", array_map(
					function($k, $v) { return "$k: $v"; },
					array_keys(getallheaders()), array_values(getallheaders())
				));
			
			if (isset($GLOBALS['debug_page']) && $GLOBALS['debug_page'])
			{
				print "\n\n".$exception_info;
			}
			$admin_email = isset($GLOBALS['sql_errormail']) ? $GLOBALS['sql_errormail'] : 'root@localhost';
			$sender_email = isset($GLOBALS['emailaddr']) ? $GLOBALS['emailaddr'] : 'root@localhost';
			mail(
				$admin_email,
				"OKAPI Method Error - ".(
					isset($GLOBALS['absolute_server_URI'])
					? $GLOBALS['absolute_server_URI'] : "unknown location"
				),
				"OKAPI caught the following exception while executing API method request.\n".
				"This is an error in OUR code and should be fixed. Please contact the\n".
				"developer of the module that threw this error. Thanks!\n\n".
				$exception_info,
				"Content-Type: text/plain; charset=utf-8\n".
				"From: OKAPI <$sender_email>\n".
				"Reply-To: $sender_email\n"
				);
		}
	}
}

/** Container for error-handling functions. */
class OkapiErrorHandler
{
	public static $treat_notices_as_errors = false;
	
	/** Handle error encountered while executing OKAPI request. */
	public static function handle($severity, $message, $filename, $lineno)
	{
		if ($severity == E_STRICT) return false;
		if (($severity == E_NOTICE || $severity == E_DEPRECATED) &&
			!self::$treat_notices_as_errors)
		{
			return false;
		}
		throw new ErrorException($message, 0, $severity, $filename, $lineno);
	}
	
	/** Use this BEFORE calling a piece of buggy code. */
	public static function disable()
	{
		restore_error_handler();
	}
	
	/** Use this AFTER calling a piece of buggy code. */
	public static function reenable()
	{
		set_error_handler(array('\okapi\OkapiErrorHandler', 'handle'));
	}
}

# Settings handlers. Errors will now throw exceptions, and all exceptions
# will be properly handled. (Unfortunetelly, only SOME errors can be caught
# this way, PHP limitations...)

set_exception_handler(array('\okapi\OkapiExceptionHandler', 'handle'));
set_error_handler(array('\okapi\OkapiErrorHandler', 'handle'));

#
# Extending exception types (introducing some convenient shortcuts for
# the developer).
#

class Http404 extends BadRequest {}

/** Common type of BadRequest: Required parameter is missing. */
class ParamMissing extends BadRequest
{
	public function __construct($paramName, $code = 0)
	{
		parent::__construct("Required parameter '$paramName' is missing.", $code);
	}
}

/** Common type of BadRequest: Parameter has invalid value. */
class InvalidParam extends BadRequest
{
	/** What was wrong about the param? */
	public $whats_wrong_about_it;
	
	public function __construct($paramName, $whats_wrong_about_it = "", $code = 0)
	{
		$this->whats_wrong_about_it = $whats_wrong_about_it;
		if ($whats_wrong_about_it)
			parent::__construct("Parameter '$paramName' has invalid value: ".$whats_wrong_about_it, $code);
		else
			parent::__construct("Parameter '$paramName' has invalid value.", $code);
	}
}

/** Thrown on invalid SQL queries. */
class DbException extends Exception {}

#
# Database access layer.
#

/** Database access class. Use this instead of mysql_query, sql or sqlValue. */
class Db
{
	public static function select_row($query)
	{
		$rows = self::select_all($query);
		switch (count($rows))
		{
			case 0: return null;
			case 1: return $rows[0];
			default:
				throw new DbException("Invalid query. Db::select_row returned more than one row for:\n\n".$query."\n");
		}
	}

	public static function select_all($query)
	{
		$rows = array();
		self::select_and_push($query, $rows);
		return $rows;
	}
	
	public static function select_and_push($query, & $arr, $keyField = null)
	{
		$rs = self::query($query);
		while (true)
		{
			$row = mysql_fetch_assoc($rs);
			if ($row === false)
				break;
			if ($keyField == null)
				$arr[] = $row;
			else
				$arr[$row[$keyField]] = $row;
		}
		mysql_free_result($rs);
	}

	
	public static function select_value($query)
	{
		$column = self::select_column($query);
		if ($column == null)
			return null;
		if (count($column) == 1)
			return $column[0];
		throw new DbException("Invalid query. Db::select_value returned more than one row for:\n\n".$query."\n");
	}
	
	public static function select_column($query)
	{
		$column = array();
		$rs = self::query($query);
		while (true)
		{
			$values = mysql_fetch_array($rs);
			if ($values === false)
				break;
			array_push($column, $values[0]);
		}
		mysql_free_result($rs);
		return $column;
	}
	
	public static function last_insert_id()
	{
		return mysql_insert_id();
	}

	public static function execute($query)
	{
		$rs = self::query($query);
		if ($rs !== true)
			throw new DbException("Db::execute returned a result set for your query. ".
				"You should use Db::select_* or Db::query for SELECT queries!");
	}
	
	public static function query($query)
	{
		$rs = mysql_query($query);
		if (!$rs)
		{
			throw new DbException("SQL Error ".mysql_errno().": ".mysql_error()."\n\nThe query was:\n".$query."\n");
		}
		return $rs;
	}
}

#
# Including OAuth internals. Preparing OKAPI Consumer and Token classes.
#

require_once('oauth.php');

class OkapiConsumer extends OAuthConsumer
{
	public $name;
	public $url;
	public $email;
	
	public function __construct($key, $secret, $name, $url, $email)
	{
		$this->key = $key;
		$this->secret = $secret;
		$this->name = $name;
		$this->url = $url;
		$this->email = $email;
	}
	
	public function __toString()
	{
		return "OkapiConsumer[key=$this->key,name=$this->name]";
	}
}

class OkapiToken extends OAuthToken
{
	public $consumer_key;
	public $token_type;
	
	public function __construct($key, $secret, $consumer_key, $token_type)
	{
		parent::__construct($key, $secret);
		$this->consumer_key = $consumer_key;
		$this->token_type = $token_type;
	}
}
class OkapiRequestToken extends OkapiToken
{
	public $callback_url;
	public $authorized_by_user_id;
	public $verifier;
	
	public function __construct($key, $secret, $consumer_key, $callback_url,
		$authorized_by_user_id, $verifier)
	{
		parent::__construct($key, $secret, $consumer_key, 'request');
		$this->callback_url = $callback_url;
		$this->authorized_by_user_id = $authorized_by_user_id;
		$this->verifier = $verifier;
	}
}

class OkapiAccessToken extends OkapiToken
{
	public $user_id;
	
	public function __construct($key, $secret, $consumer_key, $user_id)
	{
		parent::__construct($key, $secret, $consumer_key, 'access');
		$this->user_id = $user_id;
	}
}

/** Default OAuthServer with some OKAPI-specific methods added. */
class OkapiOAuthServer extends OAuthServer
{
	public function __construct($data_store)
	{
		parent::__construct($data_store);
		# We want HMAC_SHA1 authorization method only.
		$this->add_signature_method(new OAuthSignatureMethod_HMAC_SHA1());
	}
	
	/**
	 * By default, works like verify_request, but it does support some additional
	 * options. If $token_required == false, it doesn't throw an exception when
	 * there is no token specified. You may also change the token_type required
	 * for this request.
	 */
	public function verify_request2(&$request, $token_type = 'access', $token_required = true)
	{
		$this->get_version($request);
		$consumer = $this->get_consumer($request);
		try {
			$token = $this->get_token($request, $consumer, $token_type);
		} catch (OAuthException $e) {
			if ($token_required)
				throw $e;
			else
				$token = null;
		}
		$this->check_signature($request, $consumer, $token);
		return array($consumer, $token);
	}
}

# Including local datastore and settings (connecting SQL database etc.).

require_once('settings.php');
require_once('datastore.php');

class OkapiHttpResponse
{
	public $content_type = "text/plain; charset=utf-8";
	public $content_disposition = null;
	public $body;
	
	public function display()
	{
		header("HTTP/1.1 200 OK");
		header("Access-Control-Allow-Origin: *");
		header("Content-Type: ".$this->content_type);
		if ($this->content_disposition)
			header("Content-Disposition: ".$this->content_disposition);
		print $this->body;
	}
}

class OkapiRedirectResponse extends OkapiHttpResponse
{
	public $url;
	public function __construct($url) { $this->url = $url; }
	public function display()
	{
		header("HTTP/1.1 303 See Other");
		header("Content-Type: ".$this->content_type);
		header("Location: ".$this->url);
	}
}

class Okapi404Response extends OkapiHttpResponse
{
	public function display()
	{
		header("HTTP/1.1 404 Not Found");
		header("Content-Type: ".$this->content_type);
		print $this->body;
	}
}

/** Container for various OKAPI functions. */
class Okapi
{
	public static $data_store;
	public static $server;
	public static $revision = null; # This gets replaced in automatically deployed packages
	
	/** Returns something like "OpenCaching.PL" or "OpenCaching.DE". */
	public static function get_normalized_site_name($site_url = null)
	{
		if ($site_url == null)
			$site_url = $GLOBALS['absolute_server_URI'];
		$matches = null;
		if (preg_match("#^https?://(www.)?opencaching.([a-z.]+)/$#", $site_url, $matches)) {
			return "OpenCaching.".strtoupper($matches[2]);
		} else {
			return "DEVELSITE";
		}
	}
	
	/**
	 * Pick text from $langdict based on language preference $langpref.
	 *
	 * Example:
	 * pick_best_language(
	 *   array('pl' => 'X', 'de' => 'Y', 'en' => 'Z'),
	 *   array('sp', 'de', 'en')
	 * ) == 'Y'.
	 *
	 * @param array $langdict - assoc array of lang-code => text.
	 * @param array $langprefs - list of lang codes, in order of preference.
	 */
	public static function pick_best_language($langdict, $langprefs)
	{
		foreach ($langprefs as $pref)
			if (isset($langdict[$pref]))
				return $langdict[$pref];
		foreach ($langdict as &$text_ref)
			return $text_ref;
		return "";
	}
	
	/** Internal. */
	public static function init_server()
	{
		if (!self::$data_store)
			self::$data_store = new OkapiDataStore();
		if (!self::$server)
			self::$server = new OkapiOAuthServer(self::$data_store);
	}
	
	/**
	 * Generate a string of random characters, suitable for keys as passwords.
	 * Troublesome characters like '0', 'O', '1', 'l' will not be used.
	 * If $user_friendly=true, then it will consist from numbers only.
	 */
	public static function generate_key($length, $user_friendly = false)
	{
		if ($user_friendly)
			$chars = "0123456789";
		else
			$chars = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789";
		$max = strlen($chars);
		$key = "";
		for ($i=0; $i<$length; $i++)
		{
			$key .= $chars[rand(0, $max-1)];
		}
		return $key;
	}
	
	/**
	 * Register new OKAPI Consumer, send him an email with his key-pair, etc.
	 * This method does not verify parameter values, check if they are in
	 * a correct format prior the execution.
	 */
	public static function register_new_consumer($appname, $appurl, $email)
	{
		include_once 'service_runner.php';
		$consumer = new OkapiConsumer(Okapi::generate_key(20), Okapi::generate_key(40),
			$appname, $appurl, $email);
		$sample_cache = OkapiServiceRunner::call("services/caches/search/all",
			new OkapiInternalRequest($consumer, null, array('limit', 1)));
		if (count($sample_cache['results']) > 0)
			$sample_cache_code = $sample_cache['results'][0];
		else
			$sample_cache_code = "CACHECODE";
		$sender_email = isset($GLOBALS['emailaddr']) ? $GLOBALS['emailaddr'] : 'root@localhost';
		mail($email, "Your OKAPI Consumer Key",
			"This is the key-pair we've generated for your application:\n\n".
			"Consumer Key: $consumer->key\n".
			"Consumer Secret: $consumer->secret\n\n".
			"Note: Consumer Secret is needed only when you intend to use OAuth.\n".
			"You don't need Consumer Secret for Level 1 Authentication.\n\n".
			"Now you may easily access Level 1 methods of OKAPI! For example:\n".
			$GLOBALS['absolute_server_URI']."okapi/services/caches/geocache?cache_code=$sample_cache_code&consumer_key=$consumer->key\n\n".
			"Have fun!",
			"Content-Type: text/plain; charset=utf-8\n".
			"From: OKAPI <$sender_email>\n".
			"Reply-To: $sender_email\n"
		);
		if (isset($GLOBALS['sql_errormail']))
		{
			mail($GLOBALS['sql_errormail'], "New OKAPI app registered!",
				"Name: $consumer->name\n".
				"Developer: $consumer->email\n".
				($consumer->url ? "URL: $consumer->url\n" : "").
				"Consumer Key: $consumer->key\n",
				"Content-Type: text/plain; charset=utf-8\n".
				"From: OKAPI <$sender_email>\n".
				"Reply-To: $sender_email\n"
			);
		}
		Db::execute("
			insert into okapi_consumers (`key`, name, secret, url, email, date_created)
			values (
				'".mysql_real_escape_string($consumer->key)."',
				'".mysql_real_escape_string($consumer->name)."',
				'".mysql_real_escape_string($consumer->secret)."',
				'".mysql_real_escape_string($consumer->url)."',
				'".mysql_real_escape_string($consumer->email)."',
				now()
			);
		");
	}
	
	/**
	 * Print out the standard OKAPI response. The $object will be printed
	 * using one of the default formatters (JSON, JSONP, XML, etc.). Formatter is
	 * auto-detected by peeking on the $request 'format' parameter.
	 */
	public static function formatted_response(OkapiRequest $request, $object)
	{
		if ($request instanceof OkapiInternalRequest && ($request->i_want_okapi_response == false))
		{
			# If you call a method internally, then you probably expect to get
			# the actual object instead of it's formatted representation.
			return $object;
		}
		$format = $request->get_parameter('format');
		if ($format == null) $format = 'json';
		if (!in_array($format, array('json', 'jsonp')))
			throw new InvalidParam('format', "'$format'");
		$callback = $request->get_parameter('callback');
		if ($callback && $format != 'jsonp')
			throw new BadRequest("The 'callback' parameter is reserved to be used with the JSONP output format.");
		if ($format == 'json')
		{
			$response = new OkapiHttpResponse();
			$response->content_type = "application/json; charset=utf-8";
			$response->body = json_encode($object);
			return $response;
		}
		elseif ($format == 'jsonp')
		{
			if (!$callback)
				throw new BadRequest("'callback' parameter is required for JSONP calls");
			if (!preg_match("/^[a-zA-Z_][a-zA-Z0-9_]*$/", $callback))
				throw new InvalidParam('callback', "'$callback' doesn't seem to be a valid JavaScript function name (should match /^[a-zA-Z_][a-zA-Z0-9_]*\$/).");
			$response = new OkapiHttpResponse();
			$response->content_type = "application/javascript; charset=utf-8";
			$response->body = $callback."(".json_encode($object).");";
			return $response;
		}
	}
	
	private static $cache_types = array(
		# Primary types
		'Traditional' => 2, 'Multi' => 3, 'Quiz' => 7, 'Virtual' => 4,
		# Additional types - these should include ALL types used in
		# ANY of the opencaching installations. Contact me if you want to modify this.
		'Event' => 6, 'Webcam' => 5, 'Moving' => 8, 'Own' => 9, 'Other' => 1,
	);
	
	private static $cache_statuses = array(
		'Available' => 1, 'Temporarily unavailable' => 2, 'Archived' => 3
	);
	
	/** E.g. 'Traditional' => 2. For unknown names throw an Exception. */
	public static function cache_type_name2id($name)
	{
		if (isset(self::$cache_types[$name]))
			return self::$cache_types[$name];
		throw new Exception("Method cache_type_name2id called with unsupported cache ".
			"type name '$name'. You should not allow users to submit caches ".
			"of non-primary type.");
	}
	
	/** E.g. 2 => 'Traditional'. For unknown ids returns "Other". */
	public static function cache_type_id2name($id)
	{
		static $reversed = null;
		if ($reversed == null)
		{
			$reversed = array();
			foreach (self::$cache_types as $key => $value)
				$reversed[$value] = $key;
		}
		if (isset($reversed[$id]))
			return $reversed[$id];
		return "Other";
	}
	
	/** E.g. 'Available' => 1. For unknown names throws an Exception. */
	public static function cache_status_name2id($name)
	{
		if (isset(self::$cache_statuses[$name]))
			return self::$cache_statuses[$name];
		throw new Exception("Method cache_status_name2id called with invalid name '$name'.");
	}
	
	/** E.g. 1 => 'Available'. For unknown ids returns 'Archived'. */
	public static function cache_status_id2name($id)
	{
		static $reversed = null;
		if ($reversed == null)
		{
			$reversed = array();
			foreach (self::$cache_statuses as $key => $value)
				$reversed[$value] = $key;
		}
		if (isset($reversed[$id]))
			return $reversed[$id];
		return 'Archived';
	}
	
	/** E.g. 'Found it' => 1. For unknown logtypes throws Exception. */
	public static function logtypename2id($name)
	{
		if ($name == 'Found it') return 1;
		if ($name == "Didn't find it") return 2;
		if ($name == 'Comment') return 3;
		throw new Exception("logtype2id called with invalid log type argument: $name");
	}
	
	/** E.g. 1 => 'Found it'. For unknown ids returns 'Comment'. */
	public static function logtypeid2name($id)
	{
		if ($id == 1) return 'Found it';
		if ($id == 2) return "Didn't find it";
		return 'Comment';
	}
}


/**
 * Represents an OKAPI web method request.
 *
 * Use this class to get parameters from your request and access
 * Consumer and Token objects. Please note, that request method
 * SHOULD be irrelevant to you: GETs and POSTs are interchangable
 * within OKAPI, and it's up to the caller which one to choose.
 * If you think using GET is "unsafe", then probably you forgot to
 * add OAuth signature requirement (consumer=required) - this way,
 * all the "unsafety" issues of using GET vanish.
 */
abstract class OkapiRequest
{
	public $consumer;
	public $token;
	
	/**
	 * Return request parameter, or NULL when not found. Use this instead of
	 * $_GET or $_POST or $_REQUEST.
	 */
	public abstract function get_parameter($name);
}

class OkapiInternalRequest extends OkapiRequest
{
	private $parameters;
	
	/**
	 * Set this to true, if you want to receive OkapiResponse instead of
	 * the actual object.
	 */
	public $i_want_okapi_response = false;
	
	public function __construct($consumer, $token, $parameters)
	{
		$this->consumer = $consumer;
		$this->token = $token;
		$this->parameters = $parameters;
	}
	
	public function get_parameter($name)
	{
		if (isset($this->parameters[$name]))
			return $this->parameters[$name];
		else
			return null;
	}
}

class OkapiHttpRequest extends OkapiRequest
{
	private $request; /* @var OAuthRequest */
	private $opt_min_auth_level; # 0..3
	private $opt_token_type = 'access'; # "access" or "request"
	
	public function __construct($options)
	{
		Okapi::init_server();
		$this->init_request();
		#
		# Parsing options.
		#
		foreach ($options as $key => $value)
		{
			switch ($key)
			{
				case 'min_auth_level':
					if (!in_array($value, array(0, 1, 2, 3)))
					{
						throw new Exception("'min_auth_level' option has invalid value: $value");
					}
					$this->opt_min_auth_level = $value;
					break;
				case 'token_type':
					if (!in_array($value, array("request", "access")))
					{
						throw new Exception("'token_type' option has invalid value: $value");
					}
					$this->opt_token_type = $value;
					break;
				default:
					throw new Exception("Unknown option: $key");
					break;
			}
		}
		if ($this->opt_min_auth_level === null) throw new Exception("Required 'min_auth_level' option is missing.");
		
		#
		# Let's see if the request is signed. If it is, verify the signature.
		# It it's not, check if it isn't against the rules defined in the $options.
		#
		
		if ($this->get_parameter('oauth_signature'))
		{
			list($this->consumer, $this->token) = Okapi::$server->
				verify_request2($this->request, $this->opt_token_type, $this->opt_min_auth_level == 3);
			if ($this->get_parameter('consumer_key') && $this->get_parameter('consumer_key') != $this->get_parameter('oauth_consumer_key'))
				throw new BadRequest("Inproper mixing of authentication types. You used both 'consumer_key' ".
					"and 'oauth_consumer_key' parameters (Level 1 and Level 2), but they do not match with ".
					"each other. Were you trying to hack me? ;)");
			if ($this->opt_min_auth_level == 3 && !$this->token)
			{
				throw new BadRequest("This method requires a valid Token to be included (Level 3 ".
					"Authentication). You didn't provide one.");
			}
		}
		else
		{
			if ($this->opt_min_auth_level >= 2)
			{
				throw new BadRequest("This method requires OAuth signature (Level ".
					$this->opt_min_auth_level." Authentication). You didn't sign your request.");
			}
			else
			{
				$consumer_key = $this->get_parameter('consumer_key');
				if ($consumer_key)
				{
					$this->consumer = Okapi::$data_store->lookup_consumer($consumer_key);
					if (!$this->consumer)
						throw new InvalidParam('consumer_key', "Consumer does not exist.");
				}
				if (($this->opt_min_auth_level == 1) && (!$this->consumer))
					throw new BadRequest("This method requires the 'consumer_key' argument (Level 1 ".
						"Authentication). You didn't provide one.");
			}
		}
		
		#
		# Prevent developers from accessing request parameters with PHP globals.
		# Remember, that OKAPI requests can be nested within other OKAPI requests!
		# Search the code for "new OkapiInternalRequest" to see examples.
		#
		
		$_GET = $_POST = $_REQUEST = null;
	}
	
	private function init_request()
	{
		$this->request = OAuthRequest::from_request();
		if (!in_array($this->request->get_normalized_http_method(),
			array('GET', 'POST')))
		{
			throw new BadRequest("Use GET and POST methods only.");
		}
	}
	
	/**
	 * Return request parameter, or NULL when not found. Use this instead of
	 * $_GET or $_POST or $_REQUEST.
	 */
	public function get_parameter($name)
	{
		return $this->request->get_parameter($name);
	}
}