<?php
/**
 * c.php — click beacon. Deliberately does nothing.
 *
 * events.js fires navigator.sendBeacon('/api/c?e=<eventId>&s=<affCode>&w=<which>')
 * when someone clicks through to Eventbrite. The POINT is the request itself:
 * the server's access log records it, query string and all, and the log parser
 * reads it back. Nothing needs to be written here — the log IS the datastore.
 *
 * WHY THIS IS NOT A REDIRECT
 * The visitor still goes straight to Eventbrite via a plain <a href>. Nothing is
 * intercepted, so there is no open-redirect surface, no extra hop, and no way
 * for this file to delay or break a booking. If it 404s, is blocked, or is
 * deleted, the beacon fails silently and the click still works.
 *
 * WHY THE NAME IS BORING
 * /track, /pixel, /beacon, /collect and /analytics are all on standard ad-block
 * filter lists. `c` is not. It is first-party either way, which blockers treat
 * far more leniently, but there is no reason to invite the match.
 *
 * NO CONFIG, NO DEPENDENCIES, ON PURPOSE. It must not be possible for a bad
 * edit to api/config.php to take this down — that already happened once and
 * briefly took the events feed with it.
 *
 * PRIVACY: writes nothing, sets no cookie, reads nothing from the device. The
 * access line it produces contains an IP that was already logged milliseconds
 * earlier for the pageview. The parser drops it before anything reaches a sheet.
 */

http_response_code(204);
