<?php
/**
 * fogproject.org/version/index.php
 *
 * Reports the newest version of each FOG branch, and tells a server that
 * POSTs its own FOG_VERSION whether it is current.
 *
 * NOT part of the fogproject git repo -- this lives on the website host and
 * is the thing every install's About page asks "am I up to date?".
 *
 * Deployed as a git checkout of FOGProject/fog-version-check. See README.md.
 */

const REPO_RAW  = 'https://raw.githubusercontent.com/FOGProject/fogproject';
const REPO_API  = 'https://api.github.com/repos/FOGProject/fogproject';
const CACHE_TTL = 300;
// Kept in a subdirectory so the web user needs write access to cache/ ALONE,
// not to the checkout -- nothing that serves a request can rewrite index.php.
const CACHE_DIR = __DIR__ . '/cache';

/**
 * Fetch a URL, returning '' for anything that is not a clean 200.
 *
 * '' is the single "no answer" value everywhere below, which is what lets a
 * caller distinguish "GitHub said nothing" from a real version string without
 * any error plumbing.
 */
function httpGet($url)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        // The GitHub API rejects any request that sends no User-Agent.
        CURLOPT_USERAGENT => 'fogproject.org-version-check',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($code == 200 && is_string($body)) ? $body : '';
}

/**
 * Serve $name from cache, refreshing it through $fetch() when older than TTL.
 */
function cached($name, $fetch)
{
    $path = CACHE_DIR . '/' . $name;
    $fresh = file_exists($path) && (time() - filemtime($path)) <= CACHE_TTL;

    if (!$fresh) {
        $value = $fetch();
        if ($value !== '') {
            file_put_contents($path, $value);
            return $value;
        }
        // GitHub is unreachable, rate limited, or mid-outage. Keep serving the
        // last known good value and bump the mtime anyway, so the next five
        // minutes of requests do not each stall on a 20s timeout. A stale
        // version reads as "up to date"; a failed one reads as "you are out of
        // date", which is the worse lie.
        @touch($path);
    }

    return file_exists($path) ? trim(file_get_contents($path)) : '';
}

/**
 * The FOG_VERSION constant as written in a branch's PHP source.
 */
function sourceVersion($branch, $path)
{
    $source = httpGet(REPO_RAW . '/' . $branch . '/' . $path);
    if ($source !== '' && preg_match("/FOG_VERSION',\s*'([0-9A-Za-z.-]+)'/", $source, $m)) {
        return $m[1];
    }

    return '';
}

/**
 * working-1.6's build number is not in git any more, so it has to be counted.
 *
 * Since GH-1510 FOG_VERSION on that branch is GENERATED -- written at commit,
 * checkout and install time into the gitignored packages/web/commons/version.php
 * by .githooks/lib/write-version-file.sh. The value is
 * `git rev-list master..HEAD --count`, a property of the commit graph; holding
 * it in a tracked file made every branch open at once conflict on one line.
 *
 * src/Base/System.php therefore carries only the release fallback, bare
 * '1.6.0-beta' with no build number. Scraping it the way stable and dev-branch
 * are scraped yields a string no running 1.6 server can ever equal, so every
 * beta install gets told it is out of date, forever.
 *
 * GitHub's compare API reports that same count as `ahead_by`, so the version is
 * reassembled here exactly as fog-version.sh builds it: prefix, dot, count.
 *
 * per_page=1&page=2 is what keeps this cheap. The files[] diff of
 * master...working-1.6 is enormous and is only attached to page 1, so asking
 * for page 2 takes the response from ~2.5MB to ~13KB; ahead_by is on every
 * page. With the 300s cache that is 12 unauthenticated API calls an hour,
 * against a limit of 60.
 */
function betaVersion()
{
    $prefix = sourceVersion('working-1.6', 'packages/web/src/Base/System.php');
    if ($prefix === '') {
        return '';
    }
    // Tolerate the fallback ever gaining a build number of its own again.
    $prefix = preg_replace('/\.[0-9]+$/', '', $prefix);

    $compare = json_decode(
        httpGet(REPO_API . '/compare/master...working-1.6?per_page=1&page=2'),
        true
    );
    if (!isset($compare['ahead_by'])) {
        return '';
    }

    return $prefix . '.' . $compare['ahead_by'];
}

// Keyed by the request parameter each one answers to. 'alpha' is the beta
// branch: the parameter predates the branch being called beta and is a public
// contract with anything already calling this endpoint, so it stays.
$versions = [
    'stable' => cached('stable-version.txt', function () {
        return sourceVersion('stable', 'packages/web/lib/fog/system.class.php');
    }),
    'dev' => cached('dev-branch-version.txt', function () {
        return sourceVersion('dev-branch', 'packages/web/lib/fog/system.class.php');
    }),
    'alpha' => cached('betabranch-version.txt', 'betaVersion'),
];

// JSON: any combination of ?stable, ?dev, ?alpha returns just those.
$requested = array_intersect_key($versions, $_REQUEST);
if ($requested) {
    header('Content-Type: application/json');
    echo json_encode($requested);
    exit;
}

// Otherwise: the HTML blob the About page renders.
$labels = [
    'stable' => 'stable',
    'dev' => 'dev-branch',
    'alpha' => 'beta-branch',
];
$curversion = trim(isset($_REQUEST['version']) ? $_REQUEST['version'] : '');

$running = '';
foreach ($versions as $key => $version) {
    if ($version !== '' && version_compare($curversion, $version, '=')) {
        $running = $key;
        break;
    }
}

if ($running === '') {
    echo '<font face="arial" color="RED" size="4"><b>You are not running the most current version of FOG!</b></font>';
    // Escaped: $curversion is reflected straight back out of the request.
    echo '<p>You are currently running version: ' . htmlspecialchars($curversion, ENT_QUOTES) . '</p>';
    foreach ($versions as $key => $version) {
        echo '<p>Latest ' . $labels[$key] . ' version is ' . $version . '</p>';
    }
} else {
    echo "<b>Your version of FOG is up to date.</b><br/>";
    echo "You're running the latest " . $labels[$running] . ' version: ' . $versions[$running];
}
