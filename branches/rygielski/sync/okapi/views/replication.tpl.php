<!doctype html>
<html lang='en'>
	<head>
		<meta http-equiv="content-type" content="text/html; charset=UTF-8">
		<title>Sign up for an API Key</title>
		<link rel="stylesheet" href="/okapi/static/common.css?<?= $vars['okapi_rev'] ?>">
		<script src='https://ajax.googleapis.com/ajax/libs/jquery/1.6.2/jquery.min.js'></script>
		<script>
			var okapi_base_url = "<?= $vars['okapi_base_url'] ?>";
		</script>
		<script src='/okapi/static/common.js?<?= $vars['okapi_rev'] ?>'></script>
	</head>
	<body class='api'>
		<div class='okd_mid'>
			<div class='okd_top'>
				<? include 'installations_box.tpl.php'; ?>
				<table cellspacing='0' cellpadding='0'><tr>
					<td class='apimenu'>
						<?= $vars['menu'] ?>
					</td>
					<td class='article'>
					
						<h1>
							Data replication
							<div class='subh1'>:: How to maintain a fresh snapshot of OKAPI database</div>
						</h1>
						
						<p>For some applications it might be desireable to have a quick access to the entire
						OpenCaching database (instead of quering for specific portions of it). You may use
						OKAPI's dbsync module to achive this effect.</p>
						
						<h2>Requirements and limitations</h2>
						
						<p>A couple of things for you to remember:</p>
						<ul>
							<li>Currently, this functionality is available <b>for developers only</b>,
							NOT for the individual users. We don't want users to download a fresh snapshot of
							the entire database every time they want, this could kill our servers.
							If you want to enable such features for your users, you can do it, but you
							should use <b>your own server</b> for data traffic (i.e. serve your own
							copy of snapshots).</li>
							<li>Please download the snapshot <b>only once</b>. Later on, use
							the services/dbsync/changelog method to download snapshot updates. If you
							download updates frequently, this should allow you to keep the fresh
							copy of our data for years.</li>
							<li>You <b>must</b> update your database frequently for this method to work.
							We don't keep the changelog indefinitelly. You must update at least once every week.</li>
							<li>You <b>should not</b> update your database more frequently than once every
							5 minutes. This won't do you any good, since we update the changelog only once
							every 5 minutes anyway.</li>
						</ul>
						
						<h2>Understanding the changelog</h2>
						
						<p>Let's assume that you already have a copy of OKAPI database, but it's
						already 2 days old. How to update your copy to the most current state?</p>
						
						<p>OKAPI exposes a <b>changelog</b> to help you with that. Changelog is the
						list of all changes which appeared in the OKAPI database since the last time
						you downloaded it. What you have to do is to download this list of changes
						and to replay them on your copy of our database. After you do this, your
						database is up-to-date.</p>
						
						<p>We use <b>revision</b> numbers to keep track of all the versions of our
						database. Every time you update a database to its fresh state, you receive
						the <b>revision</b> number along with it. You must keep this number carefully,
						because you need it in order for us to generate a proper changelog for you
						next time you ask for it.</p>
						
						<p>Example:</p>
						<ul>
							<li>You download a fulldump of our database with the revision number <b>238004</b>.</li>
							<li>Later, you request the changelog for revision number <b>238004</b>.</li>
							<li>OKAPI checks if there were any changes recorded since revision <b>238004</b>.
							It responds with the list of changes and the new revision number <b>238017</b>.</li>
							<li>You receive the changes and replay them on your database. Now your database
							is at revision <b>238017</b>. Next time you'll request the changelog, you
							will use this number.</li>
						</ul>
						
						<p>See the docs of the service/dbsync/changelog method for details.</p>
						
						<h2>Understanding fulldump archive</h2>
						
						<p>Before you proceed with the download, please note:</p>
						
						<ul>
							<li>Fulldump is a copy of the entire database. We generate such copy once every couple of
							days. This copy if intended for you to start only, later you must use the changelog to
							keep it up-to-date.</li>
							<li>Fulldump is a compressed archive with JSON files. Each JSON file contains a
							list of changelog entries (in the same format as described in the services/dbsync/changelog
							method). It contains ALL the objects in the database.</li>
							<li>There is no XMLMAP version of this file.</li>
							<li>You should not assume anything permanent about the structure of this file,
							i.e. the names and sizes of the included files may change in future. If you
							set up everything correctly then you need fulldump only once.</li>
						</ul>
						
						<p>Download your copy of fulldump archive using services/dbsync/fulldump method.</p>
						
						<div class='issue-comments' issue_id='WRTODO'></div>
					</td>
				</tr></table>
			</div>
			<div class='okd_bottom'>
			</div>
		</div>
	</body>
</html>
