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
 * TWO MODES, because reading whole log files in one request blew the execution
 * limit and returned an empty 500:
 *   ?token=...              stat only. No file contents read at all. Cheap.
 *   ?token=...&sample=/path first ~5 and last ~5 lines of ONE file, by seeking
 *                           to the end rather than walking the whole thing.
 *
 * SECURITY
 *  - Gated on a token in api/config.php, which is git-ignored and excluded from
 *    the FTP deploy. Without `probe_token` set, this endpoint refuses to run.
 *  - `sample` is restricted to paths this file already knows about, so it can
 *    never be used to read an arbitrary file on the server.
 *  - Every sample line has its IP address REDACTED before output. Raw logs are
 *    personal data; nothing here should put an IP in a browser or a sheet.
 *  - Reads only. Opens nothing for writing, changes nothing.
 *
 * DELETE THIS FILE once the question is answered. It exposes server paths.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

// A fatal would otherwise return an empty 500 with no clue what happened.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo json_encode(['ok' => false, 'fatal' => $e['message'], 'line' => $e['line']]);
    }
});
@set_time_limit(20);

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
if ($expected === '') {
    http_response_code(403);
    echo json_encode(['error' => "Set 'probe_token' in api/config.php first."]);
    exit;
}
if (!hash_equals($expected, $given)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$home   = '/home/iainhwqg';
$domain = 'glasgowsoundbath.com';

/** Everything this probe is allowed to look at. `sample` cannot escape this. */
function candidates($home, $domain) {
    return [
        "$home/access-logs",
        "$home/access-logs/$domain",
        "$home/access-logs/$domain-ssl_log",
        "$home/logs",
        "$home/logs/$domain",
        "/usr/local/apache/domlogs/iainhwqg",
        "/usr/local/apache/domlogs/iainhwqg/$domain",
        "/usr/local/apache/domlogs/$domain",
        "$home/tmp/awstats/ssl",
        $home,
    ];
}

function redact($line) {
    // Positional, not regex: the IP is field 1 of the combined format. A regex
    // for IPv6 also matched the timestamp (2026:07:36:03), hiding the dates.
    $sp = strpos($line, ' ');
    if ($sp !== false) $line = '<ip>' . substr($line, $sp);
    return substr($line, 0, 320);
}

/* ---------- mode 2: sample one file, without walking it ---------- */

if (isset($_GET['sample'])) {
    $want = (string)$_GET['sample'];
    $allowed = candidates($home, $domain);

    // Also allow anything discovered inside an allowed directory, one level deep.
    foreach (candidates($home, $domain) as $dir) {
        if (@is_dir($dir) && @is_readable($dir)) {
            foreach ((array)@scandir($dir) as $n) {
                if ($n !== '.' && $n !== '..') $allowed[] = rtrim($dir, '/') . '/' . $n;
            }
        }
    }
    if (!in_array($want, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Path not in the probe allow-list.', 'path' => $want]);
        exit;
    }
    if (!@is_file($want) || !@is_readable($want)) {
        echo json_encode(['ok' => false, 'path' => $want, 'error' => 'not a readable file']);
        exit;
    }

    $isGz  = substr($want, -3) === '.gz';
    $first = [];
    $last  = [];

    if ($isGz) {
        $fh = @gzopen($want, 'rb');
        if ($fh) {
            $n = 0;
            while ($n < 5 && ($l = gzgets($fh)) !== false) { $first[] = redact(rtrim($l, "\r\n")); $n++; }
            gzclose($fh);
        }
    } else {
        $fh = @fopen($want, 'rb');
        if ($fh) {
            $n = 0;
            while ($n < 5 && ($l = fgets($fh)) !== false) { $first[] = redact(rtrim($l, "\r\n")); $n++; }
            // Tail by seeking, so file size does not matter.
            $size = @filesize($want);
            $back = min($size, 8192);
            if ($size > 0) {
                fseek($fh, -$back, SEEK_END);
                $tail = fread($fh, $back);
                $lines = array_values(array_filter(explode("\n", (string)$tail), 'strlen'));
                foreach (array_slice($lines, -5) as $l) $last[] = redact(rtrim($l, "\r"));
            }
            fclose($fh);
        }
    }

    echo json_encode([
        'ok' => true, 'path' => $want, 'gzipped' => $isGz,
        'size_bytes' => @filesize($want), 'modified' => @date('c', @filemtime($want)),
        'first_lines' => $first, 'last_lines' => $last,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ---------- mode 3: tally query params and referrers across the log ---------- */

if (isset($_GET['scan'])) {
    $want = (string)$_GET['scan'];
    $allowed = candidates($home, $domain);
    foreach (candidates($home, $domain) as $dir) {
        if (@is_dir($dir) && @is_readable($dir)) {
            foreach ((array)@scandir($dir) as $n) {
                if ($n !== '.' && $n !== '..') $allowed[] = rtrim($dir, '/') . '/' . $n;
            }
        }
    }
    if (!in_array($want, $allowed, true) || !@is_file($want) || !@is_readable($want)) {
        http_response_code(400);
        echo json_encode(['error' => 'Path not allowed or unreadable.']);
        exit;
    }

    $isGz = substr($want, -3) === '.gz';
    $fh = $isGz ? @gzopen($want, 'rb') : @fopen($want, 'rb');
    if (!$fh) { echo json_encode(['error' => 'could not open']); exit; }

    $tally = ['utm_source'=>[], 'utm_medium'=>[], 'utm_campaign'=>[], 'referrer_host'=>[], 'status'=>[]];
    $lines = 0; $withQuery = 0; $bots = 0; $firstDay = null; $lastDay = null;
    $deadline = time() + 12;

    while (($l = $isGz ? gzgets($fh) : fgets($fh)) !== false) {
        if (++$lines % 2000 === 0 && time() > $deadline) break;

        if (preg_match('#\[(\d{2}/[A-Za-z]{3}/\d{4})#', $l, $m)) {
            if ($firstDay === null) $firstDay = $m[1];
            $lastDay = $m[1];
        }
        if (preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview/i', $l)) { $bots++; continue; }

        // "GET /path?query HTTP/1.1" status size "referrer" "agent"
        if (!preg_match('#"(?:GET|POST|HEAD) ([^ ]+) HTTP/[0-9.]+" (\d{3}) \S+ "([^"]*)"#', $l, $m)) continue;
        $req = $m[1]; $status = $m[2]; $ref = $m[3];

        $tally['status'][$status] = ($tally['status'][$status] ?? 0) + 1;

        $q = strpos($req, '?');
        if ($q !== false) {
            $withQuery++;
            parse_str(substr($req, $q + 1), $params);
            foreach (['utm_source','utm_medium','utm_campaign'] as $k) {
                if (!empty($params[$k])) {
                    $v = substr((string)$params[$k], 0, 40);
                    $tally[$k][$v] = ($tally[$k][$v] ?? 0) + 1;
                }
            }
        }
        if ($ref !== '' && $ref !== '-') {
            $h = parse_url($ref, PHP_URL_HOST);
            if ($h && stripos($h, 'glasgowsoundbath') === false) {
                $tally['referrer_host'][$h] = ($tally['referrer_host'][$h] ?? 0) + 1;
            }
        }
    }
    $isGz ? gzclose($fh) : fclose($fh);

    foreach ($tally as $k => $v) { arsort($tally[$k]); $tally[$k] = array_slice($tally[$k], 0, 15, true); }

    echo json_encode([
        'ok' => true, 'path' => $want,
        'lines_read' => $lines, 'bot_lines_skipped' => $bots,
        'requests_with_query' => $withQuery,
        'day_range' => [$firstDay, $lastDay],
        'tally' => $tally,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ---------- mode 1: stat only, read no contents ---------- */

$results = [];
foreach (candidates($home, $domain) as $path) {
    $info = ['path' => $path, 'exists' => @file_exists($path)];
    if (!$info['exists']) { $results[] = $info; continue; }

    $info['readable'] = @is_readable($path);
    $info['is_dir']   = @is_dir($path);

    if ($info['is_dir']) {
        if ($info['readable']) {
            $names = @scandir($path);
            if (is_array($names)) {
                $names = array_values(array_filter($names, function ($n) { return $n !== '.' && $n !== '..'; }));
                sort($names);
                $info['entry_count'] = count($names);
                $info['entries'] = array_slice($names, 0, 40);
            }
        }
    } else {
        $info['size_bytes'] = @filesize($path);
        $info['modified']   = @date('c', @filemtime($path));
    }
    $results[] = $info;
}

echo json_encode([
    'ok' => true,
    'note' => 'Stat only. Add &sample=<path> to read a few lines from one file. IPs are redacted. Delete this file when done.',
    'php' => [
        'version'           => PHP_VERSION,
        'open_basedir'      => ini_get('open_basedir') ?: null,
        'disable_functions' => ini_get('disable_functions') ?: null,
        'gzopen_available'  => function_exists('gzopen'),
        'max_execution_time'=> ini_get('max_execution_time'),
    ],
    'candidates' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
