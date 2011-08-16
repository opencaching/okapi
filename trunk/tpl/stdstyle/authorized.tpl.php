<? $vars = $GLOBALS['tplvars']; ?>

<style>
	.okapi { font-size: 15px; font-family: "lucida grande", "Segoe UI", tahoma, arial, sans-serif; color: #555; margin: 20px 60px 0 40px; }
	.okapi * { padding: 0; margin: 0; border: 0; }
	.okapi input, select { font-size: 15px; font-family: "lucida grande", "Segoe UI", tahoma, arial, sans-serif; color: #444; }
	.okapi a, .okapi a:hover, .okapi a:visited { cursor: pointer; color: #3e48a8; text-decoration: underline; }
	.okapi span.note { color: #888; font-size: 70%; font-weight: normal; }
	.okapi h1 { padding: 12px 0 30px 0; font-weight: bold; font-style: italic; font-size: 22px; color: #bb4924; }
	.okapi p { margin-bottom: 15px; font-size: 15px; }
	.okapi .pin { margin: 20px 20px 0 0; background: #eee; border: 1px solid #ccc; padding: 20px 40px; text-align: center; font-size: 24px; }
</style>

<div class='okapi'>
	<img src='/images/okapi/logo-xsmall.gif' style='float: right'>
	<h1>Pomyślnie dałeś dostęp</h1>
	<p><b>Właśnie dałeś dostęp aplikacji <?= $vars['token']['consumer_name'] ?> do Twojego
	konta OpenCaching.</b>
	Aby zakończyć operację, wróć teraz do aplikacji <?= $vars['token']['consumer_name'] ?>
	i wpisz następujący kod PIN:</p>
	
	<div class='pin'><?= $vars['verifier'] ?></div>
</div>
