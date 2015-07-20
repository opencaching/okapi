#!/usr/bin/env python
# -*- coding: utf-8 -*-

# This script is executed by GitHub upon every Git commit (to the official
# repository). It is currently installed on one of my servers. Contact me for
# details (rygielski@mimuw.edu.pl).

# What it does:
#   - verifies that the request came from GitHub (HMAC signature),
#   - checks if the commit changed anything within the okapi directory of the
#     master branch (only this locations matters to us),
#   - exports the code to a temporary location,
#   - strips unwanted files (etc/, README.md...),
#   - replaces Okapi::$version_number and $git_revision fields in okapi/core.php,
#   - builds a new package (hosted on http://rygielski.net/r/okapi-latest),
#   - checks out okapi directory of the opencaching-pl project,
#   - replaces the okapi directory with the new version,
#   - commits the changes to opencaching-pl project.

import contextlib
import sys
import cgi
import cgitb
import os
import hmac
import hashlib
import json
import datetime
import subprocess
import httplib
from cStringIO import StringIO

try:
    from auth import okapi_webhook_secret, ocpl_username, ocpl_password, \
        deployment_target
except ImportError:
    print (
        "A file called auth.py is required to run this script. "
        "The contents are:"
    )
    print
    print 'okapi_webhook_secret = "GitHub\'s webhook secret"'
    print 'ocpl_username = "Username with RW rights to opencaching-pl project"'
    print 'ocpl_password = "User\'s commit-password"'
    print (
        'deployment_target = "Target path for the deployed file (should end '
        'with .tar.gz)"'
    )
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
        name = 'request-' + '%s%02d%02d-%02d%02d%02d-%06d' % (
            now.year, now.month, now.day, now.hour, now.minute, now.second,
            now.microsecond
        )
        with open(name + '.out', 'w') as f:
            f.write(out[0])
        # Also, save the errors to an error log file.
        if out[1]:
            with open(name + '.err', 'w') as f:
                f.write(out[1])


def my_call(what, *args, **kwargs):
    #
    # subprocess.call(what) was not enough, because stdout and stderr are
    # StringIOs now.
    #
    kwargs['stderr'] = subprocess.STDOUT
    result = subprocess.check_output(what, *args, **kwargs)
    print result
    return result


