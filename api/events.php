<?php
/* Upcoming events for glasgowsoundbath.com, as JSON.
   A thin wrapper over api/events-lib.php, which does the fetching, caching and
   mapping. index.php uses that same library to render the events into the page
   itself — this endpoint exists for events.js and for anything else that wants
   the data rather than the markup.

   ⚠️ The response shape is {updated, events} and must stay that way: events.js
   and test_site/ both read it. Extra fields on an event are safe to add (the
   client ignores what it does not use); removing or renaming one is not. */

declare(strict_types=1);

require __DIR__ . '/events-lib.php';

header('Content-Type: application/json; charset=utf-8');
/* Deliberately not cached by the browser or the host's LiteSpeed page cache.
   The 15-minute file cache in the library already keeps Eventbrite requests
   rare, and an edge cache on top only delays corrections — an event pulled from
   sale, or one that should never have been listed, must disappear on the next
   request. */
header('Cache-Control: no-store, max-age=0');

$result = gsb_get_events();
header('X-Cache: ' . $result['cache']);

if (!$result['ok']) {
    http_response_code(502);
    echo json_encode(array(
        'updated' => $result['updated'],
        'events'  => array(),
        'error'   => $result['error'],
    ));
    exit;
}

echo json_encode(
    array('updated' => $result['updated'], 'events' => $result['events']),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
