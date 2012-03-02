<!doctype html>
<html lang='en'>
	<head>
		<meta http-equiv="content-type" content="text/html; charset=UTF-8">
		<title>OKAPI - OpenCaching API</title>
		<link rel="stylesheet" href="/okapi/static/common.css?<?= $vars['okapi_rev'] ?>">
		<script src='https://ajax.googleapis.com/ajax/libs/jquery/1.6.2/jquery.min.js'></script>
		<script>
			var okapi_base_url = "<?= $vars['okapi_base_url'] ?>";
		</script>
		<script src='/okapi/static/common.js?<?= $vars['okapi_rev'] ?>'></script>
	</head>
	<body class='api'>
		<div class='okd_mid'>
			<div class='okd_top'>
				<? include 'installations_box.tpl.php'; ?>
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
	<li>Allows external developers to easily <b>read public</b> OpenCaching data,</li>
	<li>Allows <b>read and write private</b> (user-related) data with OAuth 3-legged authentication.</li>
</ul>
<p>The project is aiming to become a standard API for all National OpenCaching.<i>xx</i> sites.
This OKAPI installation provides API for the
<a href='<?= $vars['site_url']; ?>'><?= $vars['site_url']; ?></a> site.
Check out other OKAPI installations:</p>

<ul>
	<? foreach ($vars['installations'] as $inst) { ?>
		<li><?= $inst['site_name'] ?> - <a href='<?= $inst['okapi_base_url'] ?>'><?= $inst['okapi_base_url'] ?></a></li>
	<? } ?>
	<li>OKAPI Project Homepage - <a href='http://code.google.com/p/opencaching-api/'>http://code.google.com/p/opencaching-api/</a></li>
</ul>

<div class='issue-comments' issue_id='28'></div>

<h2 id='howto'>How can I use OKAPI?</h2>

<p>We assume that you're a software developer and you know the basics.</p>
<p><b>OKAPI is a set of simple (REST) web services.</b> Basicly, you make a proper HTTP request,
and you receive a JSON-formatted data, that you may parse and use within your own application.</p>
<p><b>Example.</b> Click the following link to run a method that prints out the list of
all available methods:</p>
<ul>
	<li>
		<p><a href='<?= $vars['site_url'] ?>okapi/services/apiref/method_index'><?= $vars['site_url'] ?>okapi/services/apiref/method_index</a></p>
		<p>Note: You need to install a proper <a href='https://chrome.google.com/webstore/detail/chklaanhfefbnpoihckbnefhakgolnmc'>Chrome</a>
		or <a href='https://addons.mozilla.org/en-US/firefox/addon/jsonview/'>Firefox</a> extension
		in order to view JSON directly in your browser.</p>
	</li>
</ul>
<p>You've made your first OKAPI request! This method was a simple one.
It didn't require any arguments and it didn't require you to use a Consumer Key.
Other methods are more complex and require you to use
<a href='<?= $vars['site_url'] ?>okapi/signup.html'>your own API key</a>.</p>

<h2 id='auth_levels'>Authentication Levels</h2>

<p>Each OKAPI method has a <b>minimum authentication level</b>.</p>
<p>This means, that if you want to call a method which requires "Level 1"
authentication, you have to use "Level 1" authentication <b>or higher</b>
("Level 2" or "Level 3" will also work).</p>
<p><b>Important:</b> Most developers will only need to use "Level 1" authentication
and don't have to care about OAuth.</p>
<ul>
	<li>
		<p><b>Level 0.</b> Anonymous. You may call this method with no extra
		arguments.</p>
		<p><code>some_method?arg=44</code></p>
	</li>
	<li>
		<p><b>Level 1.</b> Simple Consumer Authentication. You must call this
		method with <b>consumer_key</b> argument and provide the key which has
		been generated for your application on the <a href='<?= $vars['site_url'] ?>okapi/signup.html'>Sign up</a> page.</p>
		<p><code>some_method?arg=44&consumer_key=a7Lkeqf8CjNQTL522dH8</code></p>
	</li>
	<li>
		<p><b>Level 2.</b> OAuth Consumer Signature. You must call this method
		with proper OAuth Consumer signature (based on your Consumer Secret).</p>
		<p><code>some_method<br>
		?arg=44<br>
		&oauth_consumer_key=a7Lkeqf8CjNQTL522dH8<br>
		&oauth_nonce=1987981<br>
		&oauth_signature_method=HMAC-SHA1<br>
		&oauth_timestamp=1313882320<br>
		&oauth_version=1.0<br>
		&oauth_signature=mWEpK2e%2fm8QYZk1BMm%2fRR74B3Co%3d</code></p>
	</li>
	<li>
		<p><b>Level 3.</b> OAuth Consumer+Token Signature. You must call this method
		with proper OAuth Consumer+Token signature (based on both Consumer Secret and
		Token Secret).</p>
		<p><code>some_method<br>
		?arg=44<br>
		&oauth_consumer_key=a7Lkeqf8CjNQTL522dH8<br>
		&oauth_nonce=2993717<br>
		&oauth_signature_method=HMAC-SHA1<br>
		&oauth_timestamp=1313882596<br>
		&oauth_token=AKQbwa28Afp1YvQAqSyK<br>
		&oauth_version=1.0<br>
		&oauth_signature=qbNiWkUS93fz6ADoNcjuJ7psB%2bQ%3d</code></p>
	</li>
