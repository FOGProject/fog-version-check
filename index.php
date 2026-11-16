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
// git's smart-HTTP ref advertisement: every branch and tag head in one ~100KB
// response. It is not the REST API, so it does not count against the 60 calls
// an hour this host's IP gets, a limit download.php on the same host shares.
const REPO_REFS = 'https://github.com/FOGProject/fogproject.git/info/refs?service=git-upload-pack';
const CACHE_TTL = 300;
// An answer whose inputs have not moved is still re-asked after this long, in
// case GitHub changed something no ref shows (a release flipped to prerelease).
const CACHE_MAX_AGE = 3600;
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
 * Every ref on the fogproject repo, as [ref name => commit sha].
 *
 * Cached for CACHE_TTL like everything else. This is what lets the answers
 * below skip the API: each one is keyed on the refs it depends on, and only
 * asks the API again when one of those refs has moved.
 *
 * @return array|null the refs, or null when GitHub has never answered
 */
function refs()
{
    static $refs = false;
    if ($refs !== false) {
        return $refs;
    }
    $body = cached('refs.txt', null, function () {
        // pkt-lines of "<sha> <ref>"; the first one carries "\0capabilities".
        $pattern = '#(?<sha>[0-9a-f]{40}) (?<ref>refs/[^\x00\n]+)#';
        if (!preg_match_all($pattern, httpGet(REPO_REFS), $matches, PREG_SET_ORDER)) {
            return '';
        }
        // Stored one "<ref> <sha>" per line.
        return implode("\n", array_map(function ($match) {
            return $match['ref'] . ' ' . $match['sha'];
        }, $matches));
    });
    $parsed = [];
    foreach (array_filter(explode("\n", $body)) as $line) {
        list($ref, $sha) = explode(' ', $line, 2);
        $parsed[$ref] = $sha;
    }
    $refs = $parsed ? $parsed : null;
    return $refs;
}

/**
 * The commit shas of $branches, joined: the cache key for an answer that
 * depends on those branches. null when the refs are unknown.
 */
function headsKey(...$branches)
{
    $refs = refs();
    if ($refs === null) {
        return null;
    }
    return implode(' ', array_map(function ($b) use ($refs) {
        return isset($refs["refs/heads/$b"]) ? $refs["refs/heads/$b"] : '-';
    }, $branches));
}

/**
 * The commit sha $branch points at, or the branch name when the refs are
 * unknown. Reading raw files and compares by sha matters: raw.githubusercontent
 * caches a branch-name URL for up to 300 s, so a fetch just after a push can get
 * the old file and store it under the new key for CACHE_MAX_AGE. A sha URL
 * never changes content.
 */
function headRef($branch)
{
    $refs = refs();
    return isset($refs["refs/heads/$branch"]) ? $refs["refs/heads/$branch"] : $branch;
}

/**
 * The highest release-shaped tag (X.Y.Z or X.Y.Z.N), as a stable install
 * reports it: '.0' appended to a three-part tag. '' when there is none or the
 * refs are unknown.
 */
function highestReleaseTag()
{
    $refs = refs();
    $best = '';
    foreach (array_keys($refs === null ? [] : $refs) as $ref) {
        if (preg_match('#^refs/tags/([0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?)$#', $ref, $m)
            && ($best === '' || version_compare($m[1], $best, '>'))
        ) {
            $best = $m[1];
        }
    }
    return ($best !== '' && substr_count($best, '.') === 2) ? $best . '.0' : $best;
}

/**
 * A digest of every tag name: the cache key for stable, because a new release
 * is a new tag. null when the refs are unknown.
 */
function tagsKey()
{
    $refs = refs();
    if ($refs === null) {
        return null;
    }
    return md5(implode("\n", array_filter(array_keys($refs), function ($ref) {
        return strpos($ref, 'refs/tags/') === 0;
    })));
}

/**
 * Serve $name from cache, refreshing it through $fetch() when it is stale.
 *
 * Stale means older than CACHE_TTL AND either $key changed since the last
 * good answer or that answer is older than CACHE_MAX_AGE. $key is a string
 * naming the inputs the answer depends on (commit shas); while it is
 * unchanged, the answer cannot have changed, so GitHub is not asked. A null
 * $key means "unknown", and the entry refreshes on CACHE_TTL alone.
 */
