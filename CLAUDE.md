# CLAUDE.md

Guidance for Claude Code working in this repository.

## What this is

The public website for a Glasgow soundbath events business. Plain static HTML and
CSS plus a little PHP. **No framework, no build step, no `package.json`.**
Cache-busting is manual query strings (`events.js?v=202608241600`).

**Hosting:** Namecheap shared hosting (LiteSpeed + PHP 7.2), home directory
`/home/iainhwqg`. **Deploy:** push to `master` → GitHub Actions →
`SamKirkland/FTP-Deploy-Action`. It *syncs*, so a deleted file is deleted live.

⚠️ **`api/config.php` is excluded from the deploy** (and git-ignored), so a
deploy can never overwrite or delete it — but it also means it can only be
changed by hand on the server.

Excluded from deploy: `.git*`, `.DS_Store`, `.ftpquota`, `stats/**`,
`.well-known/**`, `api/config.php`.

⚠️ `test_site/` is **not** excluded, so the staging copy deploys live too. It
references the *same* root `/events.js`, so any change there applies to it
automatically. Kept out of Google by `robots.txt` and an `X-Robots-Tag`.

## The sibling project

`/Users/iainross/Coding/Eventbrite Sheet` is a Google Apps Script project that
pulls Eventbrite, Meta and this site's data into one spreadsheet. **The two
repos are deliberately NOT wired together.** They both talk to Eventbrite, and
they meet on one shared key: the `aff` code. Read that project's `CLAUDE.md`
before changing anything about attribution here — especially its section
"⚠️ WHEN EACH MEASUREMENT STARTED".

## Attribution: how a visit becomes an attributed ticket

```
someone arrives      →  server access log records it (query string, referrer, UA)
events.js            →  resolveAff() works out where they came from
they click a ticket  →  beacon to /api/c.php  +  ?aff=<code> on the Eventbrite link
they buy             →  Eventbrite stores the code on attendee.affiliate
the sheet            →  reads it back via the API
```

Three stages, one key, **no third-party analytics anywhere.**

### `resolveAff()` in `events.js`

Computes one code per visit from `location.search` UTMs first, then
`document.referrer`, then `direct`. Computed **once at load**, not per click —
the site is hash-anchor navigation only with no `pushState`, so
`location.search` survives the whole visit. If client-side routing is ever
added, this must move into `withAff()`.

⚠️ **CLOSED VOCABULARY, deliberately.** Never interpolate a raw `utm_source`
into the code: Eventbrite's affiliate space is unbounded and **permanent** —
there is no way to delete a code once a ticket carries it. Anything
unrecognised becomes `w-other`.

| code | when |
|---|---|
| `w-ig-bio` | `utm_source=ig\|instagram` + campaign starts `link-in-bio` |
| `w-ig-paid` | `utm_source=ig\|instagram` + medium `paid\|cpc\|ppc` |
| `w-ig` | `utm_source=ig\|instagram`, otherwise |
| `w-fb` / `w-fb-paid` | `utm_source=fb\|facebook\|an\|msg` |
| `w-flyer` | `utm_source=flyer` |
| `w-other` | tagged with something unrecognised |
| `w-ig-ref` / `w-fb-ref` | no UTM; referrer is Instagram / Facebook |
| `w-google` / `w-search` / `w-ref` | no UTM; referrer is a search engine / other site |
| `w-direct` | no UTM and no usable referrer |
| `website` | the try/catch fallback — previous behaviour |

⚠️ **Meta writes `utm_source={{site_source_name}}`, which expands to `ig`, `fb`,
`an` or `msg` — NEVER `instagram`.** Measured in these logs: `ig` 1,034 vs
`instagram` 16 over one month. Matching only the long form sent ~98% of paid
traffic to `w-other`. **You do not need to add UTMs to Meta ads; Meta already
does it**, including campaign, adset and ad IDs in `utm_campaign`,
`utm_content` and `utm_term`.

