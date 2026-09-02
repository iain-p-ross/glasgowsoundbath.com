#!/usr/bin/env node
/*
 * Parse-check every .php file in the site.
 *
 * ⚠️ This catches the FATAL class — unbalanced braces, bad syntax — and that is
 * the class worth catching, because a parse error in index.php is the one
 * failure catch (\Throwable) cannot save. The live host is PHP 7.2.
 *
 * TWO ENGINES, in preference order:
 *
 *   1. A real `php -l` binary, if one is installed. Since 2026-09-02 that is
 *      MacPorts php74 (`sudo port install php74`) — brew still cannot provide
 *      one, see CLAUDE.md. This is authoritative: it is the actual PHP parser.
 *   2. The php-parser JS module, when no binary is present (another machine,
 *      or CI). ⚠️ It does NOT reject PHP 8 syntax even with php7:true —
 *      match, ?->, fn() all parse clean — so on this path the grep is the only
 *      thing standing between a PHP 8 construct and the 7.2 host.
 *
 * ⚠️ THE GREP RUNS EITHER WAY, and must. `php74 -l` was measured against each
 * construct on 2026-09-02: it rejects match, ?->, enum, named arguments and
 * constructor promotion, but ACCEPTS four things this repo bans —
 *
 *   #[Attr]                 `#` opens a COMMENT in PHP 7, so an attribute
 *                           parses clean and silently does nothing
 *   fn() and ??=            7.4 syntax, so a 7.4 binary is happy; 7.2 is not
 *   str_contains() etc.     function NAMES are calls, not syntax — `php -l`
 *                           never checks whether a function exists
 *
 * A newer binary is worse, not better: php80+ accepts everything above.
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const Engine = require('php-parser');

const ROOT = path.resolve(__dirname, '..', '..');
const parser = new Engine({ parser: { php7: true, suppressErrors: false }, ast: { withPositions: true } });

/* First binary that runs wins. PHPCHECK_PHP overrides for a different install;
   PHPCHECK_PHP=none forces the JS fallback, which is the only way to check that
   the fallback still works on a machine where a binary IS installed. */
function findPhp() {
  if (process.env.PHPCHECK_PHP === 'none') return null;
  const candidates = [process.env.PHPCHECK_PHP, '/opt/local/bin/php74', '/opt/local/bin/php72', 'php'];
  for (const bin of candidates) {
    if (!bin) continue;
    try {
      const v = execFileSync(bin, ['--version'], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
      const m = v.match(/^PHP (\d+\.\d+\.\d+)/);
      return { bin: bin, version: m ? m[1] : 'unknown' };
    } catch (e) { /* not installed, try the next */ }
  }
  return null;
}

const PHP = findPhp();

/* Check the file with the real binary. Returns [] when clean. */
function lintWithPhp(file) {
  try {
    execFileSync(PHP.bin, ['-l', file], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
    return [];
  } catch (e) {
    const out = String((e.stdout || '') + (e.stderr || '')).trim();
    const m = out.match(/line (\d+)/);
    return [{ line: m ? m[1] : '?', message: out.split('\n')[0] }];
  }
}

/* Function names and syntax that exist in PHP 8 but not 7.2. A parser cannot
   see the function names at all — they are calls, not syntax. */
const PHP8_ONLY = /\bstr_contains\s*\(|\bstr_starts_with\s*\(|\bstr_ends_with\s*\(|\?\?=|(?<![$\w])fn\s*\(|(?<![$\w>])match\s*\(|\?->|^\s*#\[/m;

function phpFiles(dir, acc = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (e.name === 'node_modules' || e.name === '.git' || e.name === 'tools') continue;
    const p = path.join(dir, e.name);
    if (e.isDirectory()) phpFiles(p, acc);
    else if (e.name.endsWith('.php')) acc.push(p);
  }
  return acc;
}

const files = phpFiles(ROOT);
if (!files.length) {
  console.error('FAIL: no .php files found — the lint would have "passed" having checked nothing.');
  process.exit(2);
}

/* Say which engine ran. A silent downgrade to the weaker parser is exactly the
   kind of thing that turns a green lint into no lint at all. */
console.log(PHP
  ? `syntax: ${PHP.bin} (PHP ${PHP.version})   host is 7.2`
  : 'syntax: php-parser (JS) — no php binary found, see the header. `sudo port install php74`');
console.log('');

let bad = 0;
for (const f of files) {
  const rel = path.relative(ROOT, f);
  const src = fs.readFileSync(f, 'utf8');
  let errs = [];
  if (PHP) {
    errs = lintWithPhp(f);
  } else {
    try {
      const ast = parser.parseCode(src, f);
      errs = ast.errors || [];
    } catch (e) {
      errs = [{ line: e.lineNumber || '?', message: e.message }];
    }
  }
  const php8 = src.match(PHP8_ONLY);
  if (errs.length || php8) {
    bad++;
    console.log(`FAIL ${rel}`);
    for (const e of errs) console.log(`   parse, line ${e.line}: ${e.message}`);
    if (php8) console.log(`   PHP 8 only, host is 7.2: ${php8[0].trim()}`);
  } else {
    console.log(`ok   ${rel}`);
  }
}
console.log(`\n${files.length} file(s), ${bad} failing`
  + (PHP ? '' : '   ⚠️ syntax checked by the JS parser only'));
process.exit(bad ? 1 : 0);
