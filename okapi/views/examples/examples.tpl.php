<!doctype html>
<html lang='en'>
    <head>
        <meta http-equiv="content-type" content="text/html; charset=UTF-8">
        <title>OKAPI Examples</title>
        <link rel="stylesheet" href="<?= $vars['okapi_base_url'] ?>static/common.css?<?= $vars['okapi_rev'] ?>">
        <link rel="icon" type="image/x-icon" href="<?= $vars['okapi_base_url'] ?>static/favicon.ico">
        <script>
            var okapi_base_url = "<?= $vars['okapi_base_url'] ?>";
            document.addEventListener('DOMContentLoaded', function() {
                var toc = document.getElementById('toc');
                var headers = document.getElementsByTagName('h2');
                for (var i = 0; i < headers.length; i++) {
                    var a = document.createElement('a');
                    a.textContent = headers[i].textContent;
                    a.setAttribute('href', '#' + headers[i].id);
                    var div = document.createElement('div');
                    div.appendChild(a);
                    toc.appendChild(div);
                }
            });
        </script>
        <script src='<?= $vars['okapi_base_url'] ?>static/common.js?<?= $vars['okapi_rev'] ?>'></script>
    </head>
    <body class='api'>
        <div class='okd_mid'>
            <div class='okd_top'>
                <?php include __DIR__ . '/../snippets/installations_box.tpl.php'; ?>
                <table cellspacing='0' cellpadding='0'><tr>
                    <td class='apimenu'>
                        <?= $vars['menu'] ?>
                    </td>
                    <td class='article'>

<h1>Examples, libraries and tools</h1>

<div id='toc'></div>

<h2 id='tools'>Tools</h2>

If you're just getting to know OKAPI, you might want to check out
<a href='https://github.com/wrygiel/okapi-browser'>OKAPI Browser</a>
(<a href='https://raw.github.com/wrygiel/okapi-browser/master/extras/screenshot.png'>screenshot</a>),
a basic OAuth Console for OKAPI methods. It is an open-source project. You can
<a href='https://github.com/wrygiel/okapi-browser'>fork it</a> on GitHub or simply
<a href='https://opencaching.pl/download/okapi-browser/'>install it here</a>.

<h2 id='libraries'>Are there any client libraries?</h2>

<p>OKAPI <b>does not</b> require you to use any special libraries. You should use
OKAPI "as is", via basic HTTP requests and responses.</p>

<p>However, if you know of any useful third-party libraries, then let us know.
We may choose to post a link somewhere here.</p>

<div class='issue-comments' issue_id='96'></div>

<h2 id='php1'>PHP Example 1 - simple query</h2>

<p>Please note that the examples below use extremely simplied error checking
routines. If you want to be "professional", then you should catch all HTTP 400
Responses, read their bodies (OKAPI error messages), and deal with them more
gracefully.</p>

<p>This will print the number of users in the <?= $vars['site_name'] ?> installation:</p>

<pre>&lt;?php
$okapi_base_url = "<?= $vars['okapi_base_url'] ?>";
$json = file_get_contents($okapi_base_url."services/apisrv/stats");
$data = json_decode($json);
print "Number of <?= $vars['site_name'] ?> users: ".$data-&gt;user_count;</pre>

<h2 id='php2'>PHP Example 2 - search for nearest geocaches</h2>

<p>This will print the codes of some nearest unfound caches:</p>

<pre>&lt;?php

/* Enter your OKAPI's URL here. */
$okapi_base_url = "<?= $vars['okapi_base_url'] ?>";

/* Enter your Consumer Key here. */
$consumer_key = "YOUR_KEY_HERE";

/* Caches found by this user will be EXCLUDED from the result. */
$username = "USERNAME_HERE";

/* Your location. */
$lat = 54.3;
$lon = 22.3;

/* 1. Get the UUID of the user. */
$json = @file_get_contents(
    $okapi_base_url."services/users/by_username".
    "?username=".$username.
    "&amp;fields=uuid".
    "&amp;consumer_key=".$consumer_key
);
if (!$json)
    die("ERROR! Check your consumer_key and/or username!\n");
$user_uuid = json_decode($json)-&gt;uuid;
print "Your UUID: ".$user_uuid."\n";

/* 2. Search for caches. */
$json = @file_get_contents(
    $okapi_base_url."services/caches/search/nearest".
    "?center=".$lat."|".$lon.
    "&amp;not_found_by=".$user_uuid.
    "&amp;limit=5".
    "&amp;consumer_key=".$consumer_key
);
if (!$json)
    die("ERROR!");
$cache_codes = json_decode($json)-&gt;results;

/* Display them. */
print "Five nearest unfound caches: ";
print implode(", ", $cache_codes)."\n";</pre>

<h2 id='js1'>JavaScript Example</h2>

<p>It is possible to access OKAPI directly from user's browser, without the
need for server backend. OKAPI allows
<a href='https://en.wikipedia.org/wiki/XMLHttpRequest#Cross-domain_requests'>Cross-domain
XHR requests</a>. You can also use
<a href='https://en.wikipedia.org/wiki/JSONP'>JSONP</a> output format.
There are some limitations of both these techniques though.</p>

<p>This example does the following:</p>

<ul>
    <li>
        Pulls the
        <a href='<?= $vars['okapi_base_url'] ?>services/apisrv/installations.html'>list of all OKAPI installations</a>
        from one of the OKAPI servers and displays it in a select-box. Note,
        that this method does not require Consumer Key (Level 0 Authentication).
    </li>
    <li>Asks you to share your location (modern browser can do that).</li>
    <li>
        Retrieves a list of nearest geocaches. (This time, it needs to use a
        Consumer Key which you will have to supply.)
    </li>
</ul>

<p><a href='<?= $vars['okapi_base_url'] ?>static/examples/javascript_nearest.html' style='font-size: 130%; font-weight: bold'>Run this example</a></p>

<h2 id='cs1'>C# Example</h2>

<p><a href='https://github.com/wrygiel/okapi-browser'>OKAPI Browser</a>
(already mentioned in the Tools section) is an open-source project. Written in C#.NET, uses
the <a href='<?= $vars['okapi_base_url'] ?>services/apisrv/installations.html'>apisrv</a>
and <a href='<?= $vars['okapi_base_url'] ?>services/apiref/method_index.html'>apiref</a>
modules to dynamically retrieve the current list of OKAPI installations and methods.
<a href='https://github.com/wrygiel/okapi-browser'>Get the source</a> or
<a href='https://opencaching.pl/download/okapi-browser/'>try it</a> first.</p>

<h2 id='comments'>Comments</h2>

<div class='issue-comments' issue_id='36'></div>

                    </td>
                </tr></table>
            </div>
            <div class='okd_bottom'>
            </div>
        </div>
    </body>
</html>
