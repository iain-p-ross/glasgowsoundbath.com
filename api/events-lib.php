<?php
/**
 * events-lib.php — the one place upcoming events are fetched, cached and mapped.
 *
 * Extracted from api/events.php on 2026-08-26 so that index.php can render the
 * same events into the page HTML. Before that, events existed ONLY after
 * events.js had run, which meant no crawler and no LLM could read a single date
 * off this site — the page source said "Upcoming dates are listed on Eventbrite"
 * and nothing more.
 *
 * ⚠️ THIS FILE MUST NOT PRINT, SET A HEADER, OR exit(). The old code called
 * exit() from inside its stale-cache path, which is exactly what made it
 * impossible to reuse: a homepage cannot have its data layer decide to end the
 * response. Callers get an array and choose what to do with it.
 *
 * PHP 7.2 (Namecheap shared hosting). No PHP 8 syntax.
 */

declare(strict_types=1);

const GSB_CACHE_TTL    = 900;   // 15 minutes
const GSB_HTTP_TIMEOUT = 8;
const GSB_MAX_PAGES    = 5;     // 50 events per page; a guard against looping forever

/* ⚠️ Bump this filename whenever the mapped shape changes. The cache holds the
   MAPPED payload, not the raw Eventbrite response, so an old file would be
   served happily with fields the new renderer expects and cannot find. v3 adds
   the price and sold-out fields; v4 added waitlist. */
function gsb_cache_file()
{
    return sys_get_temp_dir() . '/gsb_events_cache_v4.json';
}

/**
 * Upcoming events, from cache where possible.
 *
 * Returns:
 *   ok      bool    false only when there is no data at all, fresh or stale
 *   events  array   mapped events, soonest first (empty when !ok)
 *   updated int     unix time the data was built
 *   cache   string  hit | miss | stale | none — what X-Cache used to report
 *   error   string  '' unless something went wrong
 */
function gsb_get_events()
{
    $cacheFile = gsb_cache_file();

    if (is_readable($cacheFile) && (time() - (int)filemtime($cacheFile)) < GSB_CACHE_TTL) {
        $hit = gsb_read_cache($cacheFile, 'hit');
        if ($hit !== null) {
            return $hit;
        }
    }

    $configFile = __DIR__ . '/config.php';
    if (!is_readable($configFile)) {
        return gsb_stale_or_empty($cacheFile, 'not configured');
    }
    $config = require $configFile;
    if (empty($config['token']) || empty($config['org_id'])) {
        return gsb_stale_or_empty($cacheFile, 'not configured');
    }

    $events   = array();
    $page     = 1;
    $continue = null;

    do {
        $query = array(
            'status'      => 'live',
            'order_by'    => 'start_asc',
            'time_filter' => 'current_future',
            /* ticket_availability carries minimum/maximum_ticket_price,
               is_sold_out and waitlist_available. It is what lets the page say
               "Sold out" and what fills schema.org offers — without it Google
               will not show an event rich result.

               ⚠️ is_sold_out means "no ticket is purchasable right now", NOT
               "sold to capacity". Measured against this account: it is true on
               50 of 50 PAST events, including ones that closed at 60% fill,
               because an ended event sells nothing. Harmless here only because
               this endpoint asks for status=live and time_filter=current_future.
               Anyone reusing this field over history would compute a 100%
               sellout rate and believe it. */
            'expand'      => 'venue,logo,ticket_availability',
        );
        if ($continue !== null) {
            $query['continuation'] = $continue;
        }

        $url = 'https://www.eventbriteapi.com/v3/organizations/'
             . rawurlencode((string)$config['org_id']) . '/events/?'
             . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => GSB_HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $config['token']),
        ));
        $body   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status !== 200) {
            return gsb_stale_or_empty($cacheFile, 'upstream ' . $status);
        }

        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['events'])) {
            return gsb_stale_or_empty($cacheFile, 'bad upstream payload');
        }

        foreach ($data['events'] as $e) {
            $mapped = gsb_map_event($e);
            if ($mapped !== null) {
                $events[] = $mapped;
            }
        }

        $continue = (isset($data['pagination']['has_more_items']) && $data['pagination']['has_more_items'])
            ? (isset($data['pagination']['continuation']) ? $data['pagination']['continuation'] : null)
            : null;
        $page++;
    } while ($continue !== null && $page <= GSB_MAX_PAGES);

    $result = array(
        'ok'      => true,
        'events'  => $events,
        'updated' => time(),
        'cache'   => 'miss',
        'error'   => '',
    );

    gsb_write_cache($cacheFile, $result);
    return $result;
}

