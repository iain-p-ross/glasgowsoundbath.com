#!/usr/bin/env node
/*
 * Parse-check every .php file in the site.
 *
 * ⚠️ This catches the FATAL class — unbalanced braces, bad syntax — and that is
 * the class worth catching, because a parse error in index.php is the one
 * failure catch (\Throwable) cannot save. It does NOT reject PHP 8 syntax even
 * with php7:true (match, ?->, fn() all parse clean), so the grep below carries
 * that half. The live host is PHP 7.2.
 */
const fs = require('fs');
const path = require('path');
const Engine = require('php-parser');

const ROOT = path.resolve(__dirname, '..', '..');
const parser = new Engine({ parser: { php7: true, suppressErrors: false }, ast: { withPositions: true } });

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

let bad = 0;
for (const f of files) {
  const rel = path.relative(ROOT, f);
  const src = fs.readFileSync(f, 'utf8');
  let errs = [];
  try {
    const ast = parser.parseCode(src, f);
    errs = ast.errors || [];
  } catch (e) {
    errs = [{ line: e.lineNumber || '?', message: e.message }];
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
console.log(`\n${files.length} file(s), ${bad} failing`);
process.exit(bad ? 1 : 0);
