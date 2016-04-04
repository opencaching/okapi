<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:sy="http://purl.org/rss/1.0/modules/syndication/">
    <channel>
        <title>OKAPI changelog</title>
        <atom:link href="<?= $vars['site_url'] ?>changelog_feed" rel="self" type="application/rss+xml" />
        <link><?= $vars['site_url'] ?></link>
        <description>Changes to OKAPI - the publically available API for Opencaching sites</description>
        <language>en-EN</language>
        <sy:updatePeriod>hourly</sy:updatePeriod>
        <sy:updateFrequency>1</sy:updateFrequency>
<?php foreach ($vars['changes'] as $change) { ?>
        <item>
            <title>Version <?= $change['version'] ?></title>
            <link>https://github.com/opencaching/okapi/tree/<?= $change['commit'] ?></link>
            <pubDate><?= date('r', strtotime($change['time'])) ?></pubDate>
            <category><?= $change['type'] ?></category>
            <description><![CDATA[<?= $change['comment'] ?>]]></description>
        </item>
<?php } ?>
    </channel>
</rss>
