<?php
# This script MUST be run after each update of changes.xml.
# It inserts version numbers and dates.

exec('git --git-dir='.__DIR__.'/../.git log --pretty=raw', $gitlog);
if (!$gitlog)
    die("error: could not run git\n");

# parse the git log; build an array of all commits

$repo_commits = array();
$time = false;

foreach ($gitlog as $line)
{
    $tokens = explode(' ', $line);
    switch ($tokens[0])
    {
        case 'commit':
            $commit = substr($tokens[1], 0, 8);
            break;

        case 'committer':
            $time = $tokens[count($tokens) - 2];
            $timezone = $tokens[count($tokens) - 1];
            break;

        case '':
            if ($time) {
                $repo_commits[$commit] = array(
                    'time' => $time,
                    'timezone' => $timezone,
                    'position' => count($repo_commits)
                );
                $time = false;
            }
            break;
    }
}

$OKAPI_GIT_VERSION_BASE = 318;

# test for well-formed XML
# TODO: verify XML scheme
simplexml_load_file(__DIR__ . '/changes.xml');

# SimpleXML iterators are read-only, so we process the plain text file.
$changes = file(__DIR__ . '/changes.xml');
$changelog_commits = array();
$content_section = false;

foreach ($changes as &$line)
{
    if (strpos($line, '<changes>') !== false)
    {
        $content_section = true;
    }
    elseif ($content_section && preg_match('/^\s*<change /', $line))
    {
        if (preg_match(
            '/^\s*<change\s+commit="(.+?)"\s+version="(.*?)"\s+time="(.*?)"\s+type="(.+?)"(\s+infotags="(.*?)")?\s*>$/',
            $line,
            $matches
        )) {
            list(, $commit, $version, $time, $type, , $infotags) = $matches;

            if (strlen($commit) != 8)
                die("error: commit ID $commit is not 8 chars long\n");
            if (isset($changelog_commits[$commit]))
                die("error: duplicate commit ID $commit\n");
            if (!isset($repo_commits[$commit]))
                die("error: didn't find commit ID $commit in OKAPI git repo\n");
            if (!in_array($type, array('enhancement', 'bugfix', 'docs', 'other')))
                die("error: unknown type '$type' for commit $commit\n");

            $repo_version = $OKAPI_GIT_VERSION_BASE + count($repo_commits) - $repo_commits[$commit]['position'];
            $repo_time = date('Y-m-d\TH:m:s', $repo_commits[$commit]['time']) . $repo_commits[$commit]['timezone'];

            # Note: We will NOT re-calculate existing version numbers, which
            # would shift if an older commit is merged. The re-calculation
            # could cause erroneous behaviour of clients, that determine
            # the availability of new features by changelog version number.
            #
            # (Developer refers to current API docs e.g. at OC.PL and tests
            # for availability of a feature, which will *seem* unavailable
            # at an out-of-date OKAPI installation, because the shifted version
            # number mismatches the apisrv/installation version number of the
            # out-of-date installation.)

            if ($version == 0) {
                $version = $repo_version;
                echo $commit . ' version = ' . $version . "\n";
            }
            if ($time != $repo_time) {
                $time = $repo_time;
                echo $commit . ' time = ' . $time . "\n";
            }

            $line = '        <change commit="'.$commit.'" version="'.$version.'" time="'.$time.'" type="'.$type.'"';
            if ($infotags) {
                  $line .= ' infotags="'.$infotags.'"';
            }
            $line .= ">\n";

            $changelog_commits[$commit] = true;
        }
        else
        {
            die("error: unexpected <change> line format:\n$line\n");
        }
    }
}

file_put_contents(__DIR__ . '/changes.xml', $changes);
