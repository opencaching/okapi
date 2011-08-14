<? $vars = $GLOBALS['tplvars']; ?>

<p><b>Właśnie dałeś dostęp aplikacji <?= $vars['token']['consumer_name'] ?> do Twojego
konta OpenCaching.</b><br>
Aby zakończyć operację, wróć teraz do aplikacji <?= $vars['token']['consumer_name'] ?>
i wpisz następujący kod PIN:</p>

<p><?= $vars['verifier'] ?></p>