⚠️ **`classify()` in `api/logs.php` MUST mirror `resolveAff()`.** They are a
pair. If they drift, arrivals stop joining to the clicks and sales that carry
the code. Change both or neither.

### The click beacon

`events.js` fires `navigator.sendBeacon('/api/c.php?e=<eventId>&s=<affCode>&w=<which>')`
on the title link, Buy Tickets and More Information. `api/c.php` returns `204`
and does nothing — **the request itself is the record**, because the access log
captures it and `api/logs.php` reads it back.

⚠️ **NOT a redirect.** The visitor goes straight to Eventbrite via a plain
`<a href>`. Never intercept it.

⚠️ **Fire and forget. NEVER `preventDefault()` to "make sure" the beacon
lands** — that is exactly how analytics breaks checkout flows. It is wrapped in
try/catch, never awaited, never branched on. Verified by blocking both
`sendBeacon` and `Image`: nothing threw and the click still worked. Links are
`target="_blank"` so the page never unloads and there is no delivery race.

⚠️ Named `/api/c.php` because `/track`, `/pixel`, `/beacon`, `/collect` and
`/analytics` are all on standard ad-block lists. **The `.php` is required** —
the host does not rewrite extensionless paths.

⚠️ `api/c.php` requires nothing, on purpose. A bad edit to `api/config.php` must
not be able to take it down — that has already happened to the events feed.

## Files

| | |
|---|---|
| `index.html` | the site. Hash-anchor nav only. Ships a static Eventbrite link (`#eventsFallback`) that shows if the listing fails — it has already earned its keep. |
| `events.js` | events listing renderer, `resolveAff()`, the beacon. Deliberately isolated: a failure here must never take down the menu, lightbox, audio or video. |
| `api/events.php` | server-side Eventbrite proxy. Token stays server-side. 15-min file cache, serves stale on upstream failure. Skips unlisted/invite-only events. |
| `api/c.php` | click beacon. Returns 204. |
| `api/logs.php` | parses the raw access log into daily aggregates. Token-gated. |
| `api/config.php` | secrets. Hand-uploaded, git-ignored, deploy-excluded. |
| `.htaccess` | `/instagram` → homepage with link-in-bio UTMs. Optional trailing slash, case-insensitive. |
| `flyer/` | QR landing → homepage with `utm_source=flyer`. |
| `stats/` | self-hosted AWStats dashboard. Deploy-excluded; the repo copy mirrors the server. |
| `landing.html`, `script.js`, `styles.css` | the rest of the site. |

## `api/logs.php`

Returns **aggregates only** — never raw lines.

```
?token=...              live log only (what the daily sheet trigger uses)
?token=...&list=1       name the archives, read nothing
?token=...&file=<name>  ONE archive; resumable, prefer this for a backfill
?token=...&archives=1   everything (can exceed UrlFetchApp's ~60s ceiling)
```

```
live      /home/iainhwqg/access-logs/glasgowsoundbath.com.iainross.net-ssl_log
archives  /home/iainhwqg/logs/<domain>-ssl_log-<Mon-YYYY>.gz
```

⚠️ The host names the log files `glasgowsoundbath.com.iainross.net`, **not** the
bare domain. `open_basedir` is not set, so PHP can read them.

⚠️ **The server clock is `-0400`, NOT UK.** Every timestamp is converted to
Europe/London before the day is taken. Without it, evening visits land on the
previous day — the same defect that made the sheet's `Sales by day` wrong for
seven months of every year.

⚠️ **Logs rotate.** Only ~a month survives on the server, which is why the sheet
merges rather than replaces — it accumulates history the server no longer holds.

### Why not AWStats or Plausible

**AWStats strips the query string**, so it can never report a campaign — the
whole recorded URL list is six entries with no query variants. It is also ~14.5%
bots. **Plausible** was cancelled: its tier with Stats API access costs more than
it is worth here, and its script was demonstrably blocked in Iain's own browser.
The access log cannot be blocked and costs nothing.

