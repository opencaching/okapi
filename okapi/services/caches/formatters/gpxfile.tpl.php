<?

echo '<?xml version="1.0" encoding="utf-8"?>';

?>
<gpx xmlns="http://www.topografix.com/GPX/1/0" version="1.0" creator="OKAPI"
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
			<name><?= $c['wpt'] ?></name>
			<desc><?= htmlspecialchars($c['name'], ENT_COMPAT, 'UTF-8') ?> by WRTODO, Traditional Cache {type_text} WRTODO ({difficulty}/{terrain})</desc>
			<url><?= $c['url'] ?></url>
			<urlname><?= htmlspecialchars($c['name'], ENT_COMPAT, 'UTF-8') ?></urlname>
			<sym>Geocache</sym>
			<type>Geocache|<?= $vars['cache_GPX_types'][$c['type']] ?></type>
			<? if ($vars['ns_ground']) { ?>
				<cache archived="<?= ($c['status'] == 'archived') ? "True" : "False" ?>" available="<?= ($c['status'] == 'ready') ? "True" : "False" ?>" id="" xmlns="http://www.groundspeak.com/cache/1/0">
					<name><?= htmlspecialchars($c['name'], ENT_COMPAT, 'UTF-8') ?></name>
					<placed_by>WRTODO</placed_by>
					<owner id="">WRTODO</owner>
					<type><?= $vars['cache_GPX_types'][$c['type']] ?></type>
					<container><?= $vars['cache_GPX_sizes'][$c['size']] ?></container>
					<difficulty><?= $c['difficulty'] ?></difficulty>
					<terrain><?= $c['terrain'] ?></terrain>
					<long_description html="True"><?= htmlspecialchars($c['description'], ENT_COMPAT, 'UTF-8') ?></long_description>
					<encoded_hints><?= htmlspecialchars($c['hint'], ENT_COMPAT, 'UTF-8') ?></encoded_hints>
					<logs>
						<!-- WRTODO
						<log id="">
							<date>2010-12-15T15:29:47.000-06:00</date>
							<type>Additional Find</type>
							<finder id="">lavinka</finder>
							<text encoded="False">Znalezione bez Meteora, nie bylo latwo bo akurat wtedy filmowali nowo postawiona choinke. Ale jak sobie filmowcy poszli - zlupienie juz trudne nie bylo. Potem drugi raz towarzyszac Meteorowi :)</text>
						</log>
						-->
					</logs>
				</cache>
			<? } ?>
			<? /* TO BE INCLUDED IN ALTERNATE WAYPOINTS if ($vars['ns_gsak']) { ?>
				<wptExtension xmlns="http://www.gsak.net/xmlv1/5">
					<Parent>{waypoint} WRTODO</Parent>
					<Code>{waypoint} {wp_stage} WRTODO</Code>
				</wptExtension>
			<? } */ ?>
			<? if ($vars['ns_ox']) { ?>
				<opencaching xmlns="http://www.opencaching.com/xmlschemas/opencaching/1/0">
					<ratings>
						<awesomeness><?= $c['rating'] ?></awesomeness>
						<difficulty><?= $c['difficulty'] ?></difficulty>
						<? if ($c['size'] !== null) { ?><size><?= $c['size'] ?></size><? } ?>
						<terrain><?= $c['terrain'] ?></terrain>
					</ratings>
				</opencaching>
			<? } ?>
			<? /* if ($vars['ns_au']) { ?>
				<geocache status="WRTODO" xmlns="http://geocaching.com.au/geocache/1">
					<name>WRTODO</name>
					<owner>WRTODO</owner>
					<type>WRTODO</type>
					<container>WRTODO</container>
					<difficulty>WRTODO</difficulty>
					<terrain>WRTODO</terrain>
					<summary html="false">WRTODO</summary>
					<description html="true">
						WRTODO
					</description>
					<licence><?= $vars['installation']['site_name'] ?>, CC-BY-SA 3.0</licence>
					<logs>
						<log id="">
							<time>WRTODO</time>
							<geocacher>WRTODO</geocacher>
							<type>WRTODO</type>
							<text>WRTODO</text>
						</log>
					</logs>
				</geocache>
			<? } */ ?>
		</wpt>
	<? } ?>
</gpx>
