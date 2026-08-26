#!/usr/bin/env node
/*
 * Execute one PHP script with the site's api/ mounted. Real PHP, compiled to
 * WebAssembly — there is no PHP on this Mac and brew cannot install one
 * (macOS 12; see CLAUDE.md).
 *
 *   npm run php -- somescript.php
 *
 * ⚠️ ONE php.run() PER PROCESS. A second run on the same instance hangs
 * forever with no output. Spawn another process instead; test.js does.
 * ⚠️ Do not pipe this into `tail` — it buffers, and a buffered pipe looks
 * exactly like the hang above.
 */
const { loadNodeRuntime } = require('@php-wasm/node');
const { PHP } = require('@php-wasm/universal');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');
const script = process.argv[2];
if (!script) { console.error('usage: node run.js <script.php>'); process.exit(2); }

(async () => {
  const php = new PHP(await loadNodeRuntime('8.0', { emscriptenOptions: { processId: 1 } }));
  php.mkdir('/site'); php.mkdir('/site/api');
  for (const f of ['api/events-lib.php', 'api/events-render.php']) {
    php.writeFile('/site/' + f, fs.readFileSync(path.join(ROOT, f), 'utf8'));
  }
  php.writeFile('/script.php', fs.readFileSync(script, 'utf8'));
  const r = await php.run({ scriptPath: '/script.php' });
  if (r.errors) process.stderr.write(String(r.errors));
  process.stdout.write(new TextDecoder().decode(r.bytes));
  process.exit(r.exitCode === 0 ? 0 : 1);
})().catch((e) => { console.error('HARNESS ERROR:', e.message); process.exit(2); });
