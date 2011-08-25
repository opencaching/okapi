<!doctype html>
<html>
	<head>
		<meta http-equiv="content-type" content="text/html; charset=UTF-8">
		<title>Authorization Succeeded</title>
	</head>
	<style>
		.okapi { font-size: 15px; max-width: 600px; font-family: "lucida grande", "Segoe UI", tahoma, arial, sans-serif; color: #555; margin: 20px 60px 0 40px; }
		.okapi .opencaching { font-size: 20px; font-weight: bold; padding-top: 13px; }
		.okapi * { padding: 0; margin: 0; border: 0; }
		.okapi input, select { font-size: 15px; font-family: "lucida grande", "Segoe UI", tahoma, arial, sans-serif; color: #444; }
		.okapi a, .okapi a:hover, .okapi a:visited { cursor: pointer; color: #3e48a8; text-decoration: underline; }
		.okapi h1 { padding: 12px 0 30px 0; font-weight: bold; font-style: italic; font-size: 22px; color: #bb4924; }
		.okapi p { margin-bottom: 15px; font-size: 15px; }
		.okapi .form { text-align: center; margin: 20px; }
		.okapi .form input { padding: 5px 15px; background: #ded; border: 1px solid #aba; margin: 0 20px 0 20px; cursor: pointer; }
		.okapi .form input:hover {background: #ada; border: 1px solid #7a7; }
		.okapi span.note { color: #888; font-size: 70%; font-weight: normal; }
		.okapi .pin { margin: 20px 20px 20px 0; background: #eee; border: 1px solid #ccc; padding: 20px 40px; text-align: center; font-size: 24px; }
	</style>
	<body>
	
		<div class='okapi'>
			<a href='/okapi/'><img src='/okapi/static/logo-xsmall.gif' alt='OKAPI' style='float: right; margin-left: 10px;'></a>
			<a href='/'><img src="/images/oc_logo.png" alt='OpenCaching' style='float: left; margin-right: 10px'></a>
			<div class='opencaching'><?= $vars['site_name'] ?></div>
			
			<h1 style='clear: both'>Pomyślnie dałeś dostęp</h1>
			<p><b>Właśnie dałeś dostęp aplikacji <?= $vars['token']['consumer_name'] ?> do Twojego
			konta <?= $vars['site_name'] ?>.</b>
			Aby zakończyć operację, wróć teraz do aplikacji <?= $vars['token']['consumer_name'] ?>
			i wpisz następujący kod PIN:</p>
			
			<div class='pin'><?= $vars['verifier'] ?></div>
			
			<p><a href='/'><?= $vars['site_name'] ?></a></p>
		</div>

	</body>
</html>
