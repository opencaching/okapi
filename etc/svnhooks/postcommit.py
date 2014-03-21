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

import contextlib
import sys
import cgi
import cgitb
import os
import hmac
import json
import datetime
import subprocess
import httplib
from cStringIO import StringIO

import googlecode_upload

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


@contextlib.contextmanager
def capture_and_save():
    oldout,olderr = sys.stdout, sys.stderr
    try:
        out=[StringIO(), StringIO()]
        sys.stdout,sys.stderr = out
        yield out
    finally:
        sys.stdout,sys.stderr = oldout, olderr
        out[0] = out[0].getvalue()
        out[1] = out[1].getvalue()
        # After the script terminates, write the output to stdout.
        print out[0]
        # Also, save it to a log file.
        now = datetime.datetime.now()
        name = 'request-' + '%s%02d%02d-%02d%02d%02d-%06d' % (now.year, now.month, now.day, now.hour, now.minute, now.second, now.microsecond)
        with open(name + '.out', 'w') as f:
            f.write(out[0])
        # Also, save the errors to an error log file.
        if out[1]:
            with open(name + '.err', 'w') as f:
                f.write(out[1])


def my_call(what, *args, **kwargs):
    # subprocess.call(what) was not enough, because stdout and stderr are StringIOs now.
    kwargs['stderr'] = subprocess.STDOUT
    print subprocess.check_output(what, *args, **kwargs)


def deploy(revision):
    deployment_name = "okapi-r" + str(revision)
    try:
        print "Exporting revision " + str(revision) + "..."
        sys.stdout.flush()
        my_call(["svn", "export", "http://opencaching-api.googlecode.com/svn/trunk/",
            deployment_name, "-r" + str(revision)])
        sys.stdout.flush()
        print "Removing files not intended for deployment..."
        my_call(["rm", "-rf", deployment_name + "/etc"])
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
        my_call(["tar", "-czf", deployment_name + ".tar.gz", deployment_name])
        my_call(["chmod", "666", deployment_name + ".tar.gz"])
        my_call(["setfacl", "-m", "u:rygielski:r", deployment_name + ".tar.gz"])
        my_call(["rm", "-rf", deployment_name])
        print "Uploading to the Downloads page..."
        sys.stdout.flush()
        status, reason, url = googlecode_upload.upload(
            file = deployment_name + ".tar.gz",
            project_name = "opencaching-api",
            summary = "OKAPI revision " + str(revision) + " (automatic deployment)",
            user_name=okapi_username,
            password=okapi_password
        )
        if status in [httplib.FORBIDDEN, httplib.UNAUTHORIZED]:
            msg = "Error uploading! IGNORING\n" + str(status) + "\n" + str(reason) + "\n"
            print msg
            sys.stderr.write(msg)
        print "Checking out opencaching-pl/trunk/okapi..."
        sys.stdout.flush()
        my_call(["svn", "co", "https://opencaching-pl.googlecode.com/svn/trunk/okapi",
            deployment_name + "/okapi"])
        sys.stdout.flush()
        print "Replacing opencaching.pl's okapi contents with the latest version..."
        ## This will work only with svn >=1.7! For lower versions of svn see revision
        ## history of THIS file.
        my_call(["rm -rf " + deployment_name + "/okapi/*"], shell=True)
        my_call(["tar", "--overwrite", "-xf", deployment_name + ".tar.gz"])
        my_call(["svn", "add", "--force", "."], cwd = deployment_name + "/okapi")
        my_call(["svn", "commit", deployment_name + "/okapi", "--non-interactive", "--username",
            ocpl_username, "--password", ocpl_password, "--no-auth-cache", "-m",
            "Automatic OKAPI Project update (r" + str(revision) + ")"])
        print "Cleanup..."
        subprocess.call(["rm", "-rf", deployment_name])
    except subprocess.CalledProcessError, e:
        print "Error :("
        print e.output
        print str(e)
        sys.exit(1)
    except Exception, e:
        print "Error :("
        print str(e)
        sys.exit(1)

    print "Deployment complete."


if __name__ == '__main__':
    with capture_and_save() as out:

        print "Content-Type: text/plain; charset=utf-8"
        print
        print "Hello there!"
        print

        cgitb.enable()

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

        deploy(revision)
