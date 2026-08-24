<?php
/**
 * logprobe.php — READ-ONLY diagnostic. Answers one question: can we read the
 * raw server access logs from PHP, and what is in them?
 *
 * AWStats strips query strings, so it can never report a UTM campaign. The raw
 * access log does contain them, plus status codes (so redirects are visible)
 * and user agents (so we can filter bots ourselves rather than trust AWStats).
 * If this probe finds a readable log, that is a better data source than AWStats
 * for everything we want, and it needs no tracking code on the site at all.
 *
 * SECURITY
 *  - Gated on a token in api/config.php, which is git-ignored and excluded from
 *    the FTP deploy. Without `probe_token` set, this endpoint refuses to run.
 *  - Every sample line has its IP address REDACTED before output. Raw logs are
 *    personal data; nothing here should ever put an IP in a browser or a sheet.
 *  - Reads only. Opens nothing for writing, changes nothing.
 *
 * DELETE THIS FILE once the question is answered. It exposes server paths.
 *
 *   /api/logprobe.php?token=...
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'No api/config.php on the server.']);
    exit;
}
$config = require $configFile;
if (!is_array($config)) {
    http_response_code(500);
    echo json_encode(['error' => 'config.php did not return an array.']);
    exit;
}

$expected = isset($config['probe_token']) ? (string)$config['probe_token'] : '';
$given    = isset($_GET['token']) ? (string)$_GET['token'] : '';
if ($expected === '' ) {
    http_response_code(403);
    echo json_encode(['error' => "Set 'probe_token' in api/config.php first."]);
    exit;
}
if (!hash_equals($expected, $given)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

/* ---------- helpers ---------- */

/** Replace anything that looks like an IP so none ever reaches the output. */
function redact($line) {
    $line = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '<ip>', $line);
    $line = preg_replace('/\b[0-9a-fA-F:]{6,}:[0-9a-fA-F:]*\b/', '<ip6>', $line);
    // mbstring is not guaranteed on shared hosting.
    return function_exists('mb_substr') ? mb_substr($line, 0, 400) : substr($line, 0, 400);
}

function firstAndLast($path, $isGz) {
    $out = ['first' => null, 'last' => null, 'lines_sampled' => 0];
    $fh = $isGz ? @gzopen($path, 'rb') : @fopen($path, 'rb');
    if (!$fh) return $out;

    $n = 0; $last = null; $first = null;
    // Cap the read: a busy log can be very large and this is only a shape check.
    while ($n < 200000) {
        $line = $isGz ? gzgets($fh) : fgets($fh);
        if ($line === false) break;
        $line = rtrim($line, "\r\n");
        if ($line === '') continue;
        if ($first === null) $first = $line;
        $last = $line;
        $n++;
    }
    $isGz ? gzclose($fh) : fclose($fh);

    $out['first'] = $first === null ? null : redact($first);
    $out['last']  = $last  === null ? null : redact($last);
    $out['lines_sampled'] = $n;
    return $out;
}

function describe($path) {
    $info = ['path' => $path, 'exists' => false];
    if (!@file_exists($path)) return $info;
    $info['exists']   = true;
    $info['is_dir']   = @is_dir($path);
    $info['readable'] = @is_readable($path);
    if (!$info['readable']) return $info;

    if ($info['is_dir']) {
        $names = @scandir($path);
        if ($names === false) { $info['listing_error'] = true; return $info; }
        $names = array_values(array_filter($names, function ($n) { return $n !== '.' && $n !== '..'; }));
        sort($names);
        $info['entry_count'] = count($names);
        $info['entries'] = array_slice($names, 0, 40);   // enough to identify, not a dump
        return $info;
    }

    $info['size_bytes'] = @filesize($path);
    $info['modified']   = @date('c', @filemtime($path));
    $isGz = substr($path, -3) === '.gz';
    $info['gzipped'] = $isGz;
    $info = array_merge($info, firstAndLast($path, $isGz));
    return $info;
}

/* ---------- what to look at ---------- */

$home   = '/home/iainhwqg';
$domain = 'glasgowsoundbath.com';

$candidates = [
    // cPanel's usual symlink, and the per-domain files inside it
    "$home/access-logs",
    "$home/access-logs/$domain",
    "$home/access-logs/$domain-ssl_log",
    "$home/access-logs/$domain-ssl_log.1",
    "$home/logs",
    "$home/logs/$domain",
    // LiteSpeed / Apache system locations
    "/usr/local/apache/domlogs/iainhwqg",
    "/usr/local/apache/domlogs/iainhwqg/$domain",
    "/usr/local/apache/domlogs/$domain",
    "/usr/local/lsws/logs",
    // Known-good control: the AWStats dir the stats dashboard already reads
    "$home/tmp/awstats/ssl",
    // Home listing, to discover anything the guesses above miss
    $home,
];

$results = [];
foreach ($candidates as $p) {
    $results[] = describe($p);
}

echo json_encode([
    'ok' => true,
    'note' => 'READ-ONLY. IPs are redacted before output. Delete this file when done.',
    'php' => [
        'version'           => PHP_VERSION,
        'open_basedir'      => ini_get('open_basedir') ?: null,   // the usual reason reads fail
        'disable_functions' => ini_get('disable_functions') ?: null,
        'gzopen_available'  => function_exists('gzopen'),
        'script_user'       => function_exists('posix_getpwuid') && function_exists('posix_geteuid')
            ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : null,
    ],
    'candidates' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
