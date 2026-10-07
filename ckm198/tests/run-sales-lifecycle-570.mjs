#!/usr/bin/env node
/** One offline suite; each PHP fixture owns an isolated WordPress boundary. */
import { spawnSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
let php = process.env.PHP_BINARY || 'php';
for (let i = 0; i < args.length; i++) {
  if (args[i] === '--php' && args[i + 1]) php = args[++i];
  else {
    console.error('Usage: node tests/run-sales-lifecycle-570.mjs [--php PHP_BINARY_OR_WASM_CLI.mjs]');
    process.exit(2);
  }
}
const command = php.endsWith('.mjs') ? process.execPath : php;
const prefix = php.endsWith('.mjs') ? [resolve(php)] : [];
const suites = [
  'tests/sales-standard-lifecycle-570-test.php',
  'tests/sales-570-assignment-guards.php',
  'modules/effective-sales/tests/sales-570-revision-commands.php',
  'tests/sales-570-impact-storage.php',
];
let checks = 0;
let failures = 0;
for (const suite of suites) {
  const result = spawnSync(command, [...prefix,
    '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0',
    '-d', 'disable_functions=exec,shell_exec,system,passthru,proc_open,popen,fsockopen,pfsockopen,stream_socket_client,curl_exec,curl_multi_exec',
    resolve(root, suite)], {
    cwd: root, encoding: 'utf8', timeout: 60000, maxBuffer: 8 * 1024 * 1024,
    env: { PATH: process.env.PATH, SystemRoot: process.env.SystemRoot,
      TMPDIR: tmpdir(), TEMP: tmpdir(), TMP: tmpdir(), TZ: 'UTC' },
  });
  const output = (result.stdout || '') + (result.stderr || '');
  const count = (output.match(/^PASS: /gm) || []).length;
  const completed = output.match(/(?:^|\n)(?:sales-570 revision commands: (\d+) PASS|(\d+)(?: SALES-570 (?:assignment\/privacy guard|impact storage))? checks passed\.)/);
  const declaredCount = Number(completed?.[1] || completed?.[2]);
  if (result.error || result.status !== 0 || count === 0 || count !== declaredCount || /(?:^|\n)(?:FAIL:|Fatal error:|PHP Fatal error:)/.test(output)) {
    failures++;
    console.error(`FAIL ${suite} (exit ${result.status ?? 'unavailable'})`);
    console.error(result.error?.message || output);
  } else {
    checks += count;
    console.log(`PASS ${suite}: ${count} checks`);
  }
}
console.log(`${checks} checks passed; ${failures} suites failed. Live tests were not run.`);
process.exit(failures ? 1 : 0);
