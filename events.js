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
  /* Eventbrite tracking link name. Must match a tracking link created on the
     event in Eventbrite, exactly, including capitalisation. An unrecognised
     value is harmless — Eventbrite serves the same page and simply does not
     attribute the visit. Set to '' to switch tracking off. */
  const AFF_CODE    = 'website';

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
    a.href = ORGANISER;
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
    const bg = document.getElementById('bg-video');
    if (bg) { bg.preload = 'auto'; try { bg.play(); } catch (_) {} }
  }

  function degrade(message) {
    const box = el('div', 'events-fallback');
    box.appendChild(el('p', null, message));
    box.appendChild(ebLink('See all dates and book on Eventbrite'));
    replaceWrap(box);
  }

  /* ---- Go ---------------------------------------------------------------- */
  fetch(ENDPOINT, { credentials: 'omit' })
    .then((r) => {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    })
    .then((data) => {
      const all = (data && Array.isArray(data.events)) ? data.events : [];
      if (!all.length) {
        degrade('No dates are listed right now. New sessions are announced on Eventbrite.');
        return;
      }
      const list = el('ul', 'event-list' + (SHOW_IMAGES ? ' event-list--images' : ''));
      all.forEach((ev) => list.appendChild(card(ev)));

      /* Everything is rendered up front, so expanding costs no extra request.
         Beyond MAX_EVENTS the items are simply hidden until asked for. */
      const hiddenCount = MAX_EVENTS > 0 ? all.length - MAX_EVENTS : 0;
      if (hiddenCount > 0) {
        const items = list.children;
        for (let i = MAX_EVENTS; i < items.length; i++) items[i].hidden = true;

        const wrapper = el('div', 'events-result');
        wrapper.appendChild(list);

        const showAll = el('button', 'events-showall');
        showAll.type = 'button';
        showAll.setAttribute('aria-expanded', 'false');
        showAll.textContent = 'Show all ' + all.length + ' dates';
        showAll.addEventListener('click', () => {
          const open = showAll.getAttribute('aria-expanded') === 'true';
          for (let i = MAX_EVENTS; i < items.length; i++) items[i].hidden = open;
          showAll.setAttribute('aria-expanded', String(!open));
          showAll.textContent = open
            ? 'Show all ' + all.length + ' dates'
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
      /* Any failure — network, bad JSON, or a bug in the code above — ends here
         with a visible booking link rather than a spinner that never stops. */
      if (window.console && console.warn) console.warn('[events]', err);
      degrade('Upcoming dates could not be loaded just now.');
      if (typeof window.plausible === 'function') {
        window.plausible('Events listing failed');
      }
    });
})();
