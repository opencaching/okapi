<?php
require_once("../oauth.php");
require_once("../OAuth_TestServer.php");

/*
 * Config Section
 */
$domain = $_SERVER['HTTP_HOST'];
$base = "/okapi/oauthlib/example";
$base_url = "http://$domain$base";

/**
 * Some default objects
 */

$test_server = new TestOAuthServer(new MockOAuthDataStore());
$hmac_method = new OAuthSignatureMethod_HMAC_SHA1();
$test_server->add_signature_method($hmac_method);
$sig_methods = $test_server->get_signature_methods();

?>
