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

### `listing-failed` — and why it now says which failure

`events.js` reports a failed listing as `s=listing-failed`. Until 2026-08-26 the
reason was always the literal `w=error`, which was useless: **one `.catch()`
covers a network failure, a non-200, malformed JSON *and* a bug in the render**,
and they arrived indistinguishable. Two real failures were recorded on 24 and 25
Aug 2026 and could not be diagnosed at all.

It now reports the stage: `network`, `http<status>` (e.g. `http502`), `parse`,
or `render`. Cross-reference against the `errors` list from `api/logs.php` — if
the listing said `http502` there will be a matching `/api/events.php 502`.

## Files

| | |
|---|---|
| `index.php` | the site. **Was `index.html` until 2026-08-26** — see "Server-rendered events" below. Hash-anchor nav only. Still ships the static Eventbrite link (`#eventsFallback`) for when the server render produces nothing. |
| `events.js` | `resolveAff()`, the beacon, the expanders, and the *client-side* listing renderer used by `test_site/`. Deliberately isolated: a failure here must never take down the menu, lightbox, audio or video. |
| `api/events-lib.php` | **the** source of events: fetch, 15-min cache, map. Prints nothing and never `exit()`s, so both `index.php` and `api/events.php` can use it. |
| `api/events-render.php` | those events to page HTML and to schema.org JSON-LD. |
| `api/events.php` | thin JSON wrapper over the library. Response shape `{updated, events}` is depended on by `events.js` and `test_site/`. |
| `api/c.php` | click beacon. Returns 204. |
| `api/logs.php` | parses the raw access log into daily aggregates. Token-gated. |
| `api/config.php` | secrets. Hand-uploaded, git-ignored, deploy-excluded. |
| `.htaccess` | `/instagram` → homepage with link-in-bio UTMs. Optional trailing slash, case-insensitive. |
| `flyer/` | QR landing → homepage with `utm_source=flyer`. |
| `stats/` | self-hosted AWStats dashboard. Deploy-excluded; the repo copy mirrors the server. |
| `landing.html`, `script.js`, `styles.css` | the rest of the site. |

## Server-rendered events (2026-08-26)

**The listing is rendered by PHP into the page, and by JavaScript only on
`test_site/`.** Before this, events existed *solely* after `events.js` ran.

**Why it changed.** Asked about Glasgow soundbaths, an LLM reading this site
found only "Upcoming dates are listed on Eventbrite" — the page source carried
no date, venue, time or price anywhere. Verified: **0 JSON-LD blocks**, and the
entire events section in the served HTML was the loading text plus three links
to Eventbrite. Googlebot renders JavaScript and could eventually see the
listing; **GPTBot, ClaudeBot and PerplexityBot do not** and could not. `robots.txt`
was never the problem — it is `Allow: /`.

```
api/events-lib.php     fetch + cache + map      (no output, never exits)
        |
        +--> index.php            -> <ul class="event-list"> + JSON-LD   <- crawlers, LLMs
        +--> api/events.php       -> {updated, events}                   <- events.js, test_site/
```

⚠️ **`api/events-render.php` and `card()` in `events.js` are a PAIR.** They emit
the same classes, which `events.css` styles, and `events.js` finds the server
markup by `data-ping` on each link and `data-event-id` on each `.event-item`.
Change one without the other and either the styling or the attribution breaks.

⚠️ **No `aff` code is written server-side, deliberately.** `resolveAff()` needs
`document.referrer`, which only the browser has, and `classify()` in
`api/logs.php` already has to mirror it. A third implementation in PHP would be
a third thing to drift. `events.js` tags **every** Eventbrite link on the page
after load — including the three fallback links, which were untagged until now,
so a visitor who took one bought a ticket that landed under `ebdsoporgprofile`
as though they had found Eventbrite unaided.

⚠️ **A PHP fault can now cost the whole homepage, not just the listing.** That
is a real increase in blast radius and the reason `index.php` wraps the whole
thing in `catch (\Throwable)`, checks `is_readable` and uses `include_once`
rather than `require` — **a failed `require` is an `E_COMPILE_ERROR` no catch
can reach.** On any failure the page falls through to exactly the markup it
served yesterday. Verified against four failure modes; see "Testing PHP".

✅ **A parse error in an INCLUDED file is catchable, contrary to the folklore.**
Since PHP 7 it arrives as a `ParseError` (`extends CompileError extends Error
extends Throwable`), so `catch (\Throwable)` gets it — measured, not assumed:
a deliberately corrupted `events-render.php` still rendered the full page with
its fallback. **A parse error in `index.php` itself is still fatal and
uncatchable**, which is the one case the pre-deploy lint exists for.

⚠️ **`header()` must come before the try block, not after it.** A PHP warning
printed with `display_errors` on is output, and output before `header()` is
"headers already sent" plus a broken doctype. `is_readable` before each include
is the other half of that: a plain `include` of a missing file *prints*.

