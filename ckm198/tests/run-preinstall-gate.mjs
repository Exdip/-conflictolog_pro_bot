#!/usr/bin/env node
/** Full pre-install gate: every historical suite remains in the inventory. */
import { spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { dirname, extname, isAbsolute, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const options={php:process.env.PHP_BINARY||'php',parser:'php-parser',jobs:4,output:join(tmpdir(),'ckm-preinstall-gate')};
for(let i=2;i<process.argv.length;i++){
  const name=process.argv[i];
  const field={'--php':'php','--parser':'parser','--jobs':'jobs','--output':'output'}[name];
  if(!field||!process.argv[i+1])throw new Error('Usage: node tests/run-preinstall-gate.mjs [--php PHP_OR_WASM.mjs] [--parser PHP_PARSER_PACKAGE_PATH] [--jobs 1..8] [--output REPORT_DIR]');
  options[field]=process.argv[++i];
}
options.jobs=Number(options.jobs);
if(!Number.isInteger(options.jobs)||options.jobs<1||options.jobs>8)throw new Error('jobs must be1..8');
options.output=resolve(options.output);mkdirSync(options.output,{recursive:true});
const phpCommand=options.php.endsWith('.mjs')?process.execPath:options.php;
const phpPrefix=options.php.endsWith('.mjs')?[resolve(options.php)]:[];
const phpFlags=['-d','allow_url_fopen=0','-d','allow_url_include=0','-d',
  'disable_functions=exec,shell_exec,system,passthru,proc_open,popen,fsockopen,pfsockopen,stream_socket_client,curl_exec,curl_multi_exec,mail'];
const environment={PATH:process.env.PATH,SystemRoot:process.env.SystemRoot,TMPDIR:tmpdir(),TEMP:tmpdir(),TMP:tmpdir(),TZ:'UTC'};
function run(command,args){
  return new Promise(resolveResult=>{
    const child=spawn(command,args,{cwd:root,env:environment});
    let stdout='',stderr='',timedOut=false,finished=false;
    const finish=result=>{if(finished)return;finished=true;clearTimeout(timer);resolveResult({...result,stdout,stderr,timedOut});};
    child.stdout?.on('data',chunk=>stdout+=chunk.toString());
    child.stderr?.on('data',chunk=>stderr+=chunk.toString());
    const timer=setTimeout(()=>{timedOut=true;child.kill('SIGKILL');},90000);
    child.once('error',error=>finish({exitCode:null,error:error.message}));
    child.once('close',(exitCode,signal)=>finish({exitCode,signal}));
  });
}
function php(args){return run(phpCommand,[...phpPrefix,...phpFlags,...args]);}
function collect(folder){
  const files=[];
  for(const entry of readdirSync(folder,{withFileTypes:true})){
    const path=join(folder,entry.name);
    if(entry.isSymbolicLink())throw new Error('Symlinks require explicit inspection: '+path);
    if(entry.isDirectory())files.push(...collect(path));
    else if(entry.isFile())files.push(path);
  }
  return files;
}
const files=collect(root).sort();
const report={root,php:null,checks:{},results:[],waivers:[],counts:{pass:0,fail:0,skip:0,timeout:0},status:'FAIL'};
let blocked=false;
const version=await php(['-r','echo PHP_VERSION; exit(PHP_MAJOR_VERSION===8 && PHP_MINOR_VERSION===3 ? 0 : 2);']);
report.php=version.stdout.trim();
if(version.exitCode!==0){console.error('FAIL PHP8.3 runtime',version);blocked=true;}

try{
  const require=createRequire(import.meta.url);
  const Engine=require(isAbsolute(options.parser)?resolve(options.parser):options.parser);
  const engine=new Engine({parser:{version:'8.3',suppressErrors:false}});
  const phpFiles=files.filter(path=>extname(path)==='.php');
  const failures=[];
  for(const path of phpFiles){
    try{engine.parseCode(readFileSync(path,'utf8'),path);}
    catch(error){failures.push({path:relative(root,path),error:error.message});}
  }
  report.checks.phpParser={total:phpFiles.length,passed:phpFiles.length-failures.length,failed:failures.length,failures};
  if(failures.length)blocked=true;
  console.log(`php-parser ${failures.length?'FAIL':'PASS'}: ${phpFiles.length-failures.length}/${phpFiles.length}`);
}catch(error){report.checks.phpParser={error:error.message};blocked=true;console.error('FAIL php-parser',error.message);}
const token=await php([join(root,'tests/support/token-parse-tree.php'),root]);
try{report.checks.tokenParse=JSON.parse(token.stdout);}catch{report.checks.tokenParse={error:token.stderr||token.stdout};}
if(token.exitCode!==0)blocked=true;
console.log(`PHP8.3 TOKEN_PARSE ${token.exitCode===0?'PASS':'FAIL'}`);

const baselineJs=JSON.parse(readFileSync(join(root,'tests/preinstall-baseline-js.json'),'utf8'));
const jsFiles=files.filter(path=>['.js','.mjs','.cjs'].includes(extname(path))
  && baselineJs[relative(root,path)]!==createHash('sha256').update(readFileSync(path)).digest('hex'));
const jsFailures=[];
for(const path of jsFiles){const result=await run(process.execPath,['--check',path]);if(result.exitCode!==0)jsFailures.push({path:relative(root,path),...result});}
report.checks.js={total:jsFiles.length,passed:jsFiles.length-jsFailures.length,failed:jsFailures.length,failures:jsFailures};
if(jsFailures.length)blocked=true;
console.log(`node --check ${jsFailures.length?'FAIL':'PASS'}: ${jsFiles.length-jsFailures.length}/${jsFiles.length}`);

const businessRoots=['modules/effective-sales/','modules/negotiation-master/'];
const violations=[],brandingReferences=[];
const guardRequire=createRequire(import.meta.url);
const GuardEngine=guardRequire(isAbsolute(options.parser)?resolve(options.parser):options.parser);
const guardParser=new GuardEngine({parser:{version:'8.3',suppressErrors:false}});
function positiveLiteral(node){
  if(node?.kind==='cast')return positiveLiteral(node.expr);
  return ['number','string'].includes(node?.kind)&&/^\d+$/.test(String(node.value))&&Number(node.value)>0;
}
function scenarioReference(node){
  if(node?.kind==='cast')return scenarioReference(node.expr);
  if(node?.kind==='variable')return typeof node.name==='string'&&/scenario(?:_?version)?_?id$/i.test(node.name);
  if(['offsetlookup','propertylookup','nullsafepropertylookup'].includes(node?.kind)){
    const value=node.offset?.value??node.offset?.name;
    return typeof value==='string'&&/scenario(?:_?version)?_?id$/i.test(value);
  }
  return false;
}
function inspectScenarioAst(node,path){
  if(!node||typeof node!=='object')return;
  if(node.kind==='assign'&&scenarioReference(node.left)&&positiveLiteral(node.right))violations.push({path,reason:'hardcoded scenario ID assignment'});
  if(node.kind==='bin'&&['==','===','!=','!==','??'].includes(node.type)
    &&((scenarioReference(node.left)&&positiveLiteral(node.right))||(scenarioReference(node.right)&&positiveLiteral(node.left))))
    violations.push({path,reason:'hardcoded scenario ID comparison/default'});
  if(node.kind==='entry'&&/scenario(?:_?version)?_?id$/i.test(node.key?.value??'')&&positiveLiteral(node.value))violations.push({path,reason:'hardcoded scenario ID array value'});
  if(node.kind==='parameter'&&/scenario(?:_?version)?_?id$/i.test(node.name?.name??node.name??'')&&positiveLiteral(node.value))violations.push({path,reason:'hardcoded scenario ID parameter default'});
  if(node.kind==='call'&&/in_array$/i.test(node.what?.name??'')&&scenarioReference(node.arguments?.[0])
    &&node.arguments?.[1]?.kind==='array'&&node.arguments[1].items.some(item=>positiveLiteral(item.value)))
    violations.push({path,reason:'hardcoded scenario ID allow-list'});
  if(node.kind==='switch'&&scenarioReference(node.test)&&(node.body?.children||[]).some(branch=>positiveLiteral(branch.test)))
    violations.push({path,reason:'hardcoded scenario ID switch case'});
  for(const value of Object.values(node))if(value&&typeof value==='object'){
    if(Array.isArray(value))value.forEach(item=>inspectScenarioAst(item,path));else inspectScenarioAst(value,path);
  }
}
for(const path of files){
  const name=relative(root,path);
  if(!['.php','.js','.mjs'].includes(extname(path))||!businessRoots.some(prefix=>name.startsWith(prefix))
    ||/\/(?:tests|content|migrations)\//.test(name))continue;
  const source=readFileSync(path,'utf8');
  if(/\bleogorn\b/i.test(source))violations.push({path:name,reason:'test-user dependency'});
  const productionUrls=source.match(/https?:\/\/(?:www\.)?ckkm\.ru[^\s'"<>]*/gi)||[];
  for(const url of productionUrls){
    if(name.endsWith('/public/app-shell.php')&&url==='https://ckkm.ru/wp-content/uploads/2026/09/CKKM-logo-icon.webp')
      brandingReferences.push({path:name,url,reason:'existing presentation logo; not a business/runtime endpoint'});
    else violations.push({path:name,reason:'production URL dependency',url});
  }
  const hostScan=name.endsWith('/public/app-shell.php')?source.replaceAll('https://ckkm.ru/wp-content/uploads/2026/09/CKKM-logo-icon.webp',''):source;
  if(/\b(?:[a-z0-9-]+\.)*ckkm\.ru\b/i.test(hostScan))violations.push({path:name,reason:'production hostname dependency'});
  if(/\bscenario(?:_version)?_id\s*(?:=\s*['"]?[1-9]\d*|IN\s*\(\s*['"]?[1-9]\d*)/i.test(source))violations.push({path:name,reason:'hardcoded scenario ID in SQL'});
  if(extname(path)==='.php'){
    try{inspectScenarioAst(guardParser.parseCode(source,path),name);}catch(error){violations.push({path:name,reason:'architecture AST parse failed',error:error.message});}
  }else if(/scenario_?id(?:['"]\])?\s*(?:===?|!==?|=>|:|\?\?|\|\|)\s*['"]?[1-9]\d*\b|['"]?[1-9]\d*['"]?\s*(?:===?|!==?)\s*[^;\n]*scenario_?id|in_array\s*\([^,]*scenario_?id[^,]*,\s*\[\s*[1-9]/i.test(source))
    violations.push({path:name,reason:'hardcoded scenario ID in JavaScript'});
}
report.checks.architectureGuards={scope:businessRoots,excludedDirectories:['tests','content','migrations'],failed:violations.length,violations,brandingReferences};
if(violations.length)blocked=true;
console.log(`Architecture source guards ${violations.length?'FAIL':'PASS'}`);

const inventory=JSON.parse(readFileSync(join(root,'tests/preinstall-original-suites.json'),'utf8'));
if(inventory.count!==254||inventory.paths.length!==254||new Set(inventory.paths).size!==254)throw new Error('Historical suite inventory changed');
for(const path of inventory.paths)if(!existsSync(join(root,path)))throw new Error('Historical suite removed: '+path);
const directories=['tests','modules/effective-sales/tests','modules/negotiation-master/tests'];
const discovered=directories.flatMap(folder=>readdirSync(join(root,folder),{withFileTypes:true})
  .filter(entry=>entry.isFile()&&(entry.name.endsWith('.php')||/-(?:test|regression)\.(?:mjs|cjs|js)$/.test(entry.name)))
  .map(entry=>folder+'/'+entry.name));
const suites=[...new Set([...inventory.paths,...discovered])].sort();
let cursor=0;
await Promise.all(Array.from({length:options.jobs},async()=>{
  while(cursor<suites.length){
    const path=suites[cursor++];
    const result=path.endsWith('.php')?await php([join(root,path)]):await run(process.execPath,[join(root,path),'--php',options.php]);
    const output=result.stdout+result.stderr;
    const failureText=/^\s*(?:FAIL\b|(?:PHP )?(?:Fatal error|Parse error|Warning|Notice):)/m.test(output);
    const skipText=/^\s*SKIP(?:PED)?\b/im.test(output);
    const status=result.timedOut?'timeout':result.exitCode!==0||failureText?'fail':skipText?'skip':'pass';
    const log=path.replaceAll('/','__')+'.log';
    writeFileSync(join(options.output,log),output);
    report.results.push({path,status,exitCode:result.exitCode,log,error:result.error||null});
    report.counts[status]++;
    if(status!=='pass')console.error(`${status.toUpperCase()} ${path} exit=${result.exitCode}`);
    else console.log(`PASS ${path}`);
  }
}));
report.results.sort((a,b)=>a.path.localeCompare(b.path));
report.total=suites.length;report.originalSuites=inventory.paths.length;
// A waiver never hides or changes the failing test's exit status.
const waiverPath=join(root,'tests/PREINSTALL-WAIVERS.json');
const waivers=existsSync(waiverPath)?JSON.parse(readFileSync(waiverPath,'utf8')):[];
for(const waiver of waivers){
  if(!waiver.path||!waiver.reason||!waiver.evidence||waiver.category!=='moved-architecture')throw new Error('Incomplete architecture waiver');
}
report.waivers=waivers;
const failures=report.results.filter(result=>result.status!=='pass');
const unwaived=failures.filter(result=>!waivers.some(waiver=>waiver.path===result.path&&result.status==='fail'));
report.status=!blocked&&failures.length===0?'PASS':!blocked&&unwaived.length===0?'PASS_WITH_DOCUMENTED_WAIVERS':'FAIL';
writeFileSync(join(options.output,'results.json'),JSON.stringify(report,null,2)+'\n');
console.log(`Gate ${report.status}: ${report.counts.pass} PASS / ${report.counts.fail} FAIL / ${report.counts.skip} SKIP / ${report.counts.timeout} timeout; ${waivers.length} documented waivers.`);
process.exit(report.status==='FAIL'?1:0);
