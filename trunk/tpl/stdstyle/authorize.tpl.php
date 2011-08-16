<? $vars = $GLOBALS['tplvars']; ?>

<style>
	.okapi { font-size: 15px; font-family: "lucida grande", "Segoe UI", tahoma, arial, sans-serif; color: #555; margin: 20px 60px 0 40px; }
	.okapi * { padding: 0; margin: 0; border: 0; }
	.okapi input, select { font-size: 15px; font-family: "lucida grande", "Segoe UI", tahoma, arial, sans-serif; color: #444; }
	.okapi a, .okapi a:hover, .okapi a:visited { cursor: pointer; color: #3e48a8; text-decoration: underline; }
	.okapi h1 { padding: 12px 0 30px 0; font-weight: bold; font-style: italic; font-size: 22px; color: #bb4924; }
	.okapi p { margin-bottom: 15px; font-size: 15px; }
	.okapi .form { text-align: center; margin: 20px; }
	.okapi .form input { padding: 5px 15px; background: #ded; border: 1px solid #aba; margin: 0 20px 0 20px; cursor: pointer; }
	.okapi .form input:hover {background: #ada; border: 1px solid #7a7; }
</style>

<div class='okapi'>
	<? if ($vars['token_expired']) { ?>
		<h1>Przeterminowane żądanie</h1>
		<p>Niestety upłynął termin ważności żądania. Prosimy sprobować ponownie.</p>
	<? } elseif ($vars['token']) { ?>
		<img src='/images/okapi/logo-xsmall.gif' style='float: right'>
		<h1>Aplikacja zewnętrzna prosi o dostęp...</h1>
		<p><b><?= htmlentities($vars['token']['consumer_name']) ?></b> chce uzyskać
		dostęp do Twojego konta OpenCaching.
		Czy zgadzasz się na udzielenie dostępu tej aplikacji?</p>
		<form id='authform' method='POST' class='form'>
			<input type='hidden' name='authorization_result' id='authform_result' value='denied'>
			<input type='button' value="Zgadzam się" onclick="document.getElementById('authform_result').setAttribute('value', 'granted'); document.forms['authform'].submit();">
			<input type='button' value="Odmawiam" onclick="document.forms['authform'].submit();">
		</form>
		<p>Raz udzielona zgoda jest ważna aż do momentu jej wycofania na stronie
		<a href='WRTODO'>zarządzania aplikacjami</a>.</p>
		<p>Aplikacja będzie łączyć się z Twoim kontem poprzez <a href='/okapi/'>platformę OKAPI</a> (strona w języku
		angielskim). Uzyskanie zgody na dostęp pozwoli aplikacji na korzystanie
		ze wszystkich metod udostępnianych przez platformę OKAPI (m.in. aplikacja
		będzie mogła umieszczać komentarze pod znajdowanymi przez Ciebie skrzynkami).
		Zgodę możesz wycofać w każdym momencie.</p>
	<? } ?>
</div>