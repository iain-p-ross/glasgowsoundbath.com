<?php
/* Upcoming events for glasgowsoundbath.com.
   Proxies the Eventbrite API so the private token stays on the server, and
   caches the result so ordinary traffic never hits Eventbrite directly. */

declare(strict_types=1);

const CACHE_TTL   = 900;   // 15 minutes
const HTTP_TIMEOUT = 8;
const MAX_PAGES    = 5;    // 50 events per page; a guard against looping forever

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

$cacheFile = sys_get_temp_dir() . '/gsb_events_cache_v2.json';

/* ---- Fresh cache: nothing else to do ---------------------------------- */
if (is_readable($cacheFile) && (time() - (int)filemtime($cacheFile)) < CACHE_TTL) {
    header('X-Cache: hit');
    readfile($cacheFile);
    exit;
}

/* ---- Serve whatever we have if we cannot do better -------------------- */
function serveStaleOrFail(string $cacheFile, string $why): void
{
    if (is_readable($cacheFile)) {
        // A listing a few hours old is far better than no listing at all.
        header('X-Cache: stale');
        readfile($cacheFile);
        exit;
    }
    http_response_code(502);
    echo json_encode(['updated' => time(), 'events' => [], 'error' => $why]);
    exit;
}

$configFile = __DIR__ . '/config.php';
if (!is_readable($configFile)) {
    serveStaleOrFail($cacheFile, 'not configured');
}
$config = require $configFile;
if (empty($config['token']) || empty($config['org_id'])) {
    serveStaleOrFail($cacheFile, 'not configured');
}

/* ---- Fetch ------------------------------------------------------------ */
$events   = [];
$page     = 1;
$continue = null;

do {
    $query = [
        'status'      => 'live',
        'order_by'    => 'start_asc',
        'time_filter' => 'current_future',
        'expand'      => 'venue,logo',
    ];
    if ($continue !== null) {
        $query['continuation'] = $continue;
    }

    $url = 'https://www.eventbriteapi.com/v3/organizations/'
         . rawurlencode((string)$config['org_id']) . '/events/?'
         . http_build_query($query);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $config['token']],
    ]);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $status !== 200) {
        serveStaleOrFail($cacheFile, 'upstream ' . $status);
    }

    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['events'])) {
        serveStaleOrFail($cacheFile, 'bad upstream payload');
    }

    foreach ($data['events'] as $e) {
        /* status=live does NOT exclude private events. An unlisted event is one
           the organiser shares by direct link only, so it must never appear in
           a public listing. Skip anything not publicly listed or invite-only. */
        if (empty($e['listed']) || !empty($e['invite_only'])) {
            continue;
        }

        $id    = isset($e['id']) ? (string)$e['id'] : '';
        $venue = $e['venue'] ?? null;

        // Only mapped fields are ever echoed — the raw payload (and the token)
        // never reach the browser.
        $events[] = [
            'id'               => $id,
            'title'            => $e['name']['text']  ?? '',
            'description'      => $e['summary']       ?? ($e['description']['text'] ?? ''),
            'location'         => $venue['name']      ?? '',
            'location_address' => $venue['address']['localized_address_display'] ?? '',
            'image'            => $e['logo']['url']   ?? '',
            'start_time'       => $e['start']['utc']  ?? '',
            'end_time'         => $e['end']['utc']    ?? '',
            'timezone'         => $e['start']['timezone'] ?? 'Europe/London',
            'event_link'       => $e['url']           ?? '',
            'tickets_link'     => $id !== ''
                ? 'https://www.eventbrite.com/tickets-external?eid=' . $id
                : ($e['url'] ?? ''),
        ];
    }

    $continue = ($data['pagination']['has_more_items'] ?? false)
        ? ($data['pagination']['continuation'] ?? null)
        : null;
    $page++;
} while ($continue !== null && $page <= MAX_PAGES);

/* ---- Cache and emit --------------------------------------------------- */
$payload = json_encode(
    ['updated' => time(), 'events' => $events],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

// Write via a temp file so a concurrent reader never sees a half-written cache.
$tmp = $cacheFile . '.' . getmypid() . '.tmp';
if (@file_put_contents($tmp, $payload) !== false) {
    @rename($tmp, $cacheFile);
} else {
    @unlink($tmp);
}

header('X-Cache: miss');
echo $payload;