/**
 * One Eventbrite event to the shape the site renders, or null to skip it.
 *
 * ⚠️ An event with no usable start is DROPPED here rather than passed on. The
 * old code emitted `'start_time' => $e['start']['utc'] ?? ''`, and an empty
 * string reaches Intl/DateTime as an invalid date — in the browser that threw
 * RangeError out of the render loop and blanked ALL of the dates, which is what
 * fired the listing-failed beacon. One bad event must cost one event.
 */
function gsb_map_event($e)
{
    /* status=live does NOT exclude private events. An unlisted event is one the
       organiser shares by direct link only, so it must never appear in a public
       listing. Skip anything not publicly listed or invite-only. */
    if (empty($e['listed']) || !empty($e['invite_only'])) {
        return null;
    }

    $start = isset($e['start']['utc']) ? (string)$e['start']['utc'] : '';
    if ($start === '' || strtotime($start) === false) {
        return null;
    }

    $tz = isset($e['start']['timezone']) ? (string)$e['start']['timezone'] : 'Europe/London';
    if (!gsb_timezone_is_valid($tz)) {
        $tz = 'Europe/London';
    }

    $end = isset($e['end']['utc']) ? (string)$e['end']['utc'] : '';
    if ($end !== '' && strtotime($end) === false) {
        $end = '';
    }

    $id    = isset($e['id']) ? (string)$e['id'] : '';
    $venue = isset($e['venue']) ? $e['venue'] : null;
    $avail = isset($e['ticket_availability']) ? $e['ticket_availability'] : array();

    // Only mapped fields are ever echoed — the raw payload (and the token)
    // never reach the browser.
    return array(
        'id'               => $id,
        'title'            => isset($e['name']['text']) ? (string)$e['name']['text'] : '',
        'description'      => isset($e['summary']) ? (string)$e['summary']
                              : (isset($e['description']['text']) ? (string)$e['description']['text'] : ''),
        'location'         => isset($venue['name']) ? (string)$venue['name'] : '',
        'location_address' => isset($venue['address']['localized_address_display'])
                              ? (string)$venue['address']['localized_address_display'] : '',
        'image'            => isset($e['logo']['url']) ? (string)$e['logo']['url'] : '',
        'start_time'       => $start,
        'end_time'         => $end,
        'timezone'         => $tz,
        'event_link'       => isset($e['url']) ? (string)$e['url'] : '',
        'tickets_link'     => $id !== ''
                              ? 'https://www.eventbrite.com/tickets-external?eid=' . $id
                              : (isset($e['url']) ? (string)$e['url'] : ''),
        'price_min'        => gsb_major_price($avail, 'minimum_ticket_price'),
        'price_max'        => gsb_major_price($avail, 'maximum_ticket_price'),
        'currency'         => gsb_price_currency($avail),
        'sold_out'         => !empty($avail['is_sold_out']),
        'waitlist'         => !empty($avail['waitlist_available']),
    );
}

function gsb_major_price($avail, $key)
{
    if (isset($avail[$key]['major_value']) && $avail[$key]['major_value'] !== '') {
        return (string)$avail[$key]['major_value'];
    }
    return '';
}

function gsb_price_currency($avail)
{
    foreach (array('minimum_ticket_price', 'maximum_ticket_price') as $k) {
        if (isset($avail[$k]['currency']) && $avail[$k]['currency'] !== '') {
            return (string)$avail[$k]['currency'];
        }
    }
    return 'GBP';
}

function gsb_timezone_is_valid($tz)
{
    try {
        new DateTimeZone($tz);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/* ---- Cache ------------------------------------------------------------- */

function gsb_read_cache($cacheFile, $label)
{
    $raw = @file_get_contents($cacheFile);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['events']) || !is_array($data['events'])) {
        return null;
    }
    return array(
        'ok'      => true,
        'events'  => $data['events'],
        'updated' => isset($data['updated']) ? (int)$data['updated'] : (int)@filemtime($cacheFile),
        'cache'   => $label,
        'error'   => '',
    );
}

function gsb_write_cache($cacheFile, $result)
{
    $payload = json_encode(
        array('updated' => $result['updated'], 'events' => $result['events']),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    if ($payload === false) {
        return;
    }
    // Write via a temp file so a concurrent reader never sees a half-written cache.
    $tmp = $cacheFile . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $payload) !== false) {
        @rename($tmp, $cacheFile);
    } else {
        @unlink($tmp);
    }
}

/** A listing a few hours old is far better than no listing at all. */
function gsb_stale_or_empty($cacheFile, $why)
{
    if (is_readable($cacheFile)) {
        $stale = gsb_read_cache($cacheFile, 'stale');
        if ($stale !== null) {
            $stale['error'] = $why;
            return $stale;
        }
    }
    return array(
        'ok'      => false,
        'events'  => array(),
        'updated' => time(),
        'cache'   => 'none',
        'error'   => $why,
    );
}
