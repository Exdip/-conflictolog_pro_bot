<?php
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
require_once dirname(__DIR__) . '/modules/negotiation-master/domain/commitment-service.php';

use CKM\NegotiationMaster\CommitmentService;

$checks = 0; $fails = 0;
function c337(bool $ok, string $label): void { global $checks,$fails; $checks++; if(!$ok){$fails++; fwrite(STDERR,"FAIL: $label\n");} }

$service = new CommitmentService();
$state = [];
$message = ['id'=>10,'actor'=>'player','content'=>'Я обеспечу обучение команды до пятницы.'];
$r = $service->apply($state,$message,[['action'=>'create','kind'=>'result','summary'=>'Обеспечить обучение команды','deadline'=>'пятница','confidence'=>0.94]]);
c337($r['changed'] === true, 'explicit result commitment applied');
c337(count($r['state']['commitments'] ?? []) === 1, 'one commitment stored');
c337(($r['state']['commitments'][0]['status'] ?? '') === 'active', 'commitment active');
c337(($r['events'][0]['event_type'] ?? '') === 'commitment_made', 'commitment event');

$rDup = $service->apply($r['state'],$message,[['action'=>'create','kind'=>'result','summary'=>'Обеспечить обучение команды','deadline'=>'пятница','confidence'=>0.94]]);
c337($rDup['changed'] === false, 'duplicate message does not duplicate commitment');

$effort = $service->apply($r['state'],['id'=>11,'actor'=>'opponent','content'=>'Постараюсь ускорить внутреннее согласование.'],[[
    'action'=>'create','kind'=>'effort','summary'=>'Ускорить внутреннее согласование','confidence'=>0.91
]]);
c337($effort['changed'] === true, 'effort commitment applied');
c337(($effort['state']['commitments'][1]['kind'] ?? '') === 'effort', 'effort kind retained');

$intention = $service->apply($effort['state'],['id'=>12,'actor'=>'player','content'=>'Планирую обсудить это с руководителем.'],[[
    'action'=>'create','kind'=>'result','summary'=>'Обсудить с руководителем','confidence'=>0.97
]]);
c337($intention['changed'] === false, 'mere intention is not result commitment');

$conditional = $service->apply($effort['state'],['id'=>13,'actor'=>'player','content'=>'Если вы дадите 40% предоплаты, мы предоставим расширенный сервис.'],[[
    'action'=>'create','kind'=>'result','summary'=>'Предоставить расширенный сервис','condition'=>'Предоплата 40%','confidence'=>0.93
]]);
c337($conditional['changed'] === true, 'conditional commitment applied');
$cid=(int)($conditional['state']['commitments'][2]['id'] ?? 0);
c337(($conditional['events'][0]['event_type'] ?? '') === 'conditional_commitment', 'conditional event');

$risk = $service->apply($conditional['state'],['id'=>14,'actor'=>'player','content'=>'Расширенный сервис предоставить не сможем.'],[[
    'action'=>'break','commitment_id'=>$cid,'condition_satisfied'=>false,'confidence'=>0.96
]]);
c337(($risk['state']['commitments'][2]['status'] ?? '') === 'at_risk', 'conditional promise is at risk when trigger unconfirmed');
c337(($risk['events'][0]['event_type'] ?? '') === 'commitment_at_risk', 'at-risk event emitted');

$broken = $service->apply($risk['state'],['id'=>15,'actor'=>'player','content'=>'Условие выполнено, но расширенный сервис предоставить не сможем.'],[[
    'action'=>'break','commitment_id'=>$cid,'condition_satisfied'=>true,'confidence'=>0.96
]]);
c337(($broken['state']['commitments'][2]['status'] ?? '') === 'broken', 'conditional promise broken only with trigger confirmed');
c337(($broken['events'][0]['event_type'] ?? '') === 'commitment_broken', 'broken event emitted');

$clarify = $service->apply($r['state'],['id'=>16,'actor'=>'player','content'=>'Уточню: обучение проведём до 17:00 пятницы.'],[[
    'action'=>'clarify','commitment_id'=>1,'summary'=>'Провести обучение команды','deadline'=>'пятница, 17:00','confidence'=>0.90
]]);
c337($clarify['changed'] === true, 'commitment clarification applied');
c337(($clarify['events'][0]['event_type'] ?? '') === 'commitment_clarified', 'clarification event emitted');

$projection = CommitmentService::publicProjection($broken['state']);
c337(count($projection) === 3, 'public projection contains explicit commitments only');
c337(!array_key_exists('hidden', $projection[0] ?? []), 'projection contains no hidden field');

$root = dirname(__DIR__);
$plugin = file_get_contents($root.'/ckm-quiz-pro.php');
$bootstrap = file_get_contents($root.'/modules/negotiation-master/bootstrap.php');
$prompt = file_get_contents($root.'/modules/negotiation-master/ai/arbiter/arbiter-prompt-builder.php');
$validator = file_get_contents($root.'/modules/negotiation-master/ai/arbiter/arbiter-validator.php');
$snapshot = file_get_contents($root.'/modules/negotiation-master/application/player-session-snapshot-builder.php');
$player = file_get_contents($root.'/modules/negotiation-master/public/player-page.php');
$js = file_get_contents($root.'/modules/negotiation-master/assets/negotiation-session.js');
$coach = file_get_contents($root.'/modules/negotiation-master/ai/coach/coach-prompt-builder.php');
$opp = file_get_contents($root.'/modules/negotiation-master/ai/opponent/opponent-context-builder.php');

c337(ckm_test_current_plugin_release($plugin),'plugin build updated');
c337(str_contains($bootstrap,"domain/commitment-service.php"),'commitment service bootstrapped');
c337(str_contains($prompt,'commitment_updates'),'arbiter prompt requests commitment updates');
c337(str_contains($validator,"'create','clarify','break'"),'validator whitelists commitment actions');
c337(str_contains($snapshot,"'commitments' => CommitmentService::publicProjection"),'player snapshot projects commitments');
c337(str_contains($player,'>Обязательства</h4>'),'right panel has Russian commitments heading');
c337(str_contains($js,"Пока нет зафиксированных обязательств."),'UI renders commitments in Russian');
c337(str_contains($coach,"'visible_commitments'"),'coach receives only visible commitments');
c337(str_contains($opp,"'commitments' => CommitmentService::publicProjection"),'opponent receives validated commitments');

fwrite(STDOUT, sprintf("NEG-COMMITMENTS: %d/%d PASS\n", $checks-$fails, $checks));
exit($fails ? 1 : 0);
