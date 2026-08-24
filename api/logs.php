<?php
/**
 * logs.php — turns the raw server access log into daily aggregates.
 *
 * WHY THIS EXISTS
 * AWStats strips the query string, so it can never report a campaign. The raw
 * access log keeps it, along with the status code, referrer and user agent. That
 * makes it a better source than AWStats for everything we want, and it needs no
 * tracking code on the site: the server writes it whether we read it or not.
 *
 * It also carries the click beacons fired by events.js at /api/c.php, so
 * arrivals AND clicks come out of one file.
 *
 * WHAT IT RETURNS
 *   arrivals[]  date x source -> visits, pageviews
 *   clicks[]    date x source x event_id x which -> clicks
 * Aggregates only. One row per day per source, not 25,000 log lines.
 *
 * PRIVACY, and these are not optional
 *  - The IP is used ONLY to group requests into visits, via a hash with a salt
 *    that is regenerated every run. It is never returned and cannot be reversed
 *    across runs.
 *  - fbclid is dropped. It is Meta's click identifier and could link back to a
 *    person; the campaign id is the part with analytic value.
 *  - Nothing visitor-level ever leaves this file. Same discipline as buyer_hash
 *    in EB Orders Raw.
 *
 * ⚠️ THE SERVER CLOCK IS -0400, NOT UK. Every timestamp is converted to
 * Europe/London before the day is taken. Without that, evening visits land on
 * the previous day -- the same defect as the Sales by day BST bug, which was
 * wrong for seven months of every year before anyone noticed.
 *
 *   /api/logs.php?token=...            live log only
 *   /api/logs.php?token=...&archives=1 include the rotated .gz files
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo json_encode(['ok' => false, 'fatal' => $e['message'], 'line' => $e['line']]);
    }
});
@set_time_limit(60);

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'No api/config.php.']);
    exit;
}
$config = require $configFile;
if (!is_array($config)) {
    http_response_code(500);
    echo json_encode(['error' => 'config.php did not return an array.']);
    exit;
}
$expected = isset($config['logs_token']) ? (string)$config['logs_token'] : '';
$given    = isset($_GET['token']) ? (string)$_GET['token'] : '';
if ($expected === '' || !hash_equals($expected, $given)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

const LOG_LIVE   = '/home/iainhwqg/access-logs/glasgowsoundbath.com.iainross.net-ssl_log';
const LOG_ARCH   = '/home/iainhwqg/logs';
const ARCH_MATCH = 'glasgowsoundbath.com.iainross.net-ssl_log-';
const VISIT_GAP  = 1800;   // seconds; a new request from the same visitor after
                           // this long counts as a new visit

/** Per-run salt: the IP hash cannot be correlated between two calls. */
$SALT = bin2hex(random_bytes(16));

/**
 * Source vocabulary. MUST mirror resolveAff() in events.js, or arrivals will not
 * join to the clicks and sales that carry the aff code.
 *
 * ⚠️ Meta writes utm_source={{site_source_name}} -> ig / fb / an / msg, NEVER
 * "instagram". Measured in these very logs: ig 1,034 vs instagram 16.
 */
function classify($query, $referrer) {
    $p = [];
    if ($query !== '') parse_str($query, $p);

    $src = isset($p['utm_source']) ? strtolower(trim($p['utm_source'])) : '';
    $med = isset($p['utm_medium']) ? strtolower(trim($p['utm_medium'])) : '';
    $cam = isset($p['utm_campaign']) ? strtolower(trim($p['utm_campaign'])) : '';
    $paid = (bool)preg_match('/paid|cpc|ppc/', $med);

    if (in_array($src, ['instagram', 'ig'], true)) {
        if (strpos($cam, 'link-in-bio') === 0) return 'w-ig-bio';
        return $paid ? 'w-ig-paid' : 'w-ig';
    }
    if (in_array($src, ['facebook', 'fb', 'an', 'msg'], true)) return $paid ? 'w-fb-paid' : 'w-fb';
    if ($src === 'flyer') return 'w-flyer';
    if ($src !== '') return 'w-other';

    $host = $referrer !== '' && $referrer !== '-' ? (string)parse_url($referrer, PHP_URL_HOST) : '';
    $host = strtolower($host);
    if ($host === '' || strpos($host, 'glasgowsoundbath') !== false) return 'w-direct';
    if (preg_match('/(^|\.)instagram\.com$/', $host)) return 'w-ig-ref';
    if (preg_match('/(^|\.)facebook\.com$/', $host)) return 'w-fb-ref';
    if (preg_match('/(^|\.)google\./', $host))       return 'w-google';
    if (preg_match('/(^|\.)(bing|duckduckgo|ecosia|yahoo|brave)\./', $host)) return 'w-search';
    return 'w-ref';
}

