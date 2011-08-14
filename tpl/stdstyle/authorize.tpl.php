<? $vars = $GLOBALS['tplvars']; ?>

<? if ($vars['token_expired']) { ?>
	Niestety upłynął termin ważności żądania. Prosimy sprobować ponownie.
<? } elseif ($vars['token']) { ?>
	<p><b><?= htmlentities($vars['token']['consumer_name']) ?></b> chce uzyskać
	dostęp do Twojego konta OpenCaching. Czy zgadzasz się na udzielenie
	dostępu tej aplikacji?</p>
	<form id='authform' method='POST'>
		<input type='hidden' name='authorization_result' id='authform_result' value='denied'>
		<input type='button' value="Zgadzam się" onclick="document.getElementById('authform_result').setAttribute('value', 'granted'); document.forms['authform'].submit();">
		<input type='button' value="Odmawiam" onclick="document.forms['authform'].submit();">
	</form>
	<p>Raz udzielona zgoda jest ważna aż do momentu jej wycofania na stronie
	<a href='WRTODO'>zarządzania aplikacjami</a>.</p>
<? } ?>
