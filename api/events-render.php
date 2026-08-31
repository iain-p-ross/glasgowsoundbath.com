<?php
/**
 * events-render.php — events to page HTML and to schema.org JSON-LD.
 *
 * WHY THIS EXISTS
 * Until 2026-08-26 the listing was built entirely by events.js, so the page
 * source carried no dates at all. Googlebot renders JavaScript and could see
 * them eventually; GPTBot, ClaudeBot and PerplexityBot do not and could not.
 * Asked about Glasgow soundbaths, an LLM reading this site found only
 * "Upcoming dates are listed on Eventbrite".
 *
 * ⚠️ THE MARKUP HERE MUST MATCH card() IN events.js. Both render the same
 * classes, which events.css styles, and events.js attaches its click beacons by
 * finding this markup. Change one without the other and either the styling or
 * the attribution breaks. The client path still exists for test_site/, which
 * has no server rendering.
 *
 * ⚠️ NO aff CODE IS WRITTEN HERE, deliberately. resolveAff() needs
 * document.referrer, which only the browser has, and api/logs.php's classify()
 * already has to mirror it — a third implementation in PHP would be a third
 * thing to drift. events.js tags every Eventbrite link on the page after load.
 *
 * PHP 7.2. No PHP 8 syntax.
 */

declare(strict_types=1);

/* Dates shown before the "show all" expander. Matches MAX_EVENTS in events.js. */
const GSB_MAX_VISIBLE = 8;

const GSB_ORGANISER_URL = 'https://glasgowsoundbath.eventbrite.com';
const GSB_SITE_URL      = 'https://www.glasgowsoundbath.com/';

/* Every date is led by the same person — "sound artist and musician Iain Ross",
   as the About section on index.php puts it — so the schema.org performer is a
   constant here, not a mapped field. Eventbrite has no performer concept to map
   it from. */
const GSB_PERFORMER_NAME = 'Iain Ross';

