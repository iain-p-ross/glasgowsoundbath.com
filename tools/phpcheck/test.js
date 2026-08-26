#!/usr/bin/env node
/*
 * The behaviour of index.php that must not regress, checked by executing it.
 *
 * Every case here is a bug that actually happened or a guarantee the code makes
 * in a comment. If you change api/events-render.php or index.php, run this.
 *
 *   npm test
 *
 * ⚠️ One child process per scenario: a second php.run() on one instance hangs.
 * ⚠️ The assertions are counted and the count is asserted. A test file that
 * silently checks nothing is worse than no test file — see "Verification
 * scripts that fail silently" in the sheet project's CLAUDE.md.
 */
const { execFileSync } = require('child_process');
const path = require('path');

const SCENARIOS = {
  /* The ordinary case. */
  good: (r) => [
    ['renders every event', r.eventItems === 11],
    ['shows GSB_MAX_VISIBLE at rest', r.eventItems - r.hiddenItems === 8],
    ['emits exactly one JSON-LD block', r.jsonLdBlocks === 1],
    ['one JSON-LD node per event', r.jsonLdNodes === 11],
    ['startDate carries a local offset, not Z', /\+\d\d:\d\d$/.test(r.jsonLdStartDate)],
    ['marks the wrapper server-rendered', r.serverRendered === true],
    ['hides the static fallback', r.fallbackShown === false],
    ['keeps Buy Tickets', r.buyTicketsPresent === true],
  ],

  /* One event with an empty start_time and one with a bad timezone. Both used
     to throw RangeError out of the render loop and blank the whole listing. */
  mixed: (r) => [
    ['drops only the unusable events', r.eventItems === 11 && r.seededEvents === 13],
    ['fold counts RENDERED items, not input index', r.eventItems - r.hiddenItems === 8],
    ['still emits JSON-LD', r.jsonLdNodes === 11],
    ['does not fall back', r.fallbackShown === false],
  ],

  /* A sold-out event must still reach the checkout: that is how the waitlist is
     joined, and the waitlist is the only measure of demand above capacity. */
  soldout: (r) => [
    ['labels a waitlisted sell-out', r.soldOutWaitlistLabel === true],
    ['keeps it a link', r.soldOutIsLink === true],
    ['points at the checkout', r.soldOutHref === true],
    ['plain "Sold out" without a waitlist', r.noWaitlistPlainLabel === true],
    ['still a link without a waitlist', r.noWaitlistStillLink === true],
  ],

  /* No events, a corrupted library, a missing library. All three must leave a
     complete page — index.php may cost the listing, never the homepage. */
  empty: (r) => [
    ['falls back to the static markup', r.fallbackShown === true],
    ['emits no empty JSON-LD', r.jsonLdBlocks === 0],
    ['page is still complete', r.pageIntact === true],
  ],
  'broken-lib': (r) => [
    ['survives a ParseError in the library', r.fallbackShown === true],
    ['page is still complete', r.pageIntact === true],
    ['no fatal escapes', r.fatals.length === 0],
  ],
  'missing-lib': (r) => [
    ['survives a missing library', r.fallbackShown === true],
    ['no warning printed before the doctype', r.doctypeFirst === true],
    ['page is still complete', r.pageIntact === true],
  ],
};

/* True of every scenario, always. */
const UNIVERSAL = (r) => [
  ['exits 0', r.exitCode === 0],
  ['<!DOCTYPE html> is the first thing on the page', r.doctypeFirst === true],
  ['closes </html>', r.closesHtml === true],
  ['leaks no PHP tags', r.phpLeaked === false],
  ['no raw </script> inside the JSON-LD', r.rawScriptClose === false],
];

let checks = 0, failed = 0;
for (const [mode, assertions] of Object.entries(SCENARIOS)) {
  let out;
  try {
    out = execFileSync(process.execPath, [path.join(__dirname, 'scenario.js'), mode],
      { encoding: 'utf8', timeout: 120000 });
  } catch (e) {
    console.log(`\n${mode}\n  FAIL  scenario did not run: ${e.message.split('\n')[0]}`);
    failed++; continue;
  }
  const r = JSON.parse(out);
  console.log(`\n${mode}`);
  for (const [name, ok] of [...assertions(r), ...UNIVERSAL(r)]) {
    checks++;
    if (!ok) failed++;
    console.log(`  ${ok ? 'ok  ' : 'FAIL'}  ${name}`);
  }
}

/* Assert the assertions ran. Without this, a rename that emptied SCENARIOS
   would report a clean pass having tested nothing at all. */
const EXPECTED_MIN = 45;
console.log(`\n${checks} checks, ${failed} failed`);
if (checks < EXPECTED_MIN) {
  console.log(`FAIL: only ${checks} checks ran, expected at least ${EXPECTED_MIN}. Something is not running.`);
  process.exit(2);
}
process.exit(failed ? 1 : 0);