⚠️ **Bump the cache filename in `gsb_cache_file()` when the mapped shape
changes.** The cache holds the *mapped* payload, so an old file is served
happily with fields the new renderer looks for and cannot find. `v3` added
prices and sold-out, `v4` the waitlist flag.

⚠️ **`startDate` in the JSON-LD must carry the local offset (`+01:00`), never
`Z`.** Google reads a bare UTC instant as UTC and would show 6:30pm for a 7:30pm
summer event — the same class of error as the `Sales by day` BST bug in the
sheet project.

⚠️ **The overflow fold is the RENDERED position, not the index in `$events`.**
Keyed off the input index, every event skipped above it pulls the fold one
earlier and shows fewer than `GSB_MAX_VISIBLE` dates — silently, and only ever
on the days something was already wrong. Caught by executing the renderer
against a fixture with two bad events: 12 rendered, 6 hidden, 6 shown.

⚠️ **One malformed event must cost one date.** `gsb_map_event()` drops an event
with no usable `start`, and both renderers guard each event individually. The
old code emitted `'start_time' => ... ?? ''`, and an empty string reaches
`Intl`/`DateTime` as an invalid date — in the browser that threw `RangeError`
out of `all.forEach(card)` and blanked **all** the dates. That is what fired the
`listing-failed` beacon.

**Prices and sold-out come from `expand=ticket_availability`** —
`minimum_ticket_price`, `maximum_ticket_price`, `is_sold_out`,
`waitlist_available`. The sliding scale is rendered as a schema.org
`AggregateOffer` (low £20 / high £30), because it is three prices for one
experience, not three competing offers.

⚠️ **A SOLD-OUT EVENT MUST STILL LINK TO THE CHECKOUT.** The waitlist is joined
through the normal checkout — verified both ways: Iain confirmed it, and the
`tickets-external` page for a sold-out event is full of waitlist markup. The
first version of this dropped the link "so nobody hits a dead end", which would
have silently killed waitlist signups. **Eventbrite exposes no waitlist endpoint
at all** (`/waitlist/` 403s, `/waitlist_items/` 404s — see the sheet project's
CLAUDE.md), so the waitlist is the ONLY measurement of demand above capacity
there is: fill rate stops at 100% and says nothing beyond it. The label reads
"Sold out — Join Waiting List" when `waitlist_available`, plain "Sold out"
otherwise, and it keeps `data-ping="ticket"` so the beacon measures it.

⚠️ **`is_sold_out` means "no ticket is purchasable right now", NOT "sold to
capacity".** Measured on this account: **true on 50 of 50 past events**,
including ones that closed at 60% fill, because an ended event sells nothing.
It is safe here only because the fetch asks for `status=live` and
`time_filter=current_future`. Reuse it over history and you will compute a 100%
sellout rate and have no reason to doubt it.

**Progressive enhancement, both expanders.** The "Show all N dates" button and
each "Show more" toggle ship `hidden` and are unhidden by `events.js` — a button
that cannot work is worse than no button. The overflow dates and the descriptions
are in the source either way, which is what crawlers read.

⚠️ **`index.php` sets `Cache-Control: no-store`,** matching `api/events.php`: an
event pulled from sale must disappear on the next request. The cost is that the
homepage is no longer an edge-cacheable static file.

⚠️ **The deploy SYNCS, so `index.html` is deleted from the server.** Nothing
links to `/index.html` (checked) and the canonical URL is `/`, but `index.php`
must be ahead of `index.html` in the host's `DirectoryIndex`. It is on cPanel by
default.

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

**`errors`: non-2xx responses per path per day** (added 2026-08-26). The status
code was parsed off every line and thrown away, so when the events listing
failed, the one record that could have said *why* — a 502 from
`api/events.php`, sitting in this very log — was discarded a line later. Only
paths a visitor could be on, plus `/api/`; assets are excluded because a missing
favicon is noise. Returned in the JSON and surfaced by `fn=webtraffic` in the
sheet project; nothing writes it to a sheet, because no history of it is needed.

⚠️ **`w` keeps digits and hyphens now.** It was sanitised to letters only, which
silently turned a failure reason of `http502` into `http` — deleting the only
characters that said what went wrong.

### Why not AWStats or Plausible

**AWStats strips the query string**, so it can never report a campaign — the
whole recorded URL list is six entries with no query variants. It is also ~14.5%
bots. **Plausible** was cancelled: its tier with Stats API access costs more than
it is worth here, and its script was demonstrably blocked in Iain's own browser.
The access log cannot be blocked and costs nothing.

## Testing PHP without PHP

⚠️ **There is no PHP on the development Mac and `brew install php` CANNOT
provide one.** Tried on 2026-08-26: the Mac is macOS 12, Homebrew ships no
bottles for it, so everything builds from source — and the build **fails**, on
`postgresql` (a PHP dependency, for `pdo_pgsql`) with an XML entity error in its
docs, after roughly an hour. Homebrew then says outright: *"You are using macOS
12. We (and Apple) do not provide support for this old version"* and points at
MacPorts. ⚠️ **The command still exits 0**, so a script that only checks the
exit status will report success; check `brew list --versions php`. It leaves
~2.1GB of built dependencies behind either way.

