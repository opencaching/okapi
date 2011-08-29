<!doctype html>
<html>
	<head>
		<meta http-equiv="content-type" content="text/html; charset=UTF-8">
		<title>My Apps</title>
	</head>
	<style>
		.okapi { font-size: 15px; max-width: 600px; font-family: "lucida grande", "Segoe UI", tahoma, arial, sans-serif; color: #555; margin: 20px 60px 0 40px; }
		.okapi a.opencaching { font-size: 20px; font-weight: bold; padding-top: 13px; color: #333; text-decoration: none; display: block; }
		.okapi * { padding: 0; margin: 0; border: 0; }
		.okapi input, select { font-size: 15px; font-family: "lucida grande", "Segoe UI", tahoma, arial, sans-serif; color: #444; }
		.okapi a, .okapi a:hover, .okapi a:visited { cursor: pointer; color: #3e48a8; text-decoration: underline; }
		.okapi h1 { padding: 12px 0 30px 0; font-weight: bold; font-style: italic; font-size: 22px; color: #bb4924; }
		.okapi p { margin-bottom: 15px; font-size: 15px; }
		.okapi .form { text-align: center; margin: 20px; }
		.okapi .form input { padding: 5px 15px; background: #ded; border: 1px solid #aba; margin: 0 20px 0 20px; cursor: pointer; }
		.okapi .form input:hover {background: #ada; border: 1px solid #7a7; }
		.okapi span.note { color: #888; font-size: 70%; font-weight: normal; }
		.okapi .pin { margin: 20px 20px 0 0; background: #eee; border: 1px solid #ccc; padding: 20px 40px; text-align: center; font-size: 24px; }
		.okapi ul { margin-left: 40px; }
	</style>
	<body>

		<div class='okapi'>
			<a href='/okapi/'><img src='/okapi/static/logo-xsmall.gif' alt='OKAPI' style='float: right; margin-left: 10px;'></a>
			<a href='/'><img src="/images/oc_logo.png" alt='OpenCaching' style='float: left; margin-right: 10px'></a>
			<a class='opencaching'><?= $vars['site_name'] ?></a>
			
			<h1 style='clear: both'>Twoje aplikacje</h1>
			<? if (count($vars['apps']) > 0) { ?>
				<p>Następującym aplikacjom zezwoliłeś na dostęp do swojego konta <b><?= $vars['site_name'] ?></b>.
				Na tej stronie możesz wycofać udzielone poprzednio zezwolenia. Po kliknięciu
				"usuń" aplikacja nie będzie już mogła wykonywać żadnych operacji w Twoim imieniu.</p>
				<ul>
					<? foreach ($vars['apps'] as $app) { ?>
						<li>
							<? if ($app['url']) { ?>
								<a href='<?= htmlspecialchars($app['url'], ENT_QUOTES, 'utf-8') ?>'><?= htmlspecialchars($app['name'], ENT_QUOTES, 'utf-8') ?></a>
							<? } else { ?>
								<?= htmlspecialchars($app['name'], ENT_QUOTES, 'utf-8') ?>
							<? } ?>
							- <a href='/okapi/apps/revoke_access?consumer_key=<?= $app['key'] ?>'>usuń</a>
						</li>
					<? } ?>
				</ul>
			<? } else { ?>
				<p>Dzięki platformie <a href='/okapi/'>OKAPI</a> możesz dawać zewnętrznym aplikacjom
				dostęp do Twojego konta <b><?= $vars['site_name'] ?></b>.
				Aktualnie nie pozwalasz żadnej aplikacji na działania w Twoim imieniu. Jeśli kiedyś dodasz
				jakieś aplikacje, to ich lista pojawi się tutaj.</p>
			<? } ?>
		</div>

	</body>
</html>