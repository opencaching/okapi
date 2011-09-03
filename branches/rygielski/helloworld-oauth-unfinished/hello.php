<?php

error_reporting(E_ALL);
ini_set("display_errors", 1);

/* OKAPI Base URL, trailing slash included. */
$okapi_base_url = 'http://192.168.137.131/okapi/';

/* URL of THIS script. Required for callback support. */
$self_url = (isset($_SERVER['HTTPS']) ? "https://" : "http://").$_SERVER['SERVER_NAME'].$_SERVER['SCRIPT_URL'];

/* Your OKAPI Consumer Key and Secret. Visit developers page to get one. */
$consumer_key = 'key';
$consumer_secret = 'secret';

include_once 'okapi_connector.php';
$okapi = new OkapiConnector($okapi_base_url);
$okapi->include_consumer($consumer_key, $consumer_secret);

session_start();

class States
{
	const BEFORE_AUTH = 1;
	const AUTH_IN_PROGRESS = 2;
	const AFTER_AUTH = 3;
}

/* Determine session state and page to be displayed. */

if (!isset($_SESSION['state']))
	$_SESSION['state'] = States::BEFORE_AUTH;
if (isset($_GET['reset']))
	$_SESSION['state'] = States::BEFORE_AUTH;
$page = isset($_GET['page']) ? $_GET['page'] : 'welcome';
if (!in_array($page, array('welcome', 'protected')))
	$page = 'welcome';

if ($page == 'welcome')
{
	print "
		<html>
			<head>
				<title>OKAPI Hello World Sample</title>
				<meta http-equiv='content-type' content='text/html; charset=UTF-8'>
			</head>
			<body>
				<p>This is sample Hello World OKAPI application, written in PHP.</p>
				<p><a href='?page=protected'>Click here to access a protected resource</a></p>
			</body>
		</html>
	";
	exit;
}

assert($page == 'protected');

try
{
	if ($_SESSION['state'] == States::BEFORE_AUTH)
	{
		# Call "request_token" method. It returns a string in a following format:
		#   oauth_token=...&oauth_token_secret=...&oauth_callback_confirmed=...
		# We'll use the parse_str function to get the token and secret.
		
		$response = $okapi->get_body(
			"services/oauth/request_token",
			array(
				'oauth_callback' => $self_url.'?page=protected'
			)
		);
		parse_str($response, $parts);
		$_SESSION['secret'] = $parts['oauth_token_secret'];
		$_SESSION['state'] = States::AUTH_IN_PROGRESS;
		
		# Redirect the User to the Token authorization page.
		
		header('Location: '.$okapi_base_url.'services/oauth/authorize?oauth_token='.$parts['oauth_token']);
		exit;
	}
	if ($_SESSION['state'] == States::AUTH_IN_PROGRESS)
	{
		if (!isset($_GET['oauth_token'])) {
			// User came back from the authorization page, but havn't allowed access!
			header('Location: '.$self_url.'?page=welcome&reset=true');
			exit;
		}
		$okapi->include_token($_GET['oauth_token'], $_SESSION['secret']);
		
		# Call "access_token" method.
		
		$response = $okapi->get_body(
			"services/oauth/access_token",
			array(
				'oauth_verifier' => $_GET['oauth_verifier']
			)
		);
		parse_str($response, $parts);
		$_SESSION['state'] = States::AFTER_AUTH;
		$_SESSION['token'] = $parts['oauth_token'];
		$_SESSION['secret'] = $parts['oauth_token_secret'];
		header('Location: '.$self_url.'?page=protected');
		exit;
	}
	if ($_SESSION['state'] == States::AFTER_AUTH) {
		$okapi->include_token($_SESSION['token'], $_SESSION['secret']);
		$json = $okapi->get_json(
			"services/users/user",
			array(
				'fields' => 'id|username'
			)
		);
		print "
			<html>
				<head>
					<title>Success!</title>
					<meta http-equiv='content-type' content='text/html; charset=UTF-8'>
				</head>
				<body>
					<p>Hello $json[username]!</p>
					<p>I just used the <a href='${okapi_base_url}services/users/user.html'>
					services/users/user</a> method in order to get some of your details. These are the
					ones I got:</p>
					<pre>".print_r($json, true)."</pre>
					<p>Things for you to try:</p>
					<ul>
						<li>withdraw the privileges you gave me (see your
						OpenCaching Apps Administration Panel),
						then refresh this page,</li>
						<li><a href='?page=welcome&reset=true'>start over</a>.</li>
					</ul>
				</body>
			</html>
		";
		exit;
	}
} catch(OkapiAuthError $E) {
	session_destroy();
	// Probably, Access Token was revoked. We have to redo the authorization process.
	# print_r($E->lastResponse)
	# print_r($E)
	header('Location: '.$self_url.'?page=protected&reset=true');
	exit;
}

?>