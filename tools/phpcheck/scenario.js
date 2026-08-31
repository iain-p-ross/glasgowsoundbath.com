#!/usr/bin/env node
/*
 * Render index.php once, under one scenario, and print the facts as JSON.
 * A child process of test.js — see the one-run-per-process warning in run.js.
 *
 * The Eventbrite fetch is never exercised: the cache file is seeded first, so
 * this needs no token and no network. ⚠️ The seed is ASSERTED, not hoped for.
 * Swallowing a failed seed turns a cache-hit test into a live-network test that
 * hangs, which is exactly what happened the first time this was written.
 */
const { loadNodeRuntime } = require('@php-wasm/node');
const { PHP } = require('@php-wasm/universal');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');
const mode = process.argv[2] || 'good';

/* Must match gsb_cache_file() in api/events-lib.php. Read it from the source so
   a version bump there cannot leave this silently seeding the wrong file. */
const CACHE_NAME = (function () {
  const src = fs.readFileSync(path.join(ROOT, 'api/events-lib.php'), 'utf8');
  const m = src.match(/sys_get_temp_dir\(\)\s*\.\s*'\/([^']+)'/);
  if (!m) throw new Error('cannot find the cache filename in api/events-lib.php');
  return m[1];
})();

const event = (i, start, extra) => Object.assign({
  id: '19908844870' + i,
  title: 'A Ritual of Return ' + i,
  description: 'A gentle return to stillness.',
  location: 'Hyndland Community Hall',
  location_address: '25 Fortrose Street, Glasgow',
  image: 'https://img.evbuc.com/x.jpg',
  start_time: start,
  end_time: '2026-09-20T20:30:00Z',
  timezone: 'Europe/London',
  event_link: 'https://www.eventbrite.co.uk/e/19908844870' + i,
  tickets_link: 'https://www.eventbrite.com/tickets-external?eid=19908844870' + i,
  price_min: '20.00', price_max: '30.00', currency: 'GBP',
  sold_out: false, waitlist: false,
  sales_start: '2026-06-01T09:00:00Z',
}, extra || {});

(async () => {
  const php = new PHP(await loadNodeRuntime('8.0', { emscriptenOptions: { processId: 1 } }));
  php.mkdir('/site'); php.mkdir('/site/api');

  const libs = ['index.php', 'api/events-lib.php', 'api/events-render.php'];
  for (const f of libs) {
    if (mode === 'missing-lib' && f === 'api/events-render.php') continue;
    php.writeFile('/site/' + f, fs.readFileSync(path.join(ROOT, f), 'utf8'));
  }
  if (mode === 'broken-lib') php.writeFile('/site/api/events-render.php', '<?php\nthis is not php at all(((');

  const events = [];
  for (let i = 0; i < 11; i++) events.push(event(i, '2026-09-2' + (i % 10) + 'T18:30:00Z'));
  if (mode === 'mixed') {
    events.splice(3, 0, event(90, ''));                                   // the event that blanked the live listing
    events.splice(6, 0, event(91, '2026-09-20T18:30:00Z', { timezone: 'Mars/Olympus' }));
    events[0].sales_start = 'not a date';   // must cost validFrom, not the event
  }
  if (mode === 'soldout') {
    events.push(event(92, '2026-12-05T19:30:00Z', { sold_out: true, waitlist: true }));
    events.push(event(93, '2026-12-12T19:30:00Z', { sold_out: true, waitlist: false }));
  }
  if (mode === 'empty') events.length = 0;

  if (mode !== 'broken-lib' && mode !== 'missing-lib') {
    if (!php.fileExists('/tmp')) php.mkdir('/tmp');
    php.writeFile('/tmp/' + CACHE_NAME, JSON.stringify({ updated: Math.floor(Date.now() / 1000), events }));
    const back = JSON.parse(php.readFileAsText('/tmp/' + CACHE_NAME));
    if (back.events.length !== events.length) throw new Error('cache seed did not land');
  }

  const r = await php.run({ scriptPath: '/site/index.php' });
  const html = new TextDecoder().decode(r.bytes);

  /* An .event-item CONTAINS <li> elements, so a non-greedy regex to </li> stops
     at the first nested one. Split on the boundary instead — that mistake
     produced five false negatives before it was caught. */
  const chunks = html.split('<li class="event-item"');
  const chunkFor = (id) => chunks.find((c) => c.includes('data-event-id="' + id + '"')) || '';

  /* Parse the graph once: null means there was no block, -1 worth of nodes
     means there was one and it was not valid JSON. */
  const graph = (() => {
    const m = html.match(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/);
    if (!m) return null;
    try { return JSON.parse(m[1])['@graph']; } catch (e) { return undefined; }
  })();
  const nodes = Array.isArray(graph) ? graph : [];
  const offerOf = (n) => (n && n.offers) || {};

  console.log(JSON.stringify({
    mode,
    exitCode: r.exitCode,
    seededEvents: events.length,
    eventItems: (html.match(/<li class="event-item"/g) || []).length,
    hiddenItems: (html.match(/<li class="event-item" hidden/g) || []).length,
    jsonLdBlocks: (html.match(/application\/ld\+json/g) || []).length,
    jsonLdNodes: graph === null ? 0 : (Array.isArray(graph) ? graph.length : -1),
    jsonLdStartDate: (() => {
      const m = html.match(/"startDate":"([^"]+)"/);
      return m ? m[1] : '';
    })(),
    /* Search Console, 2026-08-31: missing performer, missing offers.validFrom. */
    nodesWithPerformer: nodes.filter((n) => n.performer && n.performer.name === 'Iain Ross').length,
    nodesWithValidFrom: nodes.filter((n) => offerOf(n).validFrom).length,
    jsonLdValidFrom: nodes.length ? (offerOf(nodes[0]).validFrom || '') : '',
    offerTypes: [...new Set(nodes.map((n) => offerOf(n)['@type']).filter(Boolean))].sort(),
    serverRendered: html.includes('data-server-rendered="1"'),
    fallbackShown: html.includes('id="eventsFallback"'),
    doctypeFirst: html.trimStart().startsWith('<!DOCTYPE html>'),
    closesHtml: html.trimEnd().endsWith('</html>'),
    phpLeaked: html.includes('<?php') || html.includes('<?='),
    pageIntact: html.includes('id="gallery"') && html.includes('id="events"'),
    rawScriptClose: (html.match(/<script type="application\/ld\+json">[\s\S]*?<\/script>/) || [''])[0]
      .slice(30, -9).includes('</script>'),
    soldOutWaitlistLabel: chunkFor('1990884487092').includes('Sold out — Join Waiting List'),
    soldOutIsLink: /data-ping="ticket"[^>]*>Sold out/.test(chunkFor('1990884487092')),
    soldOutHref: chunkFor('1990884487092').includes('tickets-external?eid=1990884487092'),
    noWaitlistPlainLabel: chunkFor('1990884487093').includes('>Sold out</a>'),
    noWaitlistStillLink: chunkFor('1990884487093').includes('data-ping="ticket"'),
    buyTicketsPresent: html.includes('>Buy Tickets</a>'),
    fatals: (String(r.errors || '').match(/(Fatal|Parse error)[^\n]*/g) || []).slice(0, 2),
  }));

  /* ⚠️ Exit explicitly. The WASM runtime holds the event loop open, so without
     this the process prints its result and then hangs forever — and a parent
     using execFileSync times out on work that already finished. */
  process.exit(0);
})().catch((e) => { console.error('HARNESS ERROR:', e.message); process.exit(2); });
