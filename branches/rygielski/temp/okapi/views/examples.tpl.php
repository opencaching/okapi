<!doctype html>
<html lang='en'>
	<head>
		<meta http-equiv="content-type" content="text/html; charset=UTF-8">
		<title>OKAPI Examples</title>
		<link rel="stylesheet" href="/okapi/static/common.css?<?= $vars['okapi_rev'] ?>">
		<link type="text/css" rel="stylesheet" href="/okapi/static/syntax_highlighter/SyntaxHighlighter.css"></link>
		<script src='https://ajax.googleapis.com/ajax/libs/jquery/1.6.2/jquery.min.js'></script>
		<script>
			var okapi_base_url = "<?= $vars['okapi_base_url'] ?>";
		</script>
		<script src='/okapi/static/common.js?<?= $vars['okapi_rev'] ?>'></script>
		<script language="javascript" src="/okapi/static/syntax_highlighter/shCore.js"></script>
		<script language="javascript" src="/okapi/static/syntax_highlighter/shBrushPhp.js"></script>
		<script language="javascript">
			$(function() {
				dp.SyntaxHighlighter.ClipboardSwf = '/okapi/static/syntax_highlighter/clipboard.swf';
				dp.SyntaxHighlighter.HighlightAll('code');
			});
		</script>
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

<h1>Examples</h1>

<p>Some examples of OKAPI usage with popular programming languages.

<h2>PHP</h2>

<p><b>Example 1.</b> This will print the number of users in the <?= $vars['site_name'] ?> installation:

<pre name="code" class="php:nogutter:nocontrols">
&lt;?

$json = file_get_contents("<?= $vars['okapi_base_url'] ?>services/apisrv/stats");
$data = json_decode($json);
print "Number of <?= $vars['site_name'] ?> users: ".$data->user_count;

?>
</pre>

<p><b>Example 2.</b> This will print the codes of some nearest unfound caches:</p>

<pre name="code" class="php:nogutter:nocontrols">
&lt;?

/* Enter your OKAPI's URL here. */
$okapi_base_url = "http://opencaching.pl/okapi/";

/* Enter your Consumer Key here. */
$consumer_key = "YOUR_KEY_HERE";

/* Username. Caches found by the given user will be excluded from the results. */
$username = "USERNAME_HERE";

/* Your location. */
$lat = 54.3;
$lon = 22.3;

/* 1. Get the UUID of the user. */
$json = @file_get_contents($okapi_base_url."services/users/by_username".
	"?username=".$username."&amp;fields=uuid&amp;consumer_key=".$consumer_key);
if (!$json)
	die("ERROR! Check your consumer_key and/or username!\n");
$user_uuid = json_decode($json)->uuid;
print "Your UUID: ".$user_uuid."\n";
	
/* 2. Search for caches. */
$json = @file_get_contents($okapi_base_url."services/caches/search/nearest".
	"?center=".$lat."|".$lon."&amp;not_found_by=".$user_uuid."&amp;limit=5".
	"&amp;consumer_key=".$consumer_key);
if (!$json)
	die("ERROR!");
$cache_codes = json_decode($json)->results;

/* Display them. */
print "Five nearest unfound caches: ".implode(", ", $cache_codes)."\n";

?>
</pre>

<p>Please note that the above examples use very simple error checking routines.
If you want to be "professional", you should catch HTTP 400 Responses, display their
bodies (OKAPI error messages), and deal with them more gracefully.</p>

<h2>JavaScript Example</h2>

<p>It is possible to access OKAPI directly from user's browser, without the
need for server backend. OKAPI methods allow both
<a href='http://en.wikipedia.org/wiki/JSONP'>JSONP</a>
output format and
<a href='http://en.wikipedia.org/wiki/XMLHttpRequest#Cross-domain_requests'>Cross-domain
XHR requests</a>. There are some limitations of both these techniques though.</p>

<p>This example does the following:</p>
<ul>
	<li>Pulls the <a href='/okapi/services/apisrv/installations.html'>list of all OKAPI installations</a>
	from one of the OKAPI servers and displays it in a select-box. Note, that this method does not
	require Consumer Key (Level 0 Authentication).</li>
	<li>Asks you to share your location (modern browser can do that).</li>
	<li>Retrieves a list of nearest geocaches. This time, it uses the Consumer Key you have to supply.</li>
</ul>

<p><a href='/okapi/static/examples/javascript_nearest.html' style='font-size: 130%; font-weight: bold'>Run this example</a></p>

<h2>Comments</h2>

<div class='issue-comments' issue_id='36'></div>

					</td>
				</tr></table>
			</div>
			<div class='okd_bottom'>
			</div>
		</div>
	</body>
</html>
