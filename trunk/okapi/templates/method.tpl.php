<?

# Shortcuts
$m = $vars['method'];

?>
<!doctype html>
<html lang='en'>
	<head>
		<meta http-equiv="content-type" content="text/html; charset=UTF-8">
		<title>OKAPI Reference - <?= $m['name'] ?></title>
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
							<?= $m['brief_description'] ?>
							<div class='subh1'>:: <b><?= $m['name'] ?></b> method</div>
						</h1>
						<table class='method' cellspacing='1px'>
							<tr>
								<td class='precaption' colspan='3'>
									<table><tr>
										<td>Consumer: <span class='<?= $m['auth_options']['consumer'] ?>'><?= $m['auth_options']['consumer'] ?></span></td>
										<td>Token: <span class='<?= $m['auth_options']['token'] ?>'><?= $m['auth_options']['token'] ?></span></td>
									</tr></table>
								</td>
							</tr>
							<tr>
								<td class='caption' colspan='3'>
									<b><?= $GLOBALS['absolute_server_URI']."okapi/".$m['name'] ?></b>
								</td>
							</tr>
							<tr>
								<td class='description' colspan='3'>
									<?= $m['description'] ?>
								</td>
							</tr>
							<? foreach ($m['arguments'] as $arg) { ?>
								<tr class='<?= $arg['class'] ?>'>
									<td class='argname'><?= $arg['name'] ?></td>
									<td class='<? echo $arg['is_required'] ? 'required' : 'optional'; ?>'><? echo $arg['is_required'] ? 'required' : 'optional'; ?></td>
									<td class='argdesc'>
										<?= $arg['description'] ?>
									</td>
								</tr>
							<? } ?>
							<tr>
								<td colspan='3' class='oauth_args'>
									<? if ($m['auth_options']['consumer'] == 'ignored') { ?>
										No additional OAuth arguments are required. If you provide any,
										they will be ignored.
									<? } else { ?>
										<b>Plus <?= $m['auth_options']['consumer'] ?></b>
										standard OAuth Consumer signing arguments:
										<i>oauth_consumer_key, oauth_nonce, oauth_timestamp, oauth_signature,
										oauth_signature_method, oauth_version</i>.
										<? if ($m['auth_options']['token'] == 'ignored') { ?>
											Token is not required.
										<? } else { ?>
											<b>Plus <?= $m['auth_options']['token'] ?></b> <i>oauth_token</i>
											for Token authorization.
										<? } ?>
									<? } ?>
								</td>
							</tr>
							<tr><td colspan='3' class='returns'>
								<p><b>Returned&nbsp;value:</b></p>
								<?= $m['returns'] ?>
							</td></tr>
						</table>
					</td>
				</tr></table>
			</div>
			<div class='okd_bottom'>
			</div>
		</div>
	</body>
</html>
