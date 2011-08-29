<?

include_once 'oauth.php';

/** Exception raised on failed OKAPI requests. */
class OkapiError extends Exception {}

/** Exception raised on improperly signed OKAPI requests. */
class OkapiAuthError extends OkapiError {}

/** Exception raised on invalid OKAPI requests. */
class OkapiUserError extends OkapiError {}

class OkapiConnector
{
	public $base_url;
	public $signature_method;
	public $consumer;
	public $token;
	
	/**
	 * Initialize OKAPI Connector.
	 * 
	 * @param string $okapi_base_url base URL of the OKAPI installation,
	 *   e.g. "http://opencaching.pl/okapi/".
	 */
	public function __construct($okapi_base_url)
	{
		$this->base_url = $okapi_base_url;
		$this->signature_method = new OAuthSignatureMethod_HMAC_SHA1();
	}
	
	/**
	 * Call this to start signing subsequent requests with a Consumer Key.
	 * 
	 * @param string $consumer_key Your Consumer Key. 
	 * @param string $consumer_secret Your Consumer Secret.
	 */
	public function include_consumer($consumer_key, $consumer_secret)
	{
		$this->consumer = new OAuthConsumer($consumer_key, $consumer_secret);
	}
	
	/**
	 * Call this to start signing subsequent request with the given Token.
	 * 
	 * @param unknown_type $token_key Request Token or Access Token.
	 * @param unknown_type $token_secret Token Secret.
	 */
	public function include_token($token_key, $token_secret)
	{
		if (!$this->consumer)
		{
			throw new Exception(
				"OKAPI Tokens are useless without a valid Consumer Key. ".
				"You must first call the include_consumer method."
			);
		}
		$this->token = new OAuthToken($token_key, $token_secret);
	}
	
	/**
	 * Do a POST request and return the response body. Throw an
	 * Exception when HTTP status code is different than 200.
	 */
	public static function do_post_request($url, $data)
	{
		$parts = parse_url($url);
		$fp = fsockopen($parts['host'], 80);
		fwrite($fp, "POST ".$parts['path']." HTTP/1.1\r\n");
		fwrite($fp, "Host: ".$parts['host']."\r\n");
		fwrite($fp, "Content-Type: application/x-www-form-urlencoded\r\n");
		fwrite($fp, "Content-Length: ".strlen($data)."\r\n");
		fwrite($fp, "Connection: close\r\n");
		fwrite($fp, "\r\n");
		fwrite($fp, $data);
		$http_status_line = fgets($fp, 1024);
		$matches = array();
		preg_match("#^HTTP/[^ ]+ ([0-9][0-9][0-9])#", $http_status_line, $matches);
		$status = $matches[1];
		while (!feof($fp))
		{
			$line = fgets($fp, 1024);
			if ($line == "\r\n")
				break;
		}
		$body = stream_get_contents($fp);
		if ($status == 200)
			return $body;
		elseif ($status == 401)
			throw new OkapiAuthError($body);
		elseif ($status == 400)
			throw new OkapiUserError($body);
		else
			throw new OkapiError($body);
	}
	
	/**
	 * Call a given OKAPI method and return the response body.
	 * 
	 * @param string $methodname Name of the method (starts with "services/").
	 * @param array $params Dictionary (associative array) of method arguments.
	 */
	public function get_body($methodname, $params = null)
	{
		if ($params == null)
			$params = array();
		$method_url = $this->base_url.$methodname;
		if ($this->consumer)
		{
			$request = OAuthRequest::from_consumer_and_token(
				$this->consumer, $this->token, 'POST', $method_url, $params);
			$request->sign_request($this->signature_method, $this->consumer,
				$this->token);
			$url = $request->get_normalized_http_url();
			$postdata = $request->to_postdata();
		}
		else
		{
			$url = $method_url;
			$postdata = OAuthUtil::build_http_query($params);
		}
		return self::do_post_request($url, $postdata);
	}
	
	/**
	 * Call a given OKAPI method and return, parse JSON object and
	 * return the result.
	 * 
	 * @param string $methodname Name of the method (starts with "services/").
	 * @param array $params Dictionary (associative array) of method arguments.
	 */
	public function get_json($methodname, $params, $assoc = true)
	{
		$json = $this->get_body($methodname, $params);
		return json_decode($json, $assoc);
	}
}
