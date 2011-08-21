<!doctype html>
<html lang='en'>
	<head>
		<meta http-equiv="content-type" content="text/html; charset=UTF-8">
		<title>OKAPI Examples</title>
		<link rel="stylesheet" href="/images/okapi/common.css">
		<script src='https://ajax.googleapis.com/ajax/libs/jquery/1.6.2/jquery.min.js'></script>
		<script>
			var okapi_base_url = "<?= $vars['okapi_base_url'] ?>";
		</script>
		<script src='/images/okapi/common.js'></script>
	</head>
	<body class='api'>
		<div class='okd_mid'>
			<div class='okd_top'>
				<table cellspacing='0' cellpadding='0'><tr>
					<td class='apimenu'>
						<?= $vars['menu'] ?>
					</td>
					<td class='article'>

<div style='color: #c00; text-align: center; margin-bottom: 10px'>
	Please note: this is a BETA developer-preview version.<br>
	Method signatures may change slightly during the next few days.
</div>

<h1>Examples</h1>

<p>Some examples of OKAPI usage with popular programming languages.

<h2>JavaScript</h2>

<p>It is possible to access OKAPI directly from user's browser, without the
need for server backend. OKAPI methods allow both
<a href='http://en.wikipedia.org/wiki/JSONP'>JSONP</a>
output format and
<a href='http://en.wikipedia.org/wiki/XMLHttpRequest#Cross-domain_requests'>Cross-domain
XHR requests</a>. There are some limitations of both these techniques though.</p>

<p><a href='/images/okapi/examples/javascript_nearest.html'>JavaScript Example</a> - checks your location and displays nearest geocaches.</p>


					</td>
				</tr></table>
			</div>
			<div class='okd_bottom'>
			</div>
		</div>
	</body>
</html>