/**
 * The campaign this visit belongs to.
 *
 * Meta expands {{campaign.id}} into utm_campaign automatically, so paid traffic
 * carries an 18-digit id that joins to `Meta Ads Raw`.`Campaign ID` in the
 * sheet. Manual links carry a human label instead (link-in-bio, print), which is
 * equally useful and lives in the same column.
 *
 * ⚠️ utm_id and utm_campaign are NOT the same value on this account — sampled
 * together they differ, so utm_id is something else (probably the adset). Only
 * utm_campaign is taken, and the sheet reports how many campaigns actually match
 * Meta Ads Raw so a wrong assumption here shows up as a number rather than as
 * silence.
 */
function campaignOf($query) {
    if ($query === '') return '';
    $p = [];
    parse_str($query, $p);
    $c = isset($p['utm_campaign']) ? trim((string)$p['utm_campaign']) : '';
    // Same charset the aff codes use; anything else is not a campaign we can join on.
    $c = preg_replace('/[^A-Za-z0-9._-]/', '', $c);
    return substr($c, 0, 64);
}

/** Assets and API calls are not arrivals. */
function isPageRequest($path) {
    if (strpos($path, '/api/') === 0) return false;
    if (preg_match('/\.(css|js|mjs|map|jpe?g|png|gif|webp|svg|ico|mp4|webm|woff2?|ttf|xml|txt|json|pdf)$/i', $path)) return false;
    return true;
}

/**
 * File selection.
 *
 *   (default)          the live log only. Small and fast; this is what the daily
 *                      trigger uses.
 *   &archives=1        live log plus every rotated .gz. ⚠️ Can exceed
 *                      UrlFetchApp's ~60s ceiling once there is more than a
 *                      month or two of history — it returns fine to curl and
 *                      throws to Apps Script, which is a confusing failure.
 *   &file=<name>       ONE archive by filename. Resumable: loop over
 *                      &list=1 and pull them one at a time. Prefer this for a
 *                      backfill.
 *   &list=1            just name the archives available, read nothing.
 */
$archiveNames = [];
if (is_dir(LOG_ARCH) && is_readable(LOG_ARCH)) {
    foreach ((array)scandir(LOG_ARCH) as $n) {
        if (strpos($n, ARCH_MATCH) === 0 && substr($n, -3) === '.gz') $archiveNames[] = $n;
    }
    sort($archiveNames);
}

if (!empty($_GET['list'])) {
    echo json_encode(['ok' => true, 'live' => LOG_LIVE, 'archives' => $archiveNames]);
    exit;
}

