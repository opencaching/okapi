<?php

$rootpath = './';
require_once($rootpath.'okapi/core.php');
\okapi\OkapiErrorHandler::$treat_notices_as_errors = true;

$tplname = 'authorize';
$tplvars = array();

$token_key = $_GET['oauth_token'];

$rs = sql("
	select
		c.`key` as consumer_key,
		c.name as consumer_name,
		c.url as consumer_url,
		t.callback,
		t.verifier
	from
		okapi_consumers c,
		okapi_tokens t
	where
		t.`key` = '".mysql_real_escape_string($token_key)."'
		and t.consumer_key = c.`key`
");
$token = sql_fetch_assoc($rs);
mysql_free_result($rs);
$callback_concat_char = (strpos($token['callback'], '?') === false) ? "?" : "&";

if (!$token)
{
	# Probably Request Token has expired. This will be usually viewed
	# by the user, who knows nothing on tokens and OAuth. Let's be nice then!
	$tplvars['token_expired'] = true;
	\okapi\OkapiErrorHandler::disable();
	tpl_BuildTemplate();
	\okapi\OkapiErrorHandler::reenable();
	die();
}

# Ensure a user is logged in.

if ($usr == false)
{
	$target = urlencode(tpl_get_current_page());
	tpl_redirect('login.php?target='.$target);
}

# Check if this user has already authorized this Consumer. If he did,
# then we will automatically authorize all subsequent Request Tokens
# from this Consumer.

$authorized = sqlValue("
	select 1
	from okapi_authorizations
	where
		user_id = '".mysql_real_escape_string($usr['userid'])."'
		and consumer_key = '".mysql_real_escape_string($token['consumer_key'])."'
", 0);

if (!$authorized)
{
	if (isset($_POST['authorization_result']))
	{
		# Not yet authorized, but user have just submitted the authorization form.
		
		if ($_POST['authorization_result'] == 'granted')
		{
			sql("
				INSERT INTO okapi_authorizations (consumer_key, user_id)
				VALUES (
					'".mysql_real_escape_string($token['consumer_key'])."',
					'".mysql_real_escape_string($usr['userid'])."'
				);
			");
			$authorized = true;
		}
		else
		{
			# User denied access. Nothing sensible to do now. Will try to report
			# back to the Consumer application with an error.
			
			if ($token['callback'])
			{
				header("HTTP/1.1 303 See Other");
				header("Location: ".$token['callback'].$callback_concat_char."error=access_denied");
				die();
			} else {
				# Consumer did not provide a callback URL (oauth_callback=oob).
				# Well, we'll have to redirect to the OpenCaching main page then...
				tpl_redirect('index.php');
			}
		}
	}
	else
	{
		# Not yet authorized. Display an authorization request.
		$tplvars['token'] = $token;
		
		\okapi\OkapiErrorHandler::disable();
		tpl_BuildTemplate();
		\okapi\OkapiErrorHandler::reenable();
		
		die();
	}
}

# User granted access. Now we can authorize the Request Token.

sql("
	update okapi_tokens
	set user_id = '".mysql_real_escape_string($usr['userid'])."'
	where `key` = '".mysql_real_escape_string($token_key)."';
");

# Redirect to the callback_url.

if ($token['callback'])
{
	header("HTTP/1.1 303 See Other");
	header("Location: ".$token['callback'].$callback_concat_char."oauth_token=".$token_key."&oauth_verifier=".$token['verifier']);
} else {
	# Consumer did not provide a callback URL (probably the user is using a desktop
	# or mobile application). We'll just have to display the verifier to the user.
	tpl_redirect("authorized.php?oauth_token=".$token_key."&oauth_verifier=".$token['verifier']);
}