function gsb_esc($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* ---- Dates -------------------------------------------------------------
   Always format in the EVENT's timezone, never the server's. A visitor abroad
   must still see Glasgow time, and the UK clock change means 18:00Z in October
   and 19:00Z in November are both 7:00pm locally.

   These formats mirror the Intl.DateTimeFormat('en-GB') calls in events.js:
     j       -> "29"                       (chip day)
     M       -> "Aug"                      (chip month, uppercased)
     l, j F Y-> "Saturday, 29 August 2026" (full date)
     g:ia    -> "2:00pm"                   (clock)
   ---------------------------------------------------------------------- */
function gsb_local($iso, $tz)
{
    /* ⚠️ new DateTime('') is NOT an error in PHP — it returns the current time.
       An event whose start never made it through would therefore render as
       today, silently and plausibly, which is worse than not rendering at all.
       gsb_map_event() already drops those; this is the second line, and it
       throws so the per-event catch can skip the event. */
    if (!is_string($iso) || trim($iso) === '' || strtotime($iso) === false) {
        throw new RuntimeException('unusable date');
    }
    $d = new DateTime($iso);
    $d->setTimezone(new DateTimeZone($tz));
    return $d;
}

function gsb_time_range($ev)
{
    $start = gsb_local($ev['start_time'], $ev['timezone'])->format('g:ia');
    if (empty($ev['end_time']) || $ev['end_time'] === $ev['start_time']) {
        return $start;
    }
    return $start . '–' . gsb_local($ev['end_time'], $ev['timezone'])->format('g:ia');
}

/* ---- HTML --------------------------------------------------------------- */

/**
 * The whole <ul>, or '' if nothing could be rendered.
 *
 * ⚠️ Every event is rendered inside its own try/catch. One malformed event must
 * cost one date, never the listing — the absence of exactly this guard on the
 * client side is what let a single bad start_time blank all 33 dates.
 */
function gsb_render_event_list($events)
{
    $items = array();
    foreach ($events as $ev) {
        try {
            /* ⚠️ The cut is the RENDERED position, not the position in $events.
               Keyed off the input index, every event skipped above would pull
               the fold one earlier and show fewer than GSB_MAX_VISIBLE dates —
               silently, and only ever on the days something was already wrong. */
            $items[] = gsb_render_event_item($ev, count($items) >= GSB_MAX_VISIBLE);
        } catch (\Throwable $e) {   // Error as well as Exception: a TypeError
            continue;                // in one event must not cost the other 32
        }
    }
    if (!count($items)) {
        return '';
    }

    $html = '<ul class="event-list">' . implode('', $items) . '</ul>';

    /* The expander is hidden until events.js unhides and wires it: a button that
       cannot work is worse than no button. The overflow dates stay in the source
       either way, which is what crawlers and LLMs read. */
    if (count($items) > GSB_MAX_VISIBLE) {
        $html .= '<button type="button" class="events-showall" aria-expanded="false" hidden>'
               . 'Show all ' . count($items) . ' dates</button>';
        $html  = '<div class="events-result">' . $html . '</div>';
    }
    return $html;
}

function gsb_render_event_item($ev, $overflow)
{
    $start = gsb_local($ev['start_time'], $ev['timezone']);

    $h  = '<li class="event-item"' . ($overflow ? ' hidden' : '')
        . ' data-event-id="' . gsb_esc($ev['id']) . '">';

    $h .= '<div class="event-chip">'
        . '<span class="event-chip-day">' . gsb_esc($start->format('j')) . '</span>'
        /* en-GB gives "Sept" for September and events.js slices it to 3 to keep
           the chip a uniform width; PHP's M is already 3. */
        . '<span class="event-chip-mon">' . gsb_esc(strtoupper($start->format('M'))) . '</span>'
        . '</div>';

    $h .= '<div class="event-body">';

    $h .= '<h3 class="event-title">'
        . '<a href="' . gsb_esc($ev['event_link']) . '" target="_blank" rel="noopener" data-ping="title">'
        . gsb_esc($ev['title']) . '</a></h3>';

    $h .= '<ul class="event-meta">';
    $h .= gsb_meta_row('calendar', $start->format('l, j F Y'));
    $h .= gsb_meta_row('clock', gsb_time_range($ev));
    if (!empty($ev['location'])) {
        $h .= gsb_meta_row('pin', $ev['location']);
    }

    $tickets = !empty($ev['tickets_link']) ? $ev['tickets_link'] : $ev['event_link'];
    if (!empty($ev['sold_out'])) {
        /* ⚠️ A sold-out event MUST still link to the checkout. The waitlist is
           joined through the normal checkout flow — verified: the
           tickets-external page for a sold-out event is full of waitlist
           markup — and a waitlist is the ONLY measurement of demand above
           capacity there is, since Eventbrite exposes no waitlist endpoint at
           all (403/404, see the sheet project's CLAUDE.md). Dropping the link
           to avoid a "dead end" would have thrown that away.

           The label still says sold out, so nobody clicks expecting a ticket,
           and the beacon still fires — so waitlist interest is now measurable
           for the first time. */
        $label = !empty($ev['waitlist']) ? 'Sold out — Join Waiting List' : 'Sold out';
        $h .= gsb_meta_link('ticket', $label, $tickets);
    } else {
        $h .= gsb_meta_link('ticket', 'Buy Tickets', $tickets);
    }
    $h .= gsb_meta_link('external', 'More Information', $ev['event_link']);
    $h .= '</ul>';

    $detail = array();
    if (!empty($ev['description'])) {
        $detail[] = '<p>' . gsb_esc($ev['description']) . '</p>';
    }
    if (!empty($ev['location_address'])) {
        $detail[] = '<p class="event-address">' . gsb_esc($ev['location_address']) . '</p>';
    }
    if (count($detail)) {
        /* Hidden, like the client render, but present in the source — which is
           the point of the whole exercise. */
        $h .= '<button type="button" class="event-toggle" aria-expanded="false" hidden>'
            . gsb_icon('chevron') . '<span>Show more</span></button>'
            . '<div class="event-desc" hidden>' . implode('', $detail) . '</div>';
    }

    $h .= '</div></li>';
    return $h;
}

function gsb_meta_row($icon, $text)
{
    return '<li>' . gsb_icon($icon) . '<span>' . gsb_esc($text) . '</span></li>';
}

function gsb_meta_link($icon, $text, $href)
{
    return '<li class="event-meta-link">' . gsb_icon($icon)
         . '<a href="' . gsb_esc($href) . '" target="_blank" rel="noopener" data-ping="' . gsb_esc($icon) . '">'
         . gsb_esc($text) . '</a></li>';
}

/** Same paths as ICONS in events.js. */
function gsb_icon($name)
{
    $paths = array(
        'calendar' => 'M3 4h14v13H3zM3 8h14M7 2v4M13 2v4',
        'clock'    => 'M10 3a7 7 0 100 14 7 7 0 000-14zM10 6v4l2.5 2',
        'pin'      => 'M10 18s6-5.2 6-9.4A6 6 0 004 8.6C4 12.8 10 18 10 18zM10 6.6a2 2 0 110 4 2 2 0 010-4z',
        'ticket'   => 'M2 7.5a1.5 1.5 0 000 3V14h16v-3.5a1.5 1.5 0 010-3V6H2zM7 6v8',
        'external' => 'M11 3h6v6M17 3l-7 7M15 12v4a1 1 0 01-1 1H4a1 1 0 01-1-1V6a1 1 0 011-1h4',
        'chevron'  => 'M5 8l5 5 5-5',
    );
    if (!isset($paths[$name])) {
        return '';
    }
    return '<svg viewBox="0 0 20 20" class="event-icon" aria-hidden="true" focusable="false">'
         . '<path d="' . $paths[$name] . '"></path></svg>';
}

/* ---- JSON-LD ------------------------------------------------------------ */

/**
 * A schema.org @graph of Event objects — what earns a Google event rich result
 * and what an LLM reads in preference to prose.
 *
 * ⚠️ startDate MUST carry the local offset (+01:00 in BST), not Z. Google reads
 * a bare UTC instant as UTC and will show 6:30pm for a 7:30pm summer event —
 * the same class of error as the Sales by day BST bug in the sheet project.
 */
function gsb_events_jsonld($events)
{
    $nodes = array();
    foreach ($events as $ev) {
        try {
            $nodes[] = gsb_event_node($ev);
        } catch (\Throwable $e) {   // Error as well as Exception: a TypeError
            continue;          // in one event must not cost the other 32

        }
    }
    if (!count($nodes)) {
        return '';
    }

    $json = json_encode(
        array('@context' => 'https://schema.org', '@graph' => $nodes),
        /* ⚠️ JSON_HEX_TAG is load-bearing: without it a "</script>" inside an
           event title would close this block and inject markup. */
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    if ($json === false) {
        return '';
    }
    return '<script type="application/ld+json">' . $json . '</script>';
}

function gsb_event_node($ev)
{
    $node = array(
        '@type'                => 'Event',
        'name'                 => $ev['title'],
        'startDate'            => gsb_local($ev['start_time'], $ev['timezone'])->format('c'),
        'eventStatus'          => 'https://schema.org/EventScheduled',
        'eventAttendanceMode'  => 'https://schema.org/OfflineEventAttendanceMode',
        'url'                  => $ev['event_link'],
        'organizer'            => array(
            '@type' => 'Organization',
            'name'  => 'Glasgow Soundbath',
            'url'   => GSB_SITE_URL,
        ),
        /* ⚠️ organizer is NOT performer, and Google wants both. Search Console
           flagged 'Missing field "performer"' on 2026-08-31 — non-critical, so
           the rich result still showed, but it is one of the fields that can be
           reclassified as critical. */
        'performer'            => array(
            '@type' => 'Person',
            'name'  => GSB_PERFORMER_NAME,
            'url'   => GSB_SITE_URL,
        ),
    );

    if (!empty($ev['end_time'])) {
        $node['endDate'] = gsb_local($ev['end_time'], $ev['timezone'])->format('c');
    }
    if (!empty($ev['description'])) {
        $node['description'] = $ev['description'];
    }
    if (!empty($ev['image'])) {
        $node['image'] = $ev['image'];
    }

    if (!empty($ev['location'])) {
        $place = array('@type' => 'Place', 'name' => $ev['location']);
        if (!empty($ev['location_address'])) {
            $place['address'] = $ev['location_address'];
        }
        $node['location'] = $place;
    }

    $offer = gsb_offer_node($ev);
    if ($offer !== null) {
        $node['offers'] = $offer;
    }

    return $node;
}

/**
 * The sliding scale is three real prices for one experience, so it is an
 * AggregateOffer with a low and a high — not three competing Offers, and not a
 * single price that would misreport whichever tier it picked. With no price at
 * all it stays a bare Offer.
 *
 * ⚠️ validFrom is added to whichever of the three it turns out to be. Search
 * Console flagged 'Missing field "validFrom" (in "offers")' on 2026-08-31, and
 * it was missing from every one of them. Where the value comes from — and why
 * it is NOT start_sales_date alone — is gsb_sales_start() in events-lib.php.
 */
function gsb_offer_node($ev)
{
    $url = !empty($ev['tickets_link']) ? $ev['tickets_link'] : $ev['event_link'];
    if ($url === '') {
        return null;
    }

    /* @type is assigned first and only ever overwritten in place, so it stays
       the first key in the JSON however this ends up being shaped. */
    $offer = array(
        '@type'        => 'Offer',
        'url'          => $url,
        'availability' => !empty($ev['sold_out'])
            ? 'https://schema.org/SoldOut'
            : 'https://schema.org/InStock',
    );

    $min = isset($ev['price_min']) ? $ev['price_min'] : '';
    $max = isset($ev['price_max']) ? $ev['price_max'] : '';
    if ($min !== '' || $max !== '') {
        $offer['priceCurrency'] = !empty($ev['currency']) ? $ev['currency'] : 'GBP';
        if ($max === '' || $min === $max) {
            $offer['price'] = $min !== '' ? $min : $max;
        } else {
            $offer['@type']     = 'AggregateOffer';
            $offer['lowPrice']  = $min;
            $offer['highPrice'] = $max;
        }
    }

    /* ⚠️ A bad sales_start must cost validFrom, never the offer and never the
       event — the same rule as everywhere else in this file. gsb_local() throws
       on an unusable date rather than returning today, which is exactly what is
       wanted here: catch it and carry on without the field. Local offset, not
       Z, for the same reason startDate carries one. */
    if (!empty($ev['sales_start'])) {
        try {
            $offer['validFrom'] = gsb_local($ev['sales_start'], $ev['timezone'])->format('c');
        } catch (\Throwable $e) {
            // no validFrom; Search Console calls its absence non-critical
        }
    }

    return $offer;
}
