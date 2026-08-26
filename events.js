/* Upcoming Events — self-hosted listing (replaces the eventscalendar.co widget).
   Deliberately isolated from script.js: a failure in here must never take down
   the menu, lightbox, ambient audio or background video. */
(function () {
  'use strict';

  /* ---- Config ------------------------------------------------------------ */
  const ENDPOINT    = '/api/events.php';
  const SHOW_IMAGES = false;   // variant switch: true renders the Eventbrite artwork
  const MAX_EVENTS  = 8;       // dates shown before the "show all" expander; 0 = all
  const ORGANISER   = 'https://glasgowsoundbath.eventbrite.com';
  /* ---- Where did this visitor come from? --------------------------------
     Eventbrite records whatever we put in ?aff=<code> against the ticket, so
     this code is how a sale gets traced back to a post, an ad or a search.
     No registration needed: an unknown code is stored exactly like a known one.

     Two signals, in order of trust:
       1. utm_* on our own URL  — we control the link, so this is reliable.
       2. document.referrer     — a fallback; Instagram's in-app browser often
                                  sends nothing, which lands as w-direct.

     ⚠️ CLOSED VOCABULARY, on purpose. Never interpolate a raw utm_source into
     the code: Eventbrite's affiliate space is unbounded and PERMANENT — there
     is no way to delete a code once a ticket carries it. Anything unrecognised
     becomes w-other, and adding a real code is a deliberate edit here.

     Computed once at load, not per click: index.html navigates by #anchor only
     and there is no pushState anywhere, so location.search survives the whole
     visit. If the site ever gains client-side routing, move this into withAff.

     No cookies and no storage, so there is nothing to consent to. */
  const AFF_FALLBACK = 'website';   // pre-2026-08-23 behaviour; also the panic value

  function resolveAff() {
    try {
      const q      = new URLSearchParams(location.search || '');
      const source = (q.get('utm_source')   || '').trim().toLowerCase();
      const medium = (q.get('utm_medium')   || '').trim().toLowerCase();
      const camp   = (q.get('utm_campaign') || '').trim().toLowerCase();
      const paid   = /paid|cpc|ppc/.test(medium);

      /* ⚠️ Meta writes utm_source={{site_source_name}}, which expands to `ig`,
         `fb`, `an` (Audience Network) or `msg` — NOT `instagram`. Measured in
         the raw access logs 2026-08-24: over one month, utm_source=ig appeared
         1,034 times against 16 for `instagram`, so matching only the long form
         sent ~98% of paid Instagram traffic to w-other. Normalise first. */
      const META_IG = ['instagram', 'ig'];
      const META_FB = ['facebook', 'fb', 'an', 'msg'];

      if (META_IG.indexOf(source) !== -1) {
        if (camp.indexOf('link-in-bio') === 0) return 'w-ig-bio';   // prefix: tolerates the old link-in-bio/ typo
        return paid ? 'w-ig-paid' : 'w-ig';
      }
      if (META_FB.indexOf(source) !== -1) return paid ? 'w-fb-paid' : 'w-fb';
      if (source === 'flyer')    return 'w-flyer';
      if (source)                return 'w-other';                  // tagged, but not a code we know

      /* Untagged. Fall back to whoever linked here. */
      let host = '';
      try { host = new URL(document.referrer).hostname.toLowerCase(); } catch (e) { host = ''; }

      if (!host || /(^|\.)glasgowsoundbath\.com$/.test(host))      return 'w-direct';
      if (/(^|\.)instagram\.com$/.test(host))                      return 'w-ig-ref';
      if (/(^|\.)facebook\.com$/.test(host))                       return 'w-fb-ref';
      if (/(^|\.)google\./.test(host))                             return 'w-google';
      if (/(^|\.)(bing|duckduckgo|ecosia|yahoo)\./.test(host))     return 'w-search';
      return 'w-ref';
    } catch (e) {
      return AFF_FALLBACK;   // never let attribution break the listing
    }
  }

  const AFF_CODE = resolveAff();

  /* The campaign this visit belongs to, for the BEACON ONLY.
     ⚠️ Deliberately NOT part of the aff code. Eventbrite affiliate codes are
     permanent and undeletable, so putting a campaign id in one would accrue a
     new permanent code every campaign, forever. The beacon goes to our own
     access log, where nothing is permanent and nothing is polluted. */
  const CAMPAIGN = (function () {
    try {
      const c = new URLSearchParams(location.search || '').get('utm_campaign') || '';
      return c.trim().replace(/[^A-Za-z0-9._-]/g, '').slice(0, 64);
    } catch (e) { return ''; }
  })();

  const wrap = document.getElementById('eventsWrap');
  if (!wrap) return;

  /* ---- Small DOM helpers ------------------------------------------------- */
  const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;   // textContent, never innerHTML
    return n;
  };

  const SVG_NS = 'http://www.w3.org/2000/svg';
  const ICONS = {
    calendar: 'M3 4h14v13H3zM3 8h14M7 2v4M13 2v4',
    clock:    'M10 3a7 7 0 100 14 7 7 0 000-14zM10 6v4l2.5 2',
    pin:      'M10 18s6-5.2 6-9.4A6 6 0 004 8.6C4 12.8 10 18 10 18zM10 6.6a2 2 0 110 4 2 2 0 010-4z',
    ticket:   'M2 7.5a1.5 1.5 0 000 3V14h16v-3.5a1.5 1.5 0 010-3V6H2zM7 6v8',
    external: 'M11 3h6v6M17 3l-7 7M15 12v4a1 1 0 01-1 1H4a1 1 0 01-1-1V6a1 1 0 011-1h4',
    chevron:  'M5 8l5 5 5-5'
  };
  const icon = (name) => {
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', '0 0 20 20');
    svg.setAttribute('class', 'event-icon');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    const p = document.createElementNS(SVG_NS, 'path');
    p.setAttribute('d', ICONS[name]);
    svg.appendChild(p);
    return svg;
  };

  /* Click beacon. The request itself IS the record: it lands in the server's
     access log, which the log parser reads. No redirect, so the visitor still
     goes straight to Eventbrite via a plain <a href>.

     ⚠️ FIRE AND FORGET. Never await it, never branch on it, and NEVER call
     preventDefault() on the link to "make sure" it arrives — that is exactly how
     analytics breaks checkout flows. If this is blocked, throws, or the endpoint
     is deleted, the click must still work. Links are target="_blank" so the page
     does not unload and there is no delivery race to lose. */
  const ping = (which, eventId) => {
    try {
      // .php is required: the host does not rewrite extensionless paths, and
      // /api/c 404s (which still logs, but returns a 1.2KB error body per click).
      const url = '/api/c.php?e=' + encodeURIComponent(eventId || '')
                + '&s=' + encodeURIComponent(AFF_CODE)
                + '&w=' + encodeURIComponent(which)
                + (CAMPAIGN ? '&c=' + encodeURIComponent(CAMPAIGN) : '');
      if (navigator.sendBeacon) navigator.sendBeacon(url);
      else new Image().src = url;
    } catch (e) { /* counting must never cost a booking */ }
  };

  /* Tag an Eventbrite URL with the tracking code, respecting any query string
     the URL already carries. */
  const withAff = (url) => {
    if (!url || !AFF_CODE) return url;
    return url + (url.indexOf('?') === -1 ? '?' : '&') + 'aff=' + encodeURIComponent(AFF_CODE);
  };

  /* ---- Dates ------------------------------------------------------------- */
  /* Always format in the EVENT's timezone, never the browser's. Two reasons:
     a visitor abroad must still see Glasgow time, and the UK clock change means
     18:00Z in October and 19:00Z in November are both 7:00pm locally. */
  const part = (iso, tz, opts) =>
    new Intl.DateTimeFormat('en-GB', Object.assign({ timeZone: tz }, opts)).format(new Date(iso));

  const fullDate = (iso, tz) =>
    part(iso, tz, { weekday: 'long' }) + ', ' +
    part(iso, tz, { day: 'numeric', month: 'long', year: 'numeric' });

  /* "3:00 pm" / "3:00 PM" / narrow-nbsp variants all collapse to "3:00pm" */
  const clock = (iso, tz) =>
    part(iso, tz, { hour: 'numeric', minute: '2-digit', hour12: true })
      .replace(/[\s  ]/g, '')
      .toLowerCase();

  const timeRange = (ev) => {
    const start = clock(ev.start_time, ev.timezone);
    if (!ev.end_time || ev.end_time === ev.start_time) return start;
    return start + '–' + clock(ev.end_time, ev.timezone);
  };

  /* ---- Event card -------------------------------------------------------- */
  function card(ev) {
    const li = el('li', 'event-item');

    const chip = el('div', 'event-chip');
    chip.appendChild(el('span', 'event-chip-day', part(ev.start_time, ev.timezone, { day: 'numeric' })));
    /* slice(0,3) keeps the chip a uniform width — en-GB returns "Sept" for September */
    chip.appendChild(el('span', 'event-chip-mon',
      part(ev.start_time, ev.timezone, { month: 'short' }).toUpperCase().slice(0, 3)));
    li.appendChild(chip);

    /* Artwork is a sibling of the body, not a child, so .event-item can lay the
       row out as chip | thumbnail | text. Eventbrite serves these at 450x200 with
       a signed URL, so they cannot be requested larger — keep them thumbnail-sized
       rather than upscaling into blur. */
    if (SHOW_IMAGES && ev.image) {
      const fig = el('div', 'event-figure');
      const img = el('img', 'event-img');
      img.src = ev.image;
      img.alt = '';                 // decorative; the title carries the meaning
      img.loading = 'lazy';
      img.decoding = 'async';
      img.width = 450;
      img.height = 200;
      fig.appendChild(img);
      li.appendChild(fig);
    }

    const body = el('div', 'event-body');

    const h3 = el('h3', 'event-title');
    const titleLink = el('a', null, ev.title);
    titleLink.href = withAff(ev.event_link);
    titleLink.target = '_blank';
    titleLink.rel = 'noopener';
    titleLink.addEventListener('click', () => ping('title', ev.id));
    h3.appendChild(titleLink);
    body.appendChild(h3);

    const meta = el('ul', 'event-meta');
    const row = (name, text) => {
      const item = el('li');
      item.appendChild(icon(name));
      item.appendChild(el('span', null, text));
      meta.appendChild(item);
    };
    const linkRow = (name, text, href) => {
      const item = el('li', 'event-meta-link');
      item.appendChild(icon(name));
      const a = el('a', null, text);
      a.href = href;
      a.target = '_blank';
      a.rel = 'noopener';
      a.addEventListener('click', () => ping(name, ev.id));
      item.appendChild(a);
      meta.appendChild(item);
    };

    row('calendar', fullDate(ev.start_time, ev.timezone));
    row('clock', timeRange(ev));
    if (ev.location) row('pin', ev.location);
    /* Buy Tickets goes straight to the checkout for this specific date;
       More Information goes to the full event page. Note that the checkout
       endpoint can return "Request Header Fields Too Large" for a browser
       carrying a heavy Eventbrite session (e.g. logged in as the organiser);
       first-time visitors are unaffected. Swap to withAff(ev.event_link) if
       that ever shows up for real visitors. */
    linkRow('ticket', 'Buy Tickets', withAff(ev.tickets_link || ev.event_link));
    linkRow('external', 'More Information', withAff(ev.event_link));
    body.appendChild(meta);

    /* Expandable detail — only when there is something to show */
    const detailText = [ev.description, ev.location_address].filter(Boolean);
    if (detailText.length) {
      const panel = el('div', 'event-desc');
      panel.hidden = true;
      if (ev.description) panel.appendChild(el('p', null, ev.description));
      if (ev.location_address) panel.appendChild(el('p', 'event-address', ev.location_address));

      const toggle = el('button', 'event-toggle');
      toggle.type = 'button';
      toggle.setAttribute('aria-expanded', 'false');
      toggle.appendChild(icon('chevron'));
      const toggleLabel = el('span', null, 'Show more');
      toggle.appendChild(toggleLabel);
      toggle.addEventListener('click', () => {
        const open = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', String(!open));
        toggleLabel.textContent = open ? 'Show more' : 'Show less';
        panel.hidden = open;
      });

      body.appendChild(toggle);
      body.appendChild(panel);
    }

    li.appendChild(body);
    return li;
  }

  /* ---- States ------------------------------------------------------------ */
  function ebLink(text) {
    const a = el('a', 'events-cta', text);
    /* Tagged like every other route out. This is the link a visitor takes when
       the listing has failed — precisely the visit we would otherwise lose. */
    a.href = withAff(ORGANISER);
    a.target = '_blank';
    a.rel = 'noopener';
    return a;
  }

  function replaceWrap(node) {
    while (wrap.firstChild) wrap.removeChild(wrap.firstChild);
    wrap.appendChild(node);
    wrap.setAttribute('aria-busy', 'false');
  }

  /* Success is the ONLY path that sets data-ready. Everything else leaves a
     working route to Eventbrite on the page. */
  function succeed(list) {
    replaceWrap(list);
    wrap.dataset.ready = '1';
    startBackgroundVideo();
  }

  /* Kicked once the listing is settled, on either path — the server-rendered
     page is already settled at first paint, so it calls this directly. */
  function startBackgroundVideo() {
    const bg = document.getElementById('bg-video');
    if (bg) {
      bg.preload = 'auto';
      // play() returns a promise; a try/catch does not catch its rejection,
      // which is what produced "Uncaught (in promise) AbortError" in the
      // console. If it is refused, script.js handles the poster fallback.
      const p = bg.play();
      if (p && typeof p.catch === 'function') p.catch(() => {});
    }
  }

  function degrade(message) {
    const box = el('div', 'events-fallback');
    box.appendChild(el('p', null, message));
    box.appendChild(ebLink('See all dates and book on Eventbrite'));
    replaceWrap(box);
  }

  /* ---- Enhancement of server-rendered markup ----------------------------
     index.php renders the listing into the page (see api/events-render.php),
     which is what LLMs and non-rendering crawlers read. When it has, there is
     nothing to fetch — only the behaviour that needs a browser: the aff code,
     the click beacons, and the two expanders.

     ⚠️ The markup here and in api/events-render.php are a pair. The renderer
     emits data-ping on each link and data-event-id on each .event-item, and
     this reads them back. Change one without the other and attribution stops.
     ------------------------------------------------------------------- */

  /* Every Eventbrite link on the page, not just the ones in the listing.
     The "open the listings on Eventbrite" hint, the static fallback CTA and the
     organiser-page note were all untagged until 2026-08-26, so a visitor who
     took one of those routes bought a ticket we could not attribute — it landed
     under ebdsoporgprofile as though they had found Eventbrite by themselves. */
  function tagEventbriteLinks(root) {
    if (!AFF_CODE) return;
    const links = root.querySelectorAll('a[href*="eventbrite."]');
    for (let i = 0; i < links.length; i++) {
      const a = links[i];
      try {
        if (a.href.indexOf('aff=') === -1) a.href = withAff(a.href);
      } catch (e) { /* one bad href must not stop the rest */ }
    }
  }

  function enhanceServerMarkup() {
    const items = wrap.querySelectorAll('.event-item');

    for (let i = 0; i < items.length; i++) {
      const item = items[i];
      const id = item.getAttribute('data-event-id') || '';

      const links = item.querySelectorAll('a[data-ping]');
      for (let j = 0; j < links.length; j++) {
        const a = links[j];
        a.addEventListener('click', () => ping(a.getAttribute('data-ping'), id));
      }

      /* The toggle ships hidden because a button that cannot work is worse than
         no button; unhiding it is this script saying it can work. */
      const toggle = item.querySelector('.event-toggle');
      const panel  = item.querySelector('.event-desc');
      if (toggle && panel) {
        toggle.hidden = false;
        const label = toggle.querySelector('span');
        toggle.addEventListener('click', () => {
          const open = toggle.getAttribute('aria-expanded') === 'true';
          toggle.setAttribute('aria-expanded', String(!open));
          if (label) label.textContent = open ? 'Show more' : 'Show less';
          panel.hidden = open;
        });
      }
    }

    const showAll = wrap.querySelector('.events-showall');
    if (showAll) {
      showAll.hidden = false;
      const hiddenItems = [];
      for (let i = 0; i < items.length; i++) {
        if (items[i].hidden) hiddenItems.push(items[i]);
      }
      const total = items.length;
      showAll.addEventListener('click', () => {
        const open = showAll.getAttribute('aria-expanded') === 'true';
        for (let i = 0; i < hiddenItems.length; i++) hiddenItems[i].hidden = open;
        showAll.setAttribute('aria-expanded', String(!open));
        showAll.textContent = open ? 'Show all ' + total + ' dates' : 'Show fewer dates';
        if (open) wrap.scrollIntoView({ block: 'start', behavior: 'smooth' });
      });
    }

    startBackgroundVideo();
  }

  /* ---- Client-side render (test_site/, and any page without the server one) */

  /* ⚠️ Reports WHY, not just that it broke. Until 2026-08-26 every failure —
     a 502, malformed JSON, a bug in card() — arrived identically as `w=error`,
     so two real failures in the logs could not be told apart or diagnosed.
     api/logs.php keeps digits and hyphens in `w` so "http502" survives. */
  function reportFailure(reason) {
    try { new Image().src = '/api/c.php?e=&s=listing-failed&w=' + encodeURIComponent(reason); } catch (e) {}
  }

  function fetchAndRender() {
    let stage = 'network';

    fetch(ENDPOINT, { credentials: 'omit' })
      .then((r) => {
        if (!r.ok) { stage = 'http' + r.status; throw new Error('HTTP ' + r.status); }
        stage = 'parse';
        return r.json();
      })
      .then((data) => {
        stage = 'render';
        const all = (data && Array.isArray(data.events)) ? data.events : [];
        if (!all.length) {
          degrade('No dates are listed right now. New sessions are announced on Eventbrite.');
          return;
        }

        const list = el('ul', 'event-list' + (SHOW_IMAGES ? ' event-list--images' : ''));
        /* ⚠️ Per event, deliberately. An event whose start_time or timezone the
           API left empty makes Intl throw RangeError, and one unguarded throw
           here used to abandon the whole listing — all 33 dates replaced by an
           error message. One bad event must cost one date. api/events-lib.php
           now drops such events server-side too; this is the second line. */
        let rendered = 0;
        all.forEach((ev) => {
          try { list.appendChild(card(ev)); rendered++; }
          catch (e) { if (window.console && console.warn) console.warn('[events] skipped', e); }
        });
        if (!rendered) {
          degrade('Upcoming dates could not be loaded just now.');
          reportFailure('render');
          return;
        }

        tagEventbriteLinks(list);

        /* Everything is rendered up front, so expanding costs no extra request.
           Beyond MAX_EVENTS the items are simply hidden until asked for. */
        const hiddenCount = MAX_EVENTS > 0 ? rendered - MAX_EVENTS : 0;
        if (hiddenCount > 0) {
          const items = list.children;
          for (let i = MAX_EVENTS; i < items.length; i++) items[i].hidden = true;

          const wrapper = el('div', 'events-result');
          wrapper.appendChild(list);

          const showAll = el('button', 'events-showall');
          showAll.type = 'button';
          showAll.setAttribute('aria-expanded', 'false');
          showAll.textContent = 'Show all ' + rendered + ' dates';
          showAll.addEventListener('click', () => {
            const open = showAll.getAttribute('aria-expanded') === 'true';
            for (let i = MAX_EVENTS; i < items.length; i++) items[i].hidden = open;
            showAll.setAttribute('aria-expanded', String(!open));
            showAll.textContent = open
              ? 'Show all ' + rendered + ' dates'
              : 'Show fewer dates';
            if (open) wrapper.scrollIntoView({ block: 'start', behavior: 'smooth' });
          });
          wrapper.appendChild(showAll);
          succeed(wrapper);
          return;
        }

        succeed(list);
      })
      .catch((err) => {
        /* Any failure — network, bad JSON, or a bug in the code above — ends
           here with a visible booking link rather than a spinner that never
           stops. Nothing in this block may throw: we are already in a catch. */
        if (window.console && console.warn) console.warn('[events]', err);
        degrade('Upcoming dates could not be loaded just now.');
        reportFailure(stage);
      });
  }

  /* ---- Go ---------------------------------------------------------------- */
  tagEventbriteLinks(document);

  if (wrap && wrap.getAttribute('data-server-rendered') === '1') {
    enhanceServerMarkup();
  } else {
    fetchAndRender();
  }
})();
