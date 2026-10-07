/** Exercise the actual editor direction mapper and browser select acceptance for every seed item. */
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from 'node:fs';
import { dirname, resolve, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { tmpdir } from 'node:os';
import { spawnSync } from 'node:child_process';
import vm from 'node:vm';
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
const phpFlag = args.indexOf('--php');
const phpAdapter = phpFlag >= 0 ? args[phpFlag + 1] : process.env.CKM_TEST_PHP_CLI;
if (phpFlag >= 0) { args.splice(phpFlag, 2); }
const source = readFileSync(args[0] || join(root,'modules/negotiation-master/assets/negotiation-builder.js'), 'utf8');
const mapperSource = source.match(/^function canonicalDirection\(value\)\{[^\n]+\}/m)?.[0];
assert.ok(mapperSource, 'production canonicalDirection found');
const canonicalDirection = vm.runInNewContext(`(${mapperSource})`);
const page = readFileSync(join(root,'modules/negotiation-master/public/builder-page.php'),'utf8');
const selectOptions = field => new Set([...page.match(new RegExp(`<select data-f="${field}">([\\s\\S]*?)</select>`))[1].matchAll(/<option value="([^"]*)"/g)].map(m=>m[1]));
const options={player_preference_direction:selectOptions('player_preference_direction'),opponent_preference_direction:selectOptions('opponent_preference_direction')};
const seed = readFileSync(join(root,'modules/negotiation-master/content/system-v1.php'),'utf8');
const pack = JSON.parse(seed.match(/CKM_NEG_SEED_JSON'\n([\s\S]*)\nCKM_NEG_SEED_JSON/)[1]);
let checks=0,customItems=0;
for(const scenario of pack.scenarios){
  for(const item of scenario.components.items){
    if(item.player_preference_direction==='custom'||item.opponent_preference_direction==='custom')customItems++;
    for(const field of Object.keys(options)){
      const original=item[field],value=canonicalDirection(original);
      assert.ok(options[field].has(value),`current seeded ${original} preference must survive select.value for ${field}`);
      assert.ok(value, 'current seed preference must not become empty');
      item[field]=value;checks+=2;
    }
  }
}
assert.ok(customItems>0,'current custom preference items exercised');checks++;
// Use the actual PHP backend after the actual UI adapter, keeping the generated fixture local.
const taskTemp=mkdtempSync(join(tmpdir(),'ckm-direction-roundtrip-'));
try {
  const payload=join(taskTemp,'adapted-seed.json');writeFileSync(payload,JSON.stringify(pack));
  const phpCli=phpAdapter||'php';
  const phpArgs=['-d','allow_url_fopen=0','-d','allow_url_include=0','-d','disable_functions=exec,shell_exec,system,passthru,proc_open,popen,fsockopen,pfsockopen,stream_socket_client,curl_exec,curl_multi_exec,mail',join(root,'tests/neg-builder-seed-items-571-test.php'),join(root,'modules/negotiation-master/application/scenario-builder-service.php'),payload];
  const isAdapter=phpCli.endsWith('.mjs');
  const result=spawnSync(isAdapter?process.execPath:phpCli,isAdapter?[phpCli,...phpArgs]:phpArgs,{encoding:'utf8',env:{PATH:process.env.PATH,TMPDIR:process.env.TMPDIR||tmpdir(),TZ:'UTC'}});
  process.stdout.write(result.stdout||'');process.stderr.write(result.stderr||'');
  assert.equal(result.status,0,'adapted seed survives production backend; provide --php CLI when native PHP is unavailable');checks++;
} finally {rmSync(taskTemp,{recursive:true,force:true});}
console.log(`NEG-BUILDER-DIRECTION-ROUNDTRIP-571: ${checks}/${checks} PASS; ${customItems} custom items retained`);
