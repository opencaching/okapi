<!doctype html>
<html lang='en'>
	<head>
		<meta http-equiv="content-type" content="text/html; charset=UTF-8">
		<title>OKAPI Reference</title>
		<link rel="stylesheet" href="/images/okapi/common.css">
	</head>
	<body class='api'>
		<div class='okd_mid'>
			<div class='okd_top'>
				<table cellspacing='0' cellpadding='0'><tr>
					<td class='apimenu'>
						<?= $vars['menu'] ?>
					</td>
					<td class='article'>

<h1>
	The OKAPI Project
	<div class='subh1'>:: <b>OpenCaching API</b> Reference</div>
</h1>

<p><b>OKAPI</b> is a <b>public API</b> project for National OpenCaching sites (also known as
OpenCaching Nodes).</p>
<ul>
	<li>It provides your site with a set of useful well-documented API methods,</li>
	<li>Allows external developers to easily access <b>public</b> OpenCaching data,</li>
	<li>Allows access to <b>private</b> (user-related) data through OAuth 3-legged authorization.</li>
</ul>
<p>The project is aiming to become a standard API for all National OpenCaching.<i>xx</i> sites.
This OKAPI installation provides API for the
<a href='<?= $vars['site_url']; ?>'><?= $vars['site_url']; ?></a> site.</p>
<p>OKAPI is Open Source and everyone is welcome to participate in the development.
In fact, if you'd like a particular method to exist, we might encourage you to
submit a patch. Visit project homepage for details:
<a href='http://code.google.com/p/opencaching-api/'>http://code.google.com/p/opencaching-api/</a></p>

<h2 id='howto'>How can I use OKAPI?</h2>

<p>We assume that you're a software developer and you know the basics.</p>
<p><b>OKAPI is a set of simple (REST) web services.</b> Basicly, you make a proper HTTP request,
and you receive a JSON-formatted data, that you may parse and use within your own application.</p>
<p><b>Example.</b> Click the following link to run a method that prints out the list of
all available methods:</p>
<ul>
	<li><a href='<?= $vars['site_url'] ?>okapi/services/apiref/method_index'><?= $vars['site_url'] ?>okapi/services/apiref/method_index</a></li>
</ul>
<p>You've made your first OKAPI request! <b>Unfortunetely</b>, this method was a simple one.
It didn't require any arguments and it didn't require you to digitally sign you request.
Other methods are more complex and require you to understand the <b>OAuth standard</b>.</p>

<h2>Which methods require OAuth signatures?</h2>

<p>Well... most of them. You may check it in the documentation page of the method. If it reads
"<b>Consumer: required</b>" then you have to sign your request. Additionally, if it reads
"<b>Token: required</b>", that you will first have to acquire permission from an OpenCaching
user to use this method and access his/her data.</p>
<p>There are numerous OAuth tutorials and libraries on the web. You will have to know
them by heart to properly use OKAPI. We might provide some working examples in the future.</p>
<p>Go to <a href='<?= $vars['site_url'] ?>okapi/signup.html'>this page</a> to generate a
Consumer Key for your application.</p>

<h2 id='authentication'>Authentication</h2>

<p><b>The three OAuth request URLs</b> defined in the <a href='http://oauth.net/core/1.0a/'>OAuth specification</a> are:</p>
<ul>
	<li>
		<a href='<?= $vars['site_url'] ?>okapi/services/oauth/request_token'><?= $vars['site_url'] ?>okapi/services/oauth/request_token</a>
		(documentation <a href='<?= $vars['site_url'] ?>okapi/services/oauth/request_token.html'>here</a>)
	</li>
	<li>
		<a href='<?= $vars['site_url'] ?>okapi/services/oauth/authorize'><?= $vars['site_url'] ?>okapi/services/oauth/authorize</a>
		(documentation <a href='<?= $vars['site_url'] ?>okapi/services/oauth/authorize.html'>here</a>)
	</li>
	<li>
		<a href='<?= $vars['site_url'] ?>okapi/services/oauth/access_token'><?= $vars['site_url'] ?>okapi/services/oauth/access_token</a>
		(documentation <a href='<?= $vars['site_url'] ?>okapi/services/oauth/access_token.html'>here</a>)
	</li>
</ul>

<p>Things you should pay attantion to:</p>
<ul>
	<li>
		<p>The <b>oauth_callback</b> argument of the <b>request_token</b> method is <b>required</b>.</p>
		<p>As the OAuth 1.0a specification states, it should be set to "<i>oob</i>" or a callback
		URL in case you have a web front-end for your application.</p>
		<p>For most OAuth client libraries, you just should provide
		"<i><?= $vars['site_url'] ?>okapi/services/oauth/request_token?oauth_callback=oob</i>"
		as the request_token URL, to get it started. Later, probably you'd want to switch "oob"
		to something else.</p>
	</li>
	<li>
		<p>The <b>oauth_verifier</b> argument of the <b>access_token</b> method is also <b>required</b>.</p>
		<p>When user authorizes your application, he will receive a PIN code (OAuth verifier). You
		have to use this code to receive your Access Token.</p>
	</li>
	<li>
		<p><b>Access Tokens do not expire</b> (but can be revoked). This means, that once the user
		authorizes your application, you receive a "lifetime access" to his/her account (it does
		not expire after several hours). User may still <b>revoke access</b> to his account from your
		application - when this happens, you will have to redo the autorization dance.</p>
	</li>
</ul>

<h2 id='http_methods'>GET or POST?</h2>

<p>Whichever you want. OKAPI will threat GET and POST requests as equal.
You may also use the HTTP Authorization header for passing OAuth arguments.</p>

<h2 id='common-formatting'>Common formatting parameters</h2>

<p>Most of the methods return simple objects, such as list and dictionaries
of strings and integers. Such objects can be formatted in several ways using
<i>common formatting parameters</i>:

<ul>
	<li>
		<p><b>format</b> - name of the format in which you'd like your result
		to be returned in. Currently supported output formats:</p>
		<ul>
			<li><b>json</b> - <a href='http://en.wikipedia.org/wiki/JSON'>JSON</a> format (default),</li>
			<li><b>jsonp</b> - <a href='http://en.wikipedia.org/wiki/JSONP'>JSONP</a> format, if
			you choose this one, you have to specify the <b>callback</b> parameter.</li>
		</ul>
	</li>
	<li>
		<b>callback</b> - (when using JSONP output format) name of the JavaScript function
		to be executed with the result as their parameter.
	</li>
</ul>

<p>Some methods expose some <b>special formatting</b> of their own, for example, they may return
a JPEG or a GPX file. Such methods do not accept <i>common formatting parameters</i>.</p>

<h2 id='method_index'>List of available methods</h2>

<p>Currently available OKAPI web services (methods):</p>

<ul>
	<? foreach ($vars['method_index'] as $method_info) { ?>
		<li><a href='<?= $vars['site_url']."okapi/".$method_info['name'].".html" ?>'><?= $method_info['name'] ?></a> - <?= $method_info['brief_description'] ?></li>
	<? } ?>
</ul>


					</td>
				</tr></table>
			</div>
			<div class='okd_bottom'>
			</div>
		</div>
	</body>
</html>
