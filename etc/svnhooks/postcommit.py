#!/usr/bin/env python
# -*- coding: utf-8 -*-

# This script is executed by Google Code upon every SVN commit.
# It is currently installed on one of my servers. Contact me for
# details (rygielski@mimuw.edu.pl).

# What it does:
#   - verifies that the request came from Google (HMAC signature),
#   - checks if SVN commit changed anything within trunk/ (except the
#     trunk/etc/ directory),
#   - exports trunk to a temporary location,
#   - removes etc/,
#   - replaces Okapi::$revision field in okapi/core.php,
#   - builds a package (okapi-rNNN.tar.gz),
#   - posts the package on the Project Homepage,
#   - checks out okapi directory of the opencaching-pl project,
#   - replaces the okapi directory with the new version,
#   - commits the changes to opencaching-pl project.

print "Content-Type: text/plain; charset=utf-8"
print
print "Hello there!"
print

import sys

try:
	from auth import okapi_auth_key, okapi_username, okapi_password, ocpl_username, ocpl_password
except ImportError:
	print "A file called auth.py is required to run this script. The contents are:"
	print
	print 'okapi_auth_key = "Post-Commit Authentication Key for opencaching-api project"'
	print 'okapi_username = "Username with RW rights to opencaching-api project"'
	print 'okapi_password = "User\'s commit-password"'
	print 'ocpl_username = "Username with RW rights to opencaching-pl project"'
	print 'ocpl_password = "User\'s commit-password"'
	sys.exit(1)
	
import cgi
import cgitb
cgitb.enable()
import os
import hmac
import json
from googlecode_upload import upload_find_auth

# Reading request body and signature.

body = sys.stdin.read()
# e.g. body = '{"repository_path":"http://opencaching-api.googlecode.com/svn/","project_name":"opencaching-api","revisions":[{"added":["/trunk/test.txt"],"author":"rygielski@gmail.com","url":"http://opencaching-api.googlecode.com/svn-history/r23/","timestamp":1313750735,"message":"Testing SVN hooks.","path_count":1,"removed":[],"modified":[],"revision":23}],"revision_count":1}'
signature = os.environ['HTTP_GOOGLE_CODE_PROJECT_HOSTING_HOOK_HMAC'] if os.environ.has_key('HTTP_GOOGLE_CODE_PROJECT_HOSTING_HOOK_HMAC') else "none"
# e.g. signature = '18daa1cd537cb6d5f7eda2935f81b0fb'

print "Your request body is:"
print
print body
print
print "And your signature is: " + signature
print

# Validating the signature.

m = hmac.new(okapi_auth_key)
m.update(body)
digest = m.hexdigest()
if digest == signature:
	print "Signature is VALID."
else:
	print "Signature is INVALID. Aborting your request."
	sys.exit(1)

data = json.loads(body)
filenames = []
revision = None
for rev_data in data['revisions']:
	for key in ['added', 'modified', 'removed']:
		for filename in rev_data[key]:
			filenames.append(filename)
	revision = rev_data['revision']
print
print "Files affected:\n" + "\n".join(filenames)
print

important_filenames = filter(lambda filename: filename.startswith("/trunk/"), filenames)
important_filenames = filter(lambda filename: not filename.startswith("/trunk/etc/"), important_filenames)
if len(important_filenames) == 0:
	print "Deployment package was unaffected by this commit. Aborting."
	sys.exit(0)

import subprocess

deployment_name = "okapi-r" + str(revision)
try:
	print "Exporting revision " + str(revision) + "..."
	sys.stdout.flush()
	subprocess.call(["svn", "export", "http://opencaching-api.googlecode.com/svn/trunk/",
		deployment_name, "-r" + str(revision)], stdout=sys.stdout, stderr=sys.stdout)
	sys.stdout.flush()
	print "Removing files not intended for deployment..."
	subprocess.call(["rm", "-rf", deployment_name + "/etc"])
	print "Adding version information..."
	fp = open(deployment_name + '/okapi/core.php', 'r')
	core_contents = fp.read()
	fp.close()
	core_contents = core_contents.replace(
		"public static $revision = null;",
		"public static $revision = " + str(revision) + ";")
	fp = open(deployment_name + '/okapi/core.php', 'w')
	fp.write(core_contents)
	fp.close()
	print "Creating archive..."
	sys.stdout.flush()
	subprocess.call(["tar", "-czf", deployment_name + ".tar.gz", deployment_name])
	subprocess.call(["chmod", "666", deployment_name + ".tar.gz"])
	subprocess.call(["rm", "-rf", deployment_name])
	print "Uploading to the Downloads page..."
	sys.stdout.flush()
	upload_find_auth(deployment_name + ".tar.gz", "opencaching-api",
		"OKAPI revision " + str(revision) + " (automatic deployment)",
		user_name=okapi_username, password=okapi_password)
	print "Checking out opencaching-pl/trunk/okapi..."
	sys.stdout.flush()
	subprocess.call(["svn", "co", "http://opencaching-pl.googlecode.com/svn/trunk/okapi",
		deployment_name], stdout=sys.stdout, stderr=sys.stdout)
	sys.stdout.flush()
	print "Replacing opencaching.pl's okapi contents with the latest version..."
	subprocess.call(["rm", "-rf", deployment_name + "/*"], shell=True)
	subprocess.call(["tar", "--overwrite", "-xf", deployment_name + ".tar.gz"])
	#subprocess.call(["svn", "commit", "--non-interactive", "--username", ocpl_username,
	#	"--password", ocpl_password, "--no-auth-cache", "-m", "OKAPI Project update (r" + str(revision) + ")"],
	#	stdout=sys.stdout, stderr=sys.stdout)
	#subprocess.call(["rm", "-rf", deployment_name])
except OSError, e:
	print "Error :("
	print str(e)
	sys.exit(1)

print "Deployment complete."
