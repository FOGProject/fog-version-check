<?php
/**
 * Self-test for the version endpoint. Run ON THE HOST, as the web user:
 *
 *     sudo -u nginx php /var/www/html/website/version/bin/diagnose.php
 *
 * Answers the only question that ever comes up here -- "the site is reporting
 * the wrong version, is that GitHub, the cache, or the deploy?" -- by checking
 * the three independently.
 *
 * Deliberately shares NO code with index.php. A diagnostic built on the thing
 * under test cannot tell you the thing under test is broken; running the same
 * curl by hand can.
 */

$root = dirname(__DIR__);
$fail = 0;

function head($t)
{
    echo "\n== $t ==\n";
}

function line($label, $ok, $detail)
{
    global $fail;
    if (!$ok) {
        $fail++;
    }
    printf("  [%s] %-28s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail);
}

function get($url)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'fogproject.org-version-check/diagnose',
    ]);
    $body = curl_exec($ch);
    $info = ['code' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'err' => curl_error($ch)];
    curl_close($ch);
    $info['body'] = is_string($body) ? $body : '';

    return $info;
}

head('Deploy');
$index = $root . '/index.php';
line(
    'index.php present',
    is_readable($index),
    is_readable($index) ? $index : 'MISSING'
);
if (is_readable($index)) {
    line('index.php modified', true, date('Y-m-d H:i:s', filemtime($index)));
    // The fingerprint that separates this file from the pre-repo one: the old
    // index.php had no CACHE_DIR and never called the compare API.
    $src = file_get_contents($index);
    line(
        'is the repo version',
        strpos($src, 'ahead_by') !== false,
        strpos($src, 'ahead_by') !== false ? 'yes' : 'NO -- an older index.php is on disk'
    );
}
// The classic "I replaced the file and nothing changed": php-fpm is serving a
// cached COMPILE of the previous file. Only a reload clears it.
if (function_exists('opcache_get_configuration')) {
    $cfg = opcache_get_configuration()['directives'];
    $stale = empty($cfg['opcache.validate_timestamps'])
        || (int) $cfg['opcache.revalidate_freq'] > 60;
    line(
        'opcache revalidation',
        !$stale,
        $stale
            ? sprintf(
                'validate_timestamps=%s revalidate_freq=%s -- RELOAD php-fpm after a pull',
                var_export($cfg['opcache.validate_timestamps'], true),
                $cfg['opcache.revalidate_freq']
            )
            : 'timestamps validated, a pull takes effect on its own'
    );
} else {
    line('opcache revalidation', true, 'opcache not loaded in this SAPI');
}

head('GitHub');
$raw = 'https://raw.githubusercontent.com/FOGProject/fogproject';
$sources = [
    'stable' => "$raw/stable/packages/web/lib/fog/system.class.php",
    'dev-branch' => "$raw/dev-branch/packages/web/lib/fog/system.class.php",
    'working-1.6' => "$raw/working-1.6/packages/web/src/Base/System.php",
];
foreach ($sources as $branch => $url) {
    $res = get($url);
    $ver = preg_match("/FOG_VERSION',\s*'([0-9A-Za-z.-]+)'/", $res['body'], $m) ? $m[1] : '';
    line(
        "source $branch",
        $ver !== '',
        $ver !== '' ? "HTTP {$res['code']} -> $ver" : "HTTP {$res['code']} {$res['err']}"
    );
}
// working-1.6 carries only the release fallback since GH-1513 made FOG_VERSION
// a generated file, so its build number has to be counted. See index.php.
$res = get('https://api.github.com/repos/FOGProject/fogproject/compare/master...working-1.6?per_page=1&page=2');
$cmp = json_decode($res['body'], true);
line(
    'compare API ahead_by',
    isset($cmp['ahead_by']),
    isset($cmp['ahead_by'])
        ? "HTTP {$res['code']} -> {$cmp['ahead_by']} commits"
        : sprintf(
            'HTTP %s %s %s',
            $res['code'],
            $res['err'],
            isset($cmp['message']) ? '-- ' . $cmp['message'] : ''
        )
);
$rl = json_decode(get('https://api.github.com/rate_limit')['body'], true);
if (isset($rl['resources']['core'])) {
    $core = $rl['resources']['core'];
    line(
        'API rate limit',
        $core['remaining'] > 0,
        "{$core['remaining']}/{$core['limit']} left, resets " . date('H:i:s', $core['reset'])
    );
}

head('Cache');
$dir = $root . '/cache';
line('cache/ writable', is_writable($dir), $dir);
foreach (['stable-version.txt', 'dev-branch-version.txt', 'betabranch-version.txt'] as $name) {
    $path = "$dir/$name";
    if (!file_exists($path)) {
        line($name, true, 'absent -- will be fetched on the next request');
        continue;
    }
    $age = time() - filemtime($path);
    line(
        $name,
        true,
        sprintf('%-18s %ds old%s', trim(file_get_contents($path)), $age, $age > 300 ? ' (stale, refreshes next request)' : '')
    );
}

head($fail ? "$fail check(s) FAILED" : 'All checks passed');
echo "\nTo force a refresh, delete the cache and hit the endpoint:\n";
echo "  rm -f $dir/*.txt && curl -s 'https://fogproject.org/version/index.php?stable&dev&alpha'\n\n";

exit($fail ? 1 : 0);