</ul>

<div class='issue-comments' issue_id='38'></div>

<h2 id='http_methods'>GET or POST?</h2>

<p>Whichever you want. OKAPI will treat GET and POST requests as equal.
You may also use the HTTP <code>Authorization</code> header for passing OAuth arguments.
OKAPI does not allow usage of PUT and DELETE requests.</p>

<h2 id='common-formatting'>Common formatting parameters</h2>

<p>Most of the methods return simple objects, such as list and dictionaries
of strings and integers. Such objects can be formatted in several ways using
<i>common formatting parameters</i>:

<ul>
	<li>
		<p><b>format</b> - name of the format in which you'd like your result
		to be returned in. Currently supported output formats:</p>
		<ul>
			<li>
				<p><b>json</b> - <a href='http://en.wikipedia.org/wiki/JSON'>JSON</a> format (default),</p>
				<p><b>Important:</b> Use <a href='https://chrome.google.com/webstore/detail/chklaanhfefbnpoihckbnefhakgolnmc'>Chrome</a>
				or <a href='https://addons.mozilla.org/en-US/firefox/addon/jsonview/'>Firefox</a> extensions
				to view JSON results directly in your browser. This simplifies debugging <b>a lot</b>!</p>
			</li>
			<li><b>jsonp</b> - <a href='http://en.wikipedia.org/wiki/JSONP'>JSONP</a> format, if
			you choose this one, you have to specify the <b>callback</b> parameter,</li>
			<li><b>xmlmap</b> - XML format. This is produced by mapping JSON datatypes to XML elements.
			Keep in mind, that XML format is larger than JSON and it takes more time to generate.
			Try to use JSON when it's possible.</li>
		</ul>
	</li>
	<li>
		<b>callback</b> - (when using JSONP output format) name of the JavaScript function
		to be executed with the result as its parameter.
	</li>
</ul>

<p><b><u>Important:</u></b> Almost all of the returned datatypes are <b>extendible</b>. This means,
that (in future) they <b>may contain data that you do not expect to be there</b>.
Such data will be included in backward-compatible manner, but still you should remember about
it in some cases (i.e. when iterating over attributes of an object). The additional data might
include special elements in GPX files or special keys in JSON responses.
Your software must ignore such occurances if it doesn't understand them!</p>

<p>Some methods expose some <b>special formatting</b> of their own, for example, they may return
a JPEG or a GPX file. Such methods do not accept <i>common formatting parameters</i>.</p>

<div class='issue-comments' issue_id='30'></div>


<h2 id='oauth'>OAuth Dance URLs</h2>

<p>If you want to use <b>Level 3</b> methods, you will have to make "the OAuth dance" (series of
method calls and redirects which provide you with an Access Token).</p>
<p>The three OAuth request URLs defined in the <a href='http://oauth.net/core/1.0a/'>OAuth specification</a> are:</p>
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
		<p>As the OAuth 1.0a specification states, it should be set to "<i>oob</i>" or a callback URL
		(this usually starts with http:// or https://, but you can use any other myapp:// scheme).</p>
		<p>For most OAuth client libraries, you just should provide
		"<i><?= $vars['site_url'] ?>okapi/services/oauth/request_token?oauth_callback=oob</i>"
		as the request_token URL, to get it started. Later, probably you'd want to switch "oob"
		to something more useful.</p>
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

<div class='issue-comments' issue_id='29'></div>


<h2 id='participate'>How can I participate in OKAPI development?</h2>

<p>OKAPI is Open Source and everyone is welcome to participate in the development.
In fact, if you'd like a particular method to exist, we encourage you to
submit your patches.</p>

<p>We have our <a href='http://code.google.com/p/opencaching-api/issues/list'>Issue tracker</a>.
You can use it to contact us!<br>You may also contact some of
<a href='http://code.google.com/p/opencaching-api/people/list'>the developers</a> directly,
if you want.</p>

<p>Visit <b>project homepage</b> for details:
<a href='http://code.google.com/p/opencaching-api/'>http://code.google.com/p/opencaching-api/</a></p>


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