function cached($name, $key, $fetch)
{
    $path = CACHE_DIR . '/' . $name;
    $keyPath = $path . '.key';
    $age = file_exists($path) ? time() - filemtime($path) : PHP_INT_MAX;
    $same = $key !== null && file_exists($keyPath) && file_get_contents($keyPath) === $key;

    if ($age > CACHE_TTL && (!$same || $age > CACHE_MAX_AGE)) {
        // $fetch may set $key to null: "this answer is not final yet, do not
        // pin it to these refs".
        $value = $fetch($key);
        if ($value !== '') {
            file_put_contents($path, $value);
            if ($key !== null) {
                file_put_contents($keyPath, $key);
            }
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
    $source = httpGet(REPO_RAW . '/' . headRef($branch) . '/' . $path);
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
 * page. It is called only after master or working-1.6 moves; see $versions.
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
        httpGet(REPO_API . '/compare/' . headRef('master') . '...' . headRef('working-1.6') . '?per_page=1&page=2'),
        true
    );
    if (!isset($compare['ahead_by'])) {
        return '';
    }

    return $prefix . '.' . $compare['ahead_by'];
}

/**
 * The release candidate branch: the highest rc-* branch. The rc answer is
 * FOG_VERSION as committed on it.
 *
 * The branch name changes per release (rc-1.6.0, rc-1.6.1, ...), so it is
 * resolved the way the installer's channelToBranch does it in
 * lib/common/functions.sh: list the remote rc-* heads and take the highest
 * VERSION, not the newest date. rc-1.6.10 beats rc-1.6.2, which a lexical
 * sort gets backwards.
 *
 * No count is needed here. An rc branch bumps its suffix by committing it
 * into System.php (1.6.0-RC-1, -RC-2, ...), and fog-version.sh leaves the
 * committed value standing in head mode, so the source IS what a running RC
 * server reports.
 *
 * The rc-* heads come from refs(), so this makes no API call at all.
 *
 * @return string|null the rc branch name, '' when there is no rc branch, or
 *                     null when the refs are unknown
 */
function rcBranch()
{
    $refs = refs();
    if ($refs === null) {
        return null;
    }
    $best = '';
    foreach (array_keys($refs) as $ref) {
        if (!preg_match('#^refs/heads/rc-([0-9][0-9.]*)$#', $ref, $m)) {
            continue;
        }
        if ($best === '' || version_compare($m[1], $best, '>')) {
            $best = $m[1];
        }
    }

    return $best === '' ? '' : 'rc-' . $best;
}

/**
 * The version a dev-branch install reports.
 *
 * Both branches carry 1.6 since the 1.6.0 release (2026-11-02). 1.6 keeps
 * FOG_VERSION in src/Base/System.php; 1.5 kept it in lib/fog/system.class.php,
 * which 1.6 deleted. Read the old path only, and the fetch 404s, cached()
 * keeps the last 1.5 value, and every 1.6 install is told it is out of date.
 *
 * Like beta, a 1.6 branch commits only the release (1.6.0); the build number a
 * dev-branch server reports is `git rev-list master..HEAD --count`, recovered
 * here as the compare API's ahead_by.
 *
 * @return string the version, or '' when GitHub did not answer
 */
function devVersion()
{
    $branch = 'dev-branch';
    $version = sourceVersion($branch, 'packages/web/src/Base/System.php');
    if ($version === '') {
        return sourceVersion($branch, 'packages/web/lib/fog/system.class.php');
    }
    if (!preg_match('/^([0-9]+\.[0-9]+\.[0-9]+)(\.[0-9]+)?$/', $version, $m)) {
        return $version;
    }
    $compare = json_decode(
        httpGet(REPO_API . '/compare/' . headRef('master') . '...' . headRef($branch) . '?per_page=1&page=2'),
        true
    );
    if (!isset($compare['ahead_by'])) {
        return '';
    }
    return $m[1] . '.' . $compare['ahead_by'];
}

/**
 * The version a stable install reports: the newest release.
 *
 * A stable checkout sits on a release, and fogproject's fog-version.sh
 * reports that release tag -- as it stands when four-part (1.6.0.N), with
 * .0 appended when three-part (1.6.0 is build 0). A release tag never
 * carries a '-', which leaves out every 1.6.0-RC-N.
 *
 * @return string the version, or '' when GitHub did not answer
 */
function stableVersion()
{
    $releases = json_decode(httpGet(REPO_API . '/releases?per_page=100'), true);
    if (!is_array($releases)) {
        return '';
    }
    $best = '';
    foreach ($releases as $release) {
        $tag = isset($release['tag_name']) ? $release['tag_name'] : '';
        if (!empty($release['draft']) || !empty($release['prerelease'])
            || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?$/', $tag)
        ) {
            continue;
        }
        if ($best === '' || version_compare($tag, $best, '>')) {
            $best = $tag;
        }
    }
    if ($best === '') {
        return '';
    }
    return substr_count($best, '.') === 2 ? $best . '.0' : $best;
}

// Keyed by the request parameter each one answers to. 'alpha' is the beta
// branch: the parameter predates the branch being called beta and is a public
// contract with anything already calling this endpoint, so it stays.
//
// Each answer is keyed on the refs it is computed from, so the API is asked
// only after one of those refs moves (or CACHE_MAX_AGE passes). README.md,
// "API budget", has the numbers.
$rcBranch = rcBranch();
$versions = [
    'stable' => cached('stable-version.txt', tagsKey(), function (&$key) {
        $version = stableVersion();
        // A tag can be pushed before its release is published. Until the
        // releases API shows the newest tag, keep re-asking every CACHE_TTL
        // instead of pinning the older answer to the new tag list.
        if ($version !== highestReleaseTag()) {
            $key = null;
        }
        return $version;
    }),
    'dev' => cached('dev-branch-version.txt', headsKey('master', 'dev-branch'), 'devVersion'),
    'alpha' => cached('betabranch-version.txt', headsKey('master', 'working-1.6'), 'betaVersion'),
    // No rc branch between releases is an answer, not a failure: report none,
    // rather than the cached value of an rc branch that no longer exists.
    'rc' => $rcBranch === '' ? '' : cached(
        'rc-version.txt',
        $rcBranch === null ? null : headsKey($rcBranch),
        function () use ($rcBranch) {
            return $rcBranch ? sourceVersion($rcBranch, 'packages/web/src/Base/System.php') : '';
        }
    ),
];

// JSON: any combination of ?stable, ?dev, ?alpha, ?rc returns just those.
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
    'rc' => 'release-candidate',
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
        // No answer and no cache yet: say nothing rather than print a blank
        // version. Most likely for rc, between releases.
        if ($version === '') {
            continue;
        }
        echo '<p>Latest ' . $labels[$key] . ' version is ' . $version . '</p>';
    }
} else {
    echo "<b>Your version of FOG is up to date.</b><br/>";
    echo "You're running the latest " . $labels[$running] . ' version: ' . $versions[$running];
}
