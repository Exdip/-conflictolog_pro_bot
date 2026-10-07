<?php
require_once __DIR__ . '/support/plugin-release.php';
define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__) . '/modules/negotiation-master/ai/arbiter/arbiter-response-parser.php';
use CKM\NegotiationMaster\ArbiterResponseParser;

$checks = 0;
function a353(bool $ok, string $label): void { global $checks; $checks++; if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); } }

$payload = [
    'message'=>['message_id'=>1,'actor'=>'player'],
    'semantic_units'=>[], 'events'=>[], 'fact_updates'=>[], 'deal_updates'=>[],
    'commitment_updates'=>[], 'rule_signals'=>[],
    'dialogue_state'=>['tension'=>'normal','walkaway_risk'=>'low'],
];
$json = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$p = new ArbiterResponseParser();
a353($p->parse($json)['message']['message_id'] === 1, 'direct JSON');
a353($p->parse("```json\n{$json}\n```")['message']['actor'] === 'player', 'fenced JSON');
a353($p->parse("Результат:\n{$json}\nГотово")['dialogue_state']['tension'] === 'normal', 'prose wrapped JSON');
$double = json_encode($json, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
a353($p->parse($double)['message']['message_id'] === 1, 'double encoded AI Puffer JSON');
$thrown=false; try { $p->parse('{"message":'); } catch (UnexpectedValueException) { $thrown=true; }
a353($thrown, 'truncated JSON rejected');

$root = dirname(__DIR__);
$plugin = file_get_contents($root.'/ckm-quiz-pro.php');
a353(ckm_test_current_plugin_release($plugin),'plugin version');
$player = file_get_contents($root.'/modules/negotiation-master/public/player-page.php');
a353(str_contains($player,"'itemLabels' => \$itemLabels"),'item labels localized');
a353(str_contains($player,"'valueLabels' => \$valueLabels"),'value labels localized');
$js = file_get_contents($root.'/modules/negotiation-master/assets/negotiation-session.js');
a353(str_contains($js,'function treeKeyLabel(key)'),'runtime key labels');
a353(str_contains($js,"min:'не ниже'"),'runtime min label');
a353(str_contains($js,"max:'не выше'"),'runtime max label');
$arbiter = file_get_contents($root.'/modules/negotiation-master/ai/arbiter/arbiter-service.php');
a353(str_contains($arbiter,"'failure_stage'=>\$failureStage"),'safe failure stage diagnostics');

echo "NEG-LIVE-ARBITER-353 {$checks}/{$checks} PASS\n";