## Privacy

**No cookies, no `localStorage`, nothing read from the device for attribution**,
so PECR is not engaged and **no consent banner is required**. (`script.js` does
store `ambient-sound` in localStorage — a preference the user chose, which is
exempt.)

In `logs.php` the IP is used only to group requests into visits, hashed with a
salt regenerated every run, and never returned. **`fbclid` is stripped** before
anything reads the query. Only aggregates leave the server.

⚠️ **The site has no privacy policy. That is a genuine gap** — emails via
Eventbrite, two Meta Pixels on the Eventbrite pages, and server logs. Open item.

## Gotchas that have already cost time

- ⚠️ **Bump the `events.js?v=` cache-buster in `index.html` on every change**, or
  the deploy lands a file nobody loads.
- ⚠️ **`api/config.php` must have a trailing comma on every array line.** A
  missing one is a parse error, and because `api/events.php` requires the same
  file it takes the **public events listing down with it**. This has happened.
- ⚠️ **Deploys propagate with a lag** and LiteSpeed edge-caches HTML. A 404 or
  stale content right after a deploy is usually not a bug — re-check in a minute.
- The `.embed-wrap` class in `index.html` is a leftover from the removed
  eventscalendar.co widget.
- `0f796aa`/`0c91967`: Buy Tickets points at `tickets-external?eid=`, which once
  returned HTTP 431 for a browser carrying a heavy Eventbrite session (the
  organiser's own). Documented in a code comment rather than fixed.

## Campaign tracking

Meta appends `utm_campaign={{campaign.id}}` to ads pointing at this site
automatically — no setup was ever needed, and an earlier belief that UTMs had to
be added by hand was wrong. `events.js` captures it into `CAMPAIGN` and the
beacon carries it as `&c=`, so `api/logs.php` reports arrivals and clicks per
campaign. Those ids join to `Meta Ads Raw`.`Campaign ID` in the sheet (verified
3/3).

⚠️ **The campaign is on the BEACON, never in the `aff` code.** Eventbrite
affiliate codes are permanent and cannot be deleted, so a campaign id in one
would accrue a new permanent code every campaign forever. The beacon goes to our
own access log, where nothing is permanent.

⚠️ `utm_id` and `utm_campaign` are **different values** on this account, so
`utm_id` is something else — probably the adset. Only `utm_campaign` is taken.

**Open, and gating the last step:** whether the Instagram app's boost flow
expands `aff=ig-{{campaign.id}}` for ads pointing straight at Eventbrite. It has
no "URL parameters" field, so the macro may not expand. Test on ONE boost and
read the address bar — full instructions are in the sheet project's CLAUDE.md.
Do not roll it out first: an unexpanded literal would be written to real tickets
permanently.

## Timeline — read before comparing any metric

| date | what began |
|---|---|
| 2026-08-21 16:35 | links tagged `aff=website` (flat) |
| 2026-08-23 10:01 | flat code split into `w-*` by source |
| 2026-08-24 08:40 | `utm_source=ig` recognised (~98% of paid traffic was `w-other` for ~18h; **0 tickets affected**) |
| 2026-08-24 09:21 | **outbound clicks recorded.** No click data exists before this. |
| 2026-08-24 09:44 | Plausible removed |
| 2026-08-24 | `/instagram` becomes the Instagram bio link |
| 2026-08-24 | campaign id captured on arrivals and clicks |

⚠️ **`w-ig-bio` will look tiny for weeks.** The bio only started pointing at
`/instagram` on 2026-08-24; earlier bio traffic is sitting untagged in
`w-direct` and `w-ig-ref`. Do not judge it before late September.

⚠️ **`w-direct` is "unattributed", not "typed the URL".** Instagram's in-app
browser usually sends no referrer.
