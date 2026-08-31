# phpcheck — parse and run this site's PHP, without PHP

There is no PHP on the development Mac, and **`brew install php` cannot provide
one**: the Mac is macOS 12, Homebrew ships no bottles for it, so everything
builds from source and the build fails on `postgresql` after about an hour.
Homebrew says outright that it does not support this macOS version. ⚠️ It still
**exits 0** — only `brew list --versions php` tells the truth.

So this runs real PHP compiled to WebAssembly instead. It is not a fallback; it
is the only route available here.

```bash
cd tools/phpcheck
npm install          # once

npm run lint         # parse-check every .php in the site
npm test             # execute index.php across six scenarios
npm run php -- x.php # run one script with api/ mounted
```

## What each one is for

**`npm run lint`** catches the fatal class — unbalanced braces, bad syntax.
That is the class that matters, because a parse error in `index.php` is the one
failure `catch (\Throwable)` cannot save. ⚠️ The parser does **not** reject PHP 8
syntax even set to `php7` (`match`, `?->`, `fn()` all parse clean), so a separate
grep covers the PHP-8-only functions and syntax. The host is **PHP 7.2**.

**`npm test`** executes `index.php` under six scenarios and asserts what must not
regress. Every case is a bug that actually happened or a guarantee the code makes
in a comment:

| scenario | what it proves |
|---|---|
| `good` | 11 events, 8 visible, one JSON-LD block, `startDate` and `validFrom` carry a local offset, every node has a `performer` |
| `mixed` | an empty `start_time` and a bad timezone cost one date each, not the listing; an unusable `sales_start` costs only `validFrom` |
| `soldout` | a sold-out event still LINKS to the checkout — that is how the waitlist is joined |
| `empty` | no events falls back to the static markup |
| `broken-lib` | a `ParseError` in the library still renders the page |
| `missing-lib` | a missing library prints no warning before the doctype |

Plus five universal checks per scenario: exits 0, `<!DOCTYPE html>` first, closes
`</html>`, leaks no PHP tags, no raw `</script>` inside the JSON-LD.

**Prove it can fail before you trust a pass.** Reintroduce the fold bug
(`$i >= GSB_MAX_VISIBLE` instead of `count($items) >= …`) and the suite reports
*"fold counts RENDERED items, not input index"*. It has been checked.

## Gotchas that cost time here

⚠️ **One `php.run()` per process.** A second run on the same instance hangs
forever with no output. `test.js` spawns a child per scenario.

⚠️ **Exit explicitly.** The WASM runtime holds the Node event loop open, so a
script that finishes its work still never exits — and a parent using
`execFileSync` times out on work that already succeeded. That looked exactly
like a hang.

⚠️ **Never pipe a long-running command into `tail`.** It buffers, so the output
appears only at the end and a working command looks like a hang. Redirect to a
file instead.

⚠️ **Assert the seed landed.** `scenario.js` writes the events cache so no token
and no network are needed. Swallowing a failed write turns a cache-hit test into
a live-network test that hangs.

⚠️ **`.event-item` contains `<li>` elements**, so a non-greedy regex to `</li>`
stops at the first nested one. Split on the item boundary. That mistake produced
five false negatives.

## Not deployed

`tools/**`, `node_modules/**` and `package*.json` are in the exclude list in
`.github/workflows/deploy.yml`. ⚠️ **That list is load-bearing — the FTP action
SYNCS**, so anything not excluded is uploaded into the public web root.