def deploy(git_revision):
    git_rev = git_revision[:7]
    okapi_working_dir = "okapi-work-dir"
    ocpl_working_dir = "ocpl-work-dir"
    try:
        #
        print "Cleaning up after previous builds..."
        subprocess.call(["rm", "-rf", okapi_working_dir])
        subprocess.call(["rm", "-rf", ocpl_working_dir])
        #
        print "Checking out revision " + git_rev + "..."
        sys.stdout.flush()
        my_call([
            "git", "clone",
            "https://github.com/opencaching/okapi.git",
            okapi_working_dir
        ])
        my_call(["git", "checkout", git_revision], cwd=okapi_working_dir)
        sys.stdout.flush()
        #
        print "Counting the number of our commit's ancestors..."
        result = my_call(
            ["git", "rev-list", git_revision, "--count"],
            cwd=okapi_working_dir
        )
        version_number = int(result) + (1079 - 761)
        print "The official version number is: " + str(version_number)
        deployment_name = "okapi-v" + str(version_number) + "-r" + git_rev
        #
        print "Adding version information..."
        fp = open(okapi_working_dir + '/okapi/core.php', 'r')
        core_contents = fp.read()
        fp.close()
        core_contents = core_contents.replace(
            "public static $version_number = null;",
            "public static $version_number = " + str(version_number) + ";")
        core_contents = core_contents.replace(
            "public static $git_revision = null;",
            "public static $git_revision = '" + git_revision + "';")
        fp = open(okapi_working_dir + '/okapi/core.php', 'w')
        fp.write(core_contents)
        fp.close()
        #
        print "Creating archive..."
        sys.stdout.flush()
        my_call([
            "tar", "-czf", deployment_name + ".tar.gz", "okapi"
        ], cwd=okapi_working_dir)
        my_call([
            "mv", "-f",
            okapi_working_dir + "/" + deployment_name + ".tar.gz", "."
        ])
        my_call(["chmod", "444", deployment_name + ".tar.gz"])
        my_call([
            "setfacl", "-m", "u:rygielski:rw", deployment_name + ".tar.gz"
        ])
        ##my_call(["rm", "-rf", okapi_working_dir])
        #
        print "Copying archive to public_html..."
        my_call(["cp", deployment_name + ".tar.gz", deployment_target])
        sys.stdout.flush()
        #
        print "Checking out opencaching-pl/trunk/okapi..."
        sys.stdout.flush()
        my_call([
            "svn", "co",
            "https://opencaching-pl.googlecode.com/svn/trunk/okapi",
            ocpl_working_dir
        ])
        sys.stdout.flush()
        #
        print (
            "Replacing opencaching.pl's okapi contents with the latest "
            "version..."
        )
        ## This will work only with svn >=1.7!
        my_call(["rm -rf " + ocpl_working_dir + "/*"], shell=True)
        my_call(
            ["mv " + okapi_working_dir + "/okapi/* " + ocpl_working_dir],
            shell=True
        )
        my_call([
            "svn", "add", "--force", "."
        ], cwd=ocpl_working_dir)
        message = (
            "Automatic OKAPI Project update - ver. " + str(version_number) +
            " (rev. " + git_rev + ")"
        )
        print message
        my_call([
            "svn", "commit", ocpl_working_dir,
            "--non-interactive",
            "--username", ocpl_username,
            "--password", ocpl_password,
            "--no-auth-cache", "-m",
            message
        ])
        #
        print "Cleanup..."
        subprocess.call(["rm", "-rf", okapi_working_dir])
        subprocess.call(["rm", "-rf", ocpl_working_dir])
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
        signature = (
            os.environ['HTTP_X_HUB_SIGNATURE']
            if os.environ.has_key('HTTP_X_HUB_SIGNATURE')
            else "none"
        )
        assert signature[:5] == "sha1="
        
        print "Your request body is:"
        print
        print body
        print
        print "And your signature is: " + signature
        print

        # Validating the signature.

        m = hmac.new(okapi_webhook_secret, digestmod=hashlib.sha1)
        m.update(body)
        digest = "sha1=" + m.hexdigest()
        if digest == signature:
            print "Signature is VALID."
        else:
            print "Signature is INVALID. Aborting your request."
            sys.exit(1)

        # Two types of bodies:
        #
        # 1. X-GitHub-Event: ping
        # {"zen":"Design for failure.","hook_id":5341070,"hook":{"url":"https://api.github.com/orgs/opencaching/hooks/5341070","ping_url":"https://api.github.com/orgs/opencaching/hooks/5341070/pings","id":5341070,"name":"web","active":true,"events":["push"],"config":{"url":"http://duch.mimuw.edu.pl/~rygielski/opencaching-api-githooks/postcommit.py","content_type":"json","insecure_ssl":"0","secret":"********"},"updated_at":"2015-07-19T21:04:47Z","created_at":"2015-07-19T21:04:47Z"},"organization":{"login":"opencaching","id":13405646,"url":"https://api.github.com/orgs/opencaching","repos_url":"https://api.github.com/orgs/opencaching/repos","events_url":"https://api.github.com/orgs/opencaching/events","members_url":"https://api.github.com/orgs/opencaching/members{/member}","public_members_url":"https://api.github.com/orgs/opencaching/public_members{/member}","avatar_url":"https://avatars.githubusercontent.com/u/13405646?v=3","description":"Aiming to collect all community-driven opencaching-related projects in one place. Want to join? Contact us!"},"sender":{"login":"wrygiel","id":2168535,"avatar_url":"https://avatars.githubusercontent.com/u/2168535?v=3","gravatar_id":"","url":"https://api.github.com/users/wrygiel","html_url":"https://github.com/wrygiel","followers_url":"https://api.github.com/users/wrygiel/followers","following_url":"https://api.github.com/users/wrygiel/following{/other_user}","gists_url":"https://api.github.com/users/wrygiel/gists{/gist_id}","starred_url":"https://api.github.com/users/wrygiel/starred{/owner}{/repo}","subscriptions_url":"https://api.github.com/users/wrygiel/subscriptions","organizations_url":"https://api.github.com/users/wrygiel/orgs","repos_url":"https://api.github.com/users/wrygiel/repos","events_url":"https://api.github.com/users/wrygiel/events{/privacy}","received_events_url":"https://api.github.com/users/wrygiel/received_events","type":"User","site_admin":false}}
        #
        # 2. X-GitHub-Event: push
        # {"ref":"refs/heads/master","before":"d2d79972d6f790479244bcaccdab8848aaa80875","after":"5d89e69755101413fdbf2a3f84301cb3b62c34a5","created":false,"deleted":false,"forced":false,"base_ref":null,"compare":"https://github.com/opencaching/okapi/compare/d2d79972d6f7...5d89e6975510","commits":[{"id":"5d89e69755101413fdbf2a3f84301cb3b62c34a5","distinct":true,"message":"Testing automatic updates...","timestamp":"2015-07-20T12:28:00+02:00","url":"https://github.com/opencaching/okapi/commit/5d89e69755101413fdbf2a3f84301cb3b62c34a5","author":{"name":"Wojciech Rygielski","email":"rygielski@mimuw.edu.pl","username":"wrygiel"},"committer":{"name":"Wojciech Rygielski","email":"rygielski@mimuw.edu.pl","username":"wrygiel"},"added":[],"removed":[],"modified":["okapi/facade.php"]}],"head_commit":{"id":"5d89e69755101413fdbf2a3f84301cb3b62c34a5","distinct":true,"message":"Testing automatic updates...","timestamp":"2015-07-20T12:28:00+02:00","url":"https://github.com/opencaching/okapi/commit/5d89e69755101413fdbf2a3f84301cb3b62c34a5","author":{"name":"Wojciech Rygielski","email":"rygielski@mimuw.edu.pl","username":"wrygiel"},"committer":{"name":"Wojciech Rygielski","email":"rygielski@mimuw.edu.pl","username":"wrygiel"},"added":[],"removed":[],"modified":["okapi/facade.php"]},"repository":{"id":39339672,"name":"okapi","full_name":"opencaching/okapi","owner":{"name":"opencaching","email":"rt@opencaching.pl"},"private":false,"html_url":"https://github.com/opencaching/okapi","description":"Automatically exported from code.google.com/p/opencaching-api","fork":false,"url":"https://github.com/opencaching/okapi","forks_url":"https://api.github.com/repos/opencaching/okapi/forks","keys_url":"https://api.github.com/repos/opencaching/okapi/keys{/key_id}","collaborators_url":"https://api.github.com/repos/opencaching/okapi/collaborators{/collaborator}","teams_url":"https://api.github.com/repos/opencaching/okapi/teams","hooks_url":"https://api.github.com/repos/opencaching/okapi/hooks","issue_events_url":"https://api.github.com/repos/opencaching/okapi/issues/events{/number}","events_url":"https://api.github.com/repos/opencaching/okapi/events","assignees_url":"https://api.github.com/repos/opencaching/okapi/assignees{/user}","branches_url":"https://api.github.com/repos/opencaching/okapi/branches{/branch}","tags_url":"https://api.github.com/repos/opencaching/okapi/tags","blobs_url":"https://api.github.com/repos/opencaching/okapi/git/blobs{/sha}","git_tags_url":"https://api.github.com/repos/opencaching/okapi/git/tags{/sha}","git_refs_url":"https://api.github.com/repos/opencaching/okapi/git/refs{/sha}","trees_url":"https://api.github.com/repos/opencaching/okapi/git/trees{/sha}","statuses_url":"https://api.github.com/repos/opencaching/okapi/statuses/{sha}","languages_url":"https://api.github.com/repos/opencaching/okapi/languages","stargazers_url":"https://api.github.com/repos/opencaching/okapi/stargazers","contributors_url":"https://api.github.com/repos/opencaching/okapi/contributors","subscribers_url":"https://api.github.com/repos/opencaching/okapi/subscribers","subscription_url":"https://api.github.com/repos/opencaching/okapi/subscription","commits_url":"https://api.github.com/repos/opencaching/okapi/commits{/sha}","git_commits_url":"https://api.github.com/repos/opencaching/okapi/git/commits{/sha}","comments_url":"https://api.github.com/repos/opencaching/okapi/comments{/number}","issue_comment_url":"https://api.github.com/repos/opencaching/okapi/issues/comments{/number}","contents_url":"https://api.github.com/repos/opencaching/okapi/contents/{+path}","compare_url":"https://api.github.com/repos/opencaching/okapi/compare/{base}...{head}","merges_url":"https://api.github.com/repos/opencaching/okapi/merges","archive_url":"https://api.github.com/repos/opencaching/okapi/{archive_format}{/ref}","downloads_url":"https://api.github.com/repos/opencaching/okapi/downloads","issues_url":"https://api.github.com/repos/opencaching/okapi/issues{/number}","pulls_url":"https://api.github.com/repos/opencaching/okapi/pulls{/number}","milestones_url":"https://api.github.com/repos/opencaching/okapi/milestones{/number}","notifications_url":"https://api.github.com/repos/opencaching/okapi/notifications{?since,all,participating}","labels_url":"https://api.github.com/repos/opencaching/okapi/labels{/name}","releases_url":"https://api.github.com/repos/opencaching/okapi/releases{/id}","created_at":1437321829,"updated_at":"2015-07-19T17:05:07Z","pushed_at":1437388105,"git_url":"git://github.com/opencaching/okapi.git","ssh_url":"git@github.com:opencaching/okapi.git","clone_url":"https://github.com/opencaching/okapi.git","svn_url":"https://github.com/opencaching/okapi","homepage":null,"size":0,"stargazers_count":0,"watchers_count":0,"language":"PHP","has_issues":true,"has_downloads":false,"has_wiki":false,"has_pages":false,"forks_count":0,"mirror_url":null,"open_issues_count":84,"forks":0,"open_issues":84,"watchers":0,"default_branch":"master","stargazers":0,"master_branch":"master","organization":"opencaching"},"pusher":{"name":"wrygiel","email":"rygielski@mimuw.edu.pl"},"organization":{"login":"opencaching","id":13405646,"url":"https://api.github.com/orgs/opencaching","repos_url":"https://api.github.com/orgs/opencaching/repos","events_url":"https://api.github.com/orgs/opencaching/events","members_url":"https://api.github.com/orgs/opencaching/members{/member}","public_members_url":"https://api.github.com/orgs/opencaching/public_members{/member}","avatar_url":"https://avatars.githubusercontent.com/u/13405646?v=3","description":"Aiming to collect all community-driven opencaching-related projects in one place. Want to join? Contact us!"},"sender":{"login":"wrygiel","id":2168535,"avatar_url":"https://avatars.githubusercontent.com/u/2168535?v=3","gravatar_id":"","url":"https://api.github.com/users/wrygiel","html_url":"https://github.com/wrygiel","followers_url":"https://api.github.com/users/wrygiel/followers","following_url":"https://api.github.com/users/wrygiel/following{/other_user}","gists_url":"https://api.github.com/users/wrygiel/gists{/gist_id}","starred_url":"https://api.github.com/users/wrygiel/starred{/owner}{/repo}","subscriptions_url":"https://api.github.com/users/wrygiel/subscriptions","organizations_url":"https://api.github.com/users/wrygiel/orgs","repos_url":"https://api.github.com/users/wrygiel/repos","events_url":"https://api.github.com/users/wrygiel/events{/privacy}","received_events_url":"https://api.github.com/users/wrygiel/received_events","type":"User","site_admin":false}}

        data = json.loads(body)
        if 'ref' not in data:
            print "Not a push event. Aborting."
            sys.exit(0)
        if data['ref'] != "refs/heads/master":
            print "These commits did not change the 'master' branch. Aborting."
            sys.exit(0)
        
        new_revision = data['after']
        filenames = []
        for commit_data in data['commits']:
            for key in ['added', 'modified', 'removed']:
                for filename in commit_data[key]:
                    filenames.append(filename)
        print
        print "Files affected:\n" + "\n".join(filenames)
        print

        important_filenames = filter(
            lambda filename: filename.startswith("okapi/"), filenames
        )
        if len(important_filenames) == 0:
            print "Deployment package was unaffected by this commit. Aborting."
            sys.exit(0)

        deploy(new_revision)