**Use `tools/phpcheck/`** — real PHP 8.0 compiled to WebAssembly, plus a JS PHP
parser. Read its README; it carries the gotchas. `node_modules` is git-ignored
and the whole directory is excluded from the deploy.

```bash
cd tools/phpcheck && npm install
npm run lint   # parse-check every .php, and grep for PHP 8 syntax
npm test       # 56 checks: index.php across six scenarios
```

⚠️ **Run `npm test` after touching `index.php` or `api/events-render.php`.**
Every case in it is a bug that actually happened. It has been checked against
reintroduced bugs, so a pass means something.

## Privacy

**No cookies, no `localStorage`, nothing read from the device for attribution**,
so PECR is not engaged and **no consent banner is required**. (`script.js` does
store `ambient-sound` in localStorage — a preference the user chose, which is
exempt.)

In `logs.php` the IP is used only to group requests into visits, hashed with a
salt regenerated every run, and never returned. **`fbclid` is stripped** before
anything reads the query. Only aggregates leave the server.

⚠️ **THIS FILE WAS SERVED PUBLICLY at `/CLAUDE.md`** from 2026-08-24 to
2026-08-26. The FTP deploy syncs, and it was neither excluded nor blocked, so
the hosting layout, the home directory, the server access-log paths and every
internal endpoint were a plain GET away. **Treat anything written here as
public.** Now excluded from the deploy *and* denied in `.htaccess` — belt and
braces, because the exclude list only stops the NEXT upload.
⚠️ **The stale copy is still on the server and must be deleted by hand** via
cPanel or FTP. `.htaccess` only makes it unreachable.

**Everything else was checked at the same time and is fine:** `.env` 403,
`api/logs.php` 403 (its own token gate), `.github/**` 404, `README.md` 404, and
`api/*.php` return empty because they execute — no source leak. `test_site/`
is 200, which is known and kept out of Google by `robots.txt`.

⚠️ **The site has no privacy policy. That is a genuine gap** — emails via
Eventbrite, two Meta Pixels on the Eventbrite pages, and server logs. Open item.

## Gotchas that have already cost time

- ⚠️ **Bump the `events.js?v=` cache-buster in `index.php` on every change**, or
  the deploy lands a file nobody loads.
- ⚠️ **There is no PHP on the development Mac** — see "Testing PHP without PHP".
  **No PHP 8 syntax**: no `str_contains`, `??=`, `fn()`, `match`, `?->`, or
  attributes. The host is 7.2.
- ⚠️ **`api/config.php` must have a trailing comma on every array line.** A
  missing one is a parse error, and because `api/events.php` requires the same
  file it takes the **public events listing down with it**. This has happened.
- ⚠️ **Deploys propagate with a lag** and LiteSpeed edge-caches HTML. A 404 or
  stale content right after a deploy is usually not a bug — re-check in a minute.
- ⚠️ **Run creation is not prompt, and the delay varies wildly.** Measured
  2026-08-26 across seven pushes: **3–4 seconds** in the morning, **7–14
  minutes** the same afternoon. A check 40 seconds after pushing proves nothing.
  ⚠️ It is also not guaranteed: one commit that day (`1c162c0`) got **no run at
  all** — no failed run, no check suite — while the commits either side of it
  ran fine. No cause was found, and the scope theory that looked obvious at the
  time was wrong: the commits it "explained" all ran once GitHub caught up.
- ✅ **A skipped commit is NOT a lost deploy.** The FTP action **syncs the whole
  working tree**, so the next run that does fire uploads everything, including
  whatever the skipped commit changed. This is why nothing was lost above.
- ⚠️ **Verify a deploy against the live site, never against the Actions tab.**
  `git push` exiting 0 says only that GitHub received the objects. What settles
  it is `curl`:
  ```bash
  curl -s -o /dev/null -w '%{http_code}\n' https://glasgowsoundbath.com/
  ```
- The `.embed-wrap` class in `index.php` is a leftover from the removed
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
| 2026-08-26 | **events rendered server-side** with JSON-LD; before this no crawler or LLM could read a single date off the site |
| 2026-08-26 | the three fallback Eventbrite links carry `aff`; before this they were untagged and their sales landed under `ebdsoporgprofile` |
| 2026-08-26 | `listing-failed` reports a reason; before this every failure was the same opaque `error` |

⚠️ **`w-ig-bio` will look tiny for weeks.** The bio only started pointing at
`/instagram` on 2026-08-24; earlier bio traffic is sitting untagged in
`w-direct` and `w-ig-ref`. Do not judge it before late September.

⚠️ **`w-direct` is "unattributed", not "typed the URL".** Instagram's in-app
browser usually sends no referrer.
