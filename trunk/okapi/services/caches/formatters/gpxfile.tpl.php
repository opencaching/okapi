<?

echo '<?xml version="1.0" encoding="utf-8"?>';

?>
<gpx xmlns="http://www.topografix.com/GPX/1/0" version="1.0" creator="OKAPI r<?= $vars['installation']['okapi_revision'] ?>"
xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
xsi:schemaLocation="
http://www.topografix.com/GPX/1/0 http://www.topografix.com/GPX/1/0/gpx.xsd
http://www.opencaching.com/xmlschemas/opencaching/1/0 http://www.opencaching.com/xmlschemas/opencaching/1/0/opencaching.xsd
http://www.groundspeak.com/cache/1/0 http://www.groundspeak.com/cache/1/0/cache.xsd
http://geocaching.com.au/geocache/1 http://geocaching.com.au/geocache/1/geocache.xsd
http://www.gsak.net/xmlv1/5 http://www.gsak.net/xmlv1/5/gsak.xsd
">
	<name><?= $vars['installation']['site_name'] ?> Geocache Search Results</name>
	<desc><?= $vars['installation']['site_name'] ?> Geocache Search Results, downloaded via OKAPI - <?= $vars['installation']['okapi_base_url'] ?></desc>
	<author><?= $vars['installation']['site_name'] ?></author>
	<url><?= $vars['installation']['site_url'] ?></url>
	<urlname><?= $vars['installation']['site_name'] ?></urlname>
	<time><?= date('c') ?></time>
	<? foreach ($vars['caches'] as $c) { ?>
		<? list($lat, $lon) = explode("|", $c['location']); ?>
		<wpt lat="<?= $lat ?>" lon="<?= $lon ?>">
			<time><?= $c['date_created'] ?></time>
			<name><?= $c['code'] ?></name>
			<desc><?= htmlspecialchars($c['name'], ENT_COMPAT, 'UTF-8') ?> by <?= htmlspecialchars($c['owner']['username'], ENT_COMPAT, 'UTF-8') ?> :: <?= ucfirst($c['type']) ?> Cache (<?= $c['difficulty'] ?>/<?= $c['terrain'] ?><? if ($c['size'] !== null) { echo "/".$c['size']; } ?>/<?= $c['rating'] ?>)</desc>
			<url><?= $c['url'] ?></url>
			<urlname><?= htmlspecialchars($c['name'], ENT_COMPAT, 'UTF-8') ?></urlname>
			<sym>Geocache</sym>
			<type>Geocache|<?= $vars['cache_GPX_types'][$c['type']] ?></type>
			<? if ($vars['ns_ground']) { /* Does user want us to include Groundspeak <cache> element? */ ?>
				<cache archived="<?= ($c['status'] == 'Archived') ? "True" : "False" ?>" available="<?= ($c['status'] == 'Available') ? "True" : "False" ?>" id="" xmlns="http://www.groundspeak.com/cache/1/0">
					<name><?= htmlspecialchars($c['name'], ENT_COMPAT, 'UTF-8') ?></name>
					<placed_by><?= htmlspecialchars($c['owner']['username'], ENT_COMPAT, 'UTF-8') ?></placed_by>
					<owner id="<?= $c['owner']['uuid'] ?>"><?= htmlspecialchars($c['owner']['username'], ENT_COMPAT, 'UTF-8') ?></owner>
					<type><?= $vars['cache_GPX_types'][$c['type']] ?></type>
					<container><?= $vars['cache_GPX_sizes'][$c['size']] ?></container>
					<difficulty><?= $c['difficulty'] ?></difficulty>
					<terrain><?= $c['terrain'] ?></terrain>
					<long_description html="True">
						&lt;p&gt;&lt;a href="<?= $c['url'] ?>"&gt;<?= htmlspecialchars($c['name'], ENT_COMPAT, 'UTF-8') ?>&lt;/a&gt;
						by &lt;a href='<?= $c['owner']['profile_url'] ?>'&gt;<?= htmlspecialchars($c['owner']['username'], ENT_COMPAT, 'UTF-8') ?>&lt;/a&gt;&lt;/p&gt;
						<?= htmlspecialchars($c['description'], ENT_COMPAT, 'UTF-8') ?>
					</long_description>
					<encoded_hints><?= htmlspecialchars($c['hint'], ENT_COMPAT, 'UTF-8') ?></encoded_hints>
					<? if ($vars['latest_logs']) { /* Does user want us to include latest log entries? */ ?>
						<logs>
							<? foreach ($c['latest_logs'] as $log) { ?>
								<log id="<?= $log['uuid'] ?>">
									<date><?= $log['date'] ?></date>
									<type><?= $vars['GPX_log_types'][$log['type']] ?></type>
									<finder id="<?= $log['user']['uuid'] ?>"><?= $log['user']['username'] ?></finder>
									<text encoded="False"><?= htmlspecialchars($log['comment'], ENT_COMPAT, 'UTF-8') ?></text>
								</log>
							<? } ?>
						</logs>
					<? } ?>
				</cache>
			<? } ?>
			<? /* TO BE INCLUDED IN ALTERNATE WAYPOINTS if ($vars['ns_gsak']) { ?>
				<wptExtension xmlns="http://www.gsak.net/xmlv1/5">
					<Parent>{waypoint} WRTODO</Parent>
					<Code>{waypoint} {wp_stage} WRTODO</Code>
				</wptExtension>
			<? } */ ?>
			<? if ($vars['ns_ox']) { /* Does user want us to include Garmin's <opencaching> element? */ ?>
				<opencaching xmlns="http://www.opencaching.com/xmlschemas/opencaching/1/0">
					<ratings>
						<? if ($c['rating'] !== null) { ?><awesomeness><?= $c['rating'] ?></awesomeness><? } ?>
						<difficulty><?= $c['difficulty'] ?></difficulty>
						<? if ($c['size'] !== null) { ?><size><?= $c['size'] ?></size><? } ?>
						<terrain><?= $c['terrain'] ?></terrain>
					</ratings>
				</opencaching>
			<? } ?>
		</wpt>
	<? } ?>
</gpx>
