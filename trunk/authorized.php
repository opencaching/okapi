<?php

$rootpath = './';
require_once($rootpath.'okapi/core.php');
\okapi\OkapiErrorHandler::$treat_notices_as_errors = true;

$tplname = 'authorized';
$tplvars = array();

$token_key = $_GET['oauth_token'];
$verifier = $_GET['oauth_verifier'];

$rs = sql("
	SELECT
		c.`key` as consumer_key,
		c.name as consumer_name,
		c.url as consumer_url,
		t.verifier
	FROM
		okapi_consumers c,
		okapi_tokens t
	WHERE
		t.`key` = '".mysql_real_escape_string($token_key)."'
		and t.consumer_key = c.`key`
");
$token = sql_fetch_assoc($rs);
mysql_free_result($rs);

if (!$token)
{
	# Probably Request Token has expired or it is already used. We'll
	# just redirect to the OpenCaching main page.
	tpl_redirect("index.php");
}

$tplvars['token'] = $token;
$tplvars['verifier'] = $verifier;

\okapi\OkapiErrorHandler::disable();
tpl_BuildTemplate();
\okapi\OkapiErrorHandler::reenable();
