<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
$root = dirname(__DIR__, 3);
$passed = 0;
function s511(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }
$main = file_get_contents($root . '/ckm-quiz-pro.php');
$sound = file_get_contents($root . '/assets/completion-signal/completion-signal.js');
$prompt = file_get_contents($root . '/modules/negotiation-master/ai/opponent/opponent-prompt-builder.php');
$pack = require $root . '/modules/negotiation-master/content/system-v1.php';
s511('plugin version', ckm_test_current_plugin_release($main));
s511('audio recreates closed context', str_contains($sound, "audioCtx&&audioCtx.state==='closed'") && str_contains($sound, "if(audioCtx.state!=='running')await audioCtx.resume()"));
s511('sales prompt avoids replaying revealed facts', str_contains($prompt, 'reveal_level=2') && str_contains($prompt, 'не повторяй его дословно'));
s511('content pack 1.50.0', ($pack['pack_version'] ?? '') === '1.50.0');
$noNeed = null;
foreach ((array)($pack['scenarios'] ?? []) as $entry) { if (($entry['scenario']['slug'] ?? '') === 'no-need') { $noNeed = $entry; break; } }
s511('no-need version 2', is_array($noNeed) && (int)($noNeed['version']['version_number'] ?? 0) === 2);
$reply = (string)($noNeed['version']['mechanics_json']['progress_replies']['next_step_accept'] ?? '');
s511('next step uses only 15 minutes', str_contains($reply, 'на 15 минут') && !str_contains($reply, 'на час'));
require $root . '/modules/negotiation-master/ai/opponent/sales-client-fallback.php';
$fallback = new CKM\NegotiationMaster\SalesClientFallback();
$fact = ['code'=>'manual_control','title'=>'Ручной контроль','content'=>'Елена успевает вручную прослушивать только 5–7 звонков в неделю.','reveal_rules'=>['partial'=>'Вопрос о контроле качества разговоров','revealed'=>'Уточнение, сколько звонков реально проверяется вручную']];
$ctx = ['mechanics'=>['training_domain'=>'sales'],'identity'=>['name'=>'Елена Соколова'],'hidden_facts'=>[$fact],'validated_state'=>['discovered_facts'=>[['code'=>'manual_control','title'=>'Ручной контроль','reveal_level'=>2]]],'external_position'=>['statement'=>'Спасибо, но нам ничего не нужно.']];
$r1 = $fallback->buildFactReply($ctx, 'А как вы сейчас понимаете, какие звонки менеджеров стоит проверить в первую очередь?');
s511('fully revealed fact does not hijack adjacent question', $r1 === '');
$r2 = $fallback->buildFactReply($ctx, 'Сколько звонков вы успеваете прослушивать вручную?');
s511('direct re-question may repeat revealed fact', str_contains($r2, '5–7 звонков'));
echo "{$passed} SALES-511 regression checks passed. No database required.\n";
