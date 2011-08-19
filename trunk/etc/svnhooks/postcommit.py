#!/usr/bin/env python
# -*- coding: utf-8 -*-

# This script is executed by Google Code upon every SVN commit.
# What it does:
#   - verifies that the requests came from Google (HMAC signature),
#   - checks if SVN commit changed anything within trunk/ (except the
#     trunk/etc/ directory),
#   - exports trunk to a temporary location,
#   - removes etc/,
#   - replaces Okapi::$revision field in okapi/core.php,
#   - builds a package (okapi-rNNN.tar.gz),
#   - posts the package on the Project Homepage.

# A file called auth.py is required to run this script:
# auth_key = '(Post-Commit Authentication Key)'
# password = '(my googlecode.com password)'

print "Content-Type: text/plain; charset=utf-8"
print
print "Hello there!"
print

from auth import auth_key, password

import cgi
import cgitb
cgitb.enable()

import sys
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

m = hmac.new(auth_key)
m.update(body)
digest = m.hexdigest()
if digest == signature:
	print "Signature is VALID."
else:
	print "Signature is INVALID. Aborting your request."
	sys.exit()

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
important_filenames = filter(lambda filename: not filename.startswith("/trunk/etc/"), filenames)
if len(important_filenames) == 0:
	print "Deployment package was unaffected by this commit. Aborting."
	sys.exit()

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
	subprocess.call(["tar", "-cf", deployment_name + ".tar", deployment_name])
	subprocess.call(["gzip", deployment_name + ".tar"])
	subprocess.call(["chmod", "666", deployment_name + ".tar.gz"])
	print "Removing source files..."
	subprocess.call(["rm", "-rf", deployment_name])
	sys.stdout.flush()
except OSError:
	print "Error :("
	sys.exit()

print "Deploying..."
upload_find_auth(deployment_name + ".tar.gz", "opencaching-api",
	"OKAPI revision " + str(revision) + " (automatic deployment)",
	user_name="rygielski@gmail.com", password=password)
print "Deployed."
