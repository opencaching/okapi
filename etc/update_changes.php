<?php
# This script MUST be run after each update of changes.xml.
# It inserts version numbers and dates.

exec('git --git-dir='.__DIR__.'/../.git log --pretty=raw', $gitlog);
if (!$gitlog)
    die("error: could not run git\n");

# parse the git log; build an array of all commits

$commits = array();
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
            break;

        case '':
            if ($time) {
                $commits[$commit] = array(
                    'time' => $time,
                    'position' => count($commits)
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

foreach ($changes as &$line)
{
    if (preg_match('/^\s*<change /', $line))
    {
        if (preg_match(
            '/^\s*<change\s+commit="(.*?)"\s+version="(.*?)"\s+date="(.*?)"([^>]*)>$/',
            $line,
            $matches
        )) {
            list(, $commit, $version, $date, $rest) = $matches;

            if (strlen($commit) != 8)
                die("error: commit ID $commit is not 8 chars long\n");
            if (!isset($commits[$commit]))
                die("error: didn't find commit ID $commit in OKAPI git repo\n");

            $repo_version = $OKAPI_GIT_VERSION_BASE + count($commits) - $commits[$commit]['position'];
            $wrong_version = ($version != '' && $version != $repo_version);

            if ($version == '' || $wrong_version) {
                $version = $repo_version;
                echo $commit . ' version = ' . $version . "\n";
            }
            if ($date == '' || $wrong_version) {
                $date = date('Y-m-d H:m', $commits[$commit]['time']);
                echo $commit . ' date = ' . $date . "\n";
            }

            $line = '        <change commit="'.$commit.'" version="'.$version.'" date="'.$date.'"'.$rest.">\n";
        }
        else
        {
            die("error: unexpected <change> line format:\n$line\n");
        }
    }
}

file_put_contents(__DIR__ . '/changes.xml', $changes);
