<!doctype html>
<html lang='en'>
    <head>
        <meta http-equiv="content-type" content="text/html; charset=UTF-8">
        <title>OKAPI Changelog</title>
        <link rel="stylesheet" href="<?= $vars['okapi_base_url'] ?>static/common.css?<?= $vars['okapi_rev'] ?>">
        <link rel="icon" type="image/x-icon" href="<?= $vars['okapi_base_url'] ?>static/favicon.ico">
        <script src='https://ajax.googleapis.com/ajax/libs/jquery/1.6.2/jquery.min.js'></script>
        <script>
            var okapi_base_url = "<?= $vars['okapi_base_url'] ?>";
            $(function() {
                $('h2').each(function() {
                    $('#toc').append($("<div></div>").append($("<a></a>")
                        .text($(this).text()).attr("href", "#" + $(this).attr('id'))));
                });
            });
        </script>
        <script src='<?= $vars['okapi_base_url'] ?>static/common.js?<?= $vars['okapi_rev'] ?>'></script>
    </head>
    <body class='api'>
        <div class='okd_mid'>
            <div class='okd_top'>
                <?php include 'installations_box.tpl.php'; ?>
                <table cellspacing='0' cellpadding='0'><tr>
                    <td class='apimenu'>
                        <?= $vars['menu'] ?>
                    </td>
                    <td class='article'>

                        <h1>Changes of the OKAPI interface or adminstration</h1>

                        <?php
                        $br = '';
                        foreach ($vars['changes'] as $type => $changes) {
                            if (count($changes)) {
                                if ($type == 'uninstalled') {
                                    echo "<p>The following changes are not availble yet at " . $vars['site_name'] . ":</p>";
                                    $br = '<br />';
                                } else {
                                    echo "<p>".$br."The following changes are available at " . $vars['site_name'] . ":</p>";
                                } ?>

                                <table cellspacing='1px' class='changelog'>
                                    <tr>
                                        <th>Version</th>
                                        <th>Date</th>
                                        <th>Change</th>
                                    </th>
                                <?php foreach($changes as $change) { ?>
                                    <tr>
                                        <td><a href="https://github.com/opencaching/okapi/tree/<?= $change['commit'] ?>"><?= $change['version'] ?></a></td>
                                        <td><?= $change['date'] ?></td>
                                        <td><?= ($change['type'] == 'bugfix' ? 'Fixed:' : '') . (string)$change ?></td>
                                    </tr>
                                <?php } ?>
                                </table>
                            <?php } ?>
                        <?php } ?>

                        <h2 id='comments'>Comments</h2>

                        <div class='issue-comments' issue_id='TODO'></div>

                    </td>
                </tr></table>
            </div>
            <div class='okd_bottom'>
            </div>
        </div>
    </body>
</html>