$files = [];
if (isset($_GET['file'])) {
    $want = basename((string)$_GET['file']);          // basename: no path escape
    if (!in_array($want, $archiveNames, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown archive.', 'archives' => $archiveNames]);
        exit;
    }
    $files[] = LOG_ARCH . '/' . $want;
} else {
    $files[] = LOG_LIVE;
    if (!empty($_GET['archives'])) {
        foreach ($archiveNames as $n) $files[] = LOG_ARCH . '/' . $n;
    }
}

$arrivals = [];   // "date|source|campaign" => [visits, pageviews]
$clicks   = [];   // "date|source|campaign|event|which" => n
$lastSeen = [];   // visitor hash => last request time, for the visit window

$linesRead = 0; $bots = 0; $unparsed = 0; $beacons = 0;
$minDay = null; $maxDay = null; $truncated = false;
$deadline = time() + 25;   // well inside UrlFetchApp's ~60s ceiling
$tzLondon = new DateTimeZone('Europe/London');

$re = '/^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) (\S+) [^"]*" (\d{3}) \S+ "([^"]*)" "([^"]*)"/';

foreach ($files as $file) {
    if (!@is_readable($file)) continue;
    $isGz = substr($file, -3) === '.gz';
    $fh = $isGz ? @gzopen($file, 'rb') : @fopen($file, 'rb');
    if (!$fh) continue;

    while (($line = $isGz ? gzgets($fh) : fgets($fh)) !== false) {
        if ((++$linesRead % 2000) === 0 && time() > $deadline) { $truncated = true; break; }

        if (!preg_match($re, $line, $m)) { $unparsed++; continue; }
        list(, $ip, $ts, $method, $target, $status, $referrer, $agent) = $m;

        if (preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|scan/i', $agent)) { $bots++; continue; }

        $when = DateTime::createFromFormat('d/M/Y:H:i:s O', $ts);
        if (!$when) { $unparsed++; continue; }
        $epoch = $when->getTimestamp();
        $when->setTimezone($tzLondon);              // ⚠️ the -0400 correction
        $day = $when->format('Y-m-d');
        if ($minDay === null || $day < $minDay) $minDay = $day;
        if ($maxDay === null || $day > $maxDay) $maxDay = $day;

        $q = '';
        $path = $target;
        if (($qp = strpos($target, '?')) !== false) {
            $path = substr($target, 0, $qp);
            $q    = substr($target, $qp + 1);
        }

        /* ---- click beacons ---- */
        if ($path === '/api/c.php') {
            $p = []; parse_str($q, $p);
            $src   = isset($p['s']) ? preg_replace('/[^a-z0-9-]/i', '', (string)$p['s']) : '';
            $event = isset($p['e']) ? preg_replace('/[^0-9]/', '', (string)$p['e']) : '';
            $which = isset($p['w']) ? preg_replace('/[^a-z]/i', '', (string)$p['w']) : '';
            $camp  = isset($p['c']) ? preg_replace('/[^A-Za-z0-9._-]/', '', (string)$p['c']) : '';
            if ($src !== '') {
                $k = $day . '|' . $src . '|' . $camp . '|' . $event . '|' . $which;
                $clicks[$k] = ($clicks[$k] ?? 0) + 1;
                $beacons++;
            }
            continue;
        }

        /* ---- arrivals ---- */
        if ($method !== 'GET' || !in_array($status, ['200', '304'], true)) continue;
        if (!isPageRequest($path)) continue;

        // fbclid is Meta's click identifier: drop it before it is looked at.
        if ($q !== '') $q = preg_replace('/(^|&)fbclid=[^&]*/', '', $q);

        $source = classify($q, $referrer);
        $campaign = campaignOf($q);
        $key = $day . '|' . $source . '|' . $campaign;
        if (!isset($arrivals[$key])) $arrivals[$key] = ['visits' => 0, 'pageviews' => 0];
        $arrivals[$key]['pageviews']++;

        // Visit = same visitor, same source, within VISIT_GAP. The hash exists
        // only inside this request and is never returned.
        $vh = hash('sha256', $GLOBALS['SALT'] . $ip . $agent . $source . $campaign);
        if (!isset($lastSeen[$vh]) || ($epoch - $lastSeen[$vh]) > VISIT_GAP) {
            $arrivals[$key]['visits']++;
        }
        $lastSeen[$vh] = $epoch;
    }
    $isGz ? gzclose($fh) : fclose($fh);
    if ($truncated) break;
}

$outArrivals = [];
foreach ($arrivals as $k => $v) {
    list($d, $s, $c) = array_pad(explode('|', $k, 3), 3, '');
    $outArrivals[] = ['date' => $d, 'source' => $s, 'campaign' => $c,
                      'visits' => $v['visits'], 'pageviews' => $v['pageviews']];
}
usort($outArrivals, function ($a, $b) {
    return $a['date'] === $b['date'] ? strcmp($a['source'], $b['source']) : strcmp($a['date'], $b['date']);
});

$outClicks = [];
foreach ($clicks as $k => $n) {
    list($d, $s, $c, $e, $w) = array_pad(explode('|', $k, 5), 5, '');
    $outClicks[] = ['date' => $d, 'source' => $s, 'campaign' => $c,
                    'event_id' => $e, 'which' => $w, 'clicks' => $n];
}
usort($outClicks, function ($a, $b) {
    return $a['date'] === $b['date'] ? strcmp($a['source'], $b['source']) : strcmp($a['date'], $b['date']);
});

echo json_encode([
    'ok' => true,
    'files_read'     => count($files),
    'lines_read'     => $linesRead,
    'bots_skipped'   => $bots,
    'unparsed_lines' => $unparsed,
    'beacon_hits'    => $beacons,
    'truncated'      => $truncated,
    'day_range'      => [$minDay, $maxDay],
    'timezone'       => 'Europe/London (log is -0400)',
    'visit_gap_secs' => VISIT_GAP,
    'arrivals'       => $outArrivals,
    'clicks'         => $outClicks,
], JSON_UNESCAPED_SLASHES);
