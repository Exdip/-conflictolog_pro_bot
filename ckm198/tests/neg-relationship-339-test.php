<?php
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
require_once dirname(__DIR__) . '/modules/negotiation-master/domain/relationship-service.php';

use CKM\NegotiationMaster\RelationshipService;

$checks=0;$fails=0;
function r339(bool $ok,string $label):void{global $checks,$fails;$checks++;if(!$ok){$fails++;fwrite(STDERR,"FAIL: $label\n");}}

$service=new RelationshipService();
$base=['relationship'=>RelationshipService::initialState()];
$msg=['id'=>10,'actor'=>'player','content'=>'Личная атака'];
$r=$service->apply($base,$msg,[['event_type'=>'personal_attack','actor'=>'player','confidence'=>0.95]]);
r339($r['changed']===true,'personal attack changes relationship');
r339(($r['relationship']['tension']??'')==='high','personal attack raises tension to high');
r339(($r['relationship']['credibility']['player']??'')==='weakened','personal attack weakens actor credibility');
r339(count($r['events'])===2,'attack emits tension and credibility audit events');

$r2=$service->apply($r['state'],['id'=>11,'actor'=>'player','content'=>'Давайте вернёмся к сути.'],[['event_type'=>'deescalation','actor'=>'player','confidence'=>0.93]]);
r339(($r2['relationship']['tension']??'')==='normal','deescalation reduces high tension one step');
r339(($r2['relationship']['credibility']['player']??'')==='weakened','deescalation does not magically restore credibility');

$r3=$service->apply($r2['state'],['id'=>12,'actor'=>'opponent','content'=>'Обещание выполнить не сможем.'],[['event_type'=>'commitment_broken','actor'=>'opponent','confidence'=>1.0]]);
r339(($r3['relationship']['credibility']['opponent']??'')==='weakened','broken commitment weakens opponent credibility');
r339(($r3['relationship']['tension']??'')==='high','broken commitment increases tension');

$r4=$service->apply($base,['id'=>13,'actor'=>'player','content'=>'Предлагаю пакет.'],[['event_type'=>'package_offer_made','actor'=>'player','confidence'=>0.99]]);
r339($r4['changed']===false,'ordinary package offer does not gamify relationship');
r339(($r4['relationship']['tension']??'')==='normal','ordinary offer keeps tension normal');

$r5=$service->apply($base,['id'=>14,'actor'=>'player','content'=>'Ультиматум'],[['event_type'=>'ultimatum','actor'=>'player','confidence'=>0.50]]);
r339($r5['changed']===false,'low-confidence relationship event is ignored');

$projection=RelationshipService::internalProjection($r3['state']);
r339(array_keys($projection)===['tension','player_credibility','opponent_credibility'],'internal projection is minimal');
r339(!array_key_exists('hard_constraints',$projection),'projection cannot alter constraints');

$dynamics=RelationshipService::postGameDynamics([
  ['event_type'=>'tension_increased','actor'=>'player','message_id'=>10,'payload'=>['reason_event'=>'personal_attack']],
  ['event_type'=>'credibility_weakened','actor'=>'player','message_id'=>10,'payload'=>['reason_event'=>'personal_attack']],
  ['event_type'=>'tension_reduced','actor'=>'player','message_id'=>11,'payload'=>['reason_event'=>'deescalation']],
  ['event_type'=>'tension_increased','actor'=>'opponent','message_id'=>12,'payload'=>['reason_event'=>'commitment_broken']],
  ['event_type'=>'credibility_weakened','actor'=>'opponent','message_id'=>12,'payload'=>['reason_event'=>'commitment_broken']],
]);
r339(count($dynamics)===4,'post-game dynamics are capped at four');
r339(($dynamics[0]['evidence_message_ids'][0]??0)===10,'post-game dynamic preserves evidence message');

$root=dirname(__DIR__);
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$boot=file_get_contents($root.'/modules/negotiation-master/bootstrap.php');
$arbiter=file_get_contents($root.'/modules/negotiation-master/ai/arbiter/arbiter-service.php');
$oppCtx=file_get_contents($root.'/modules/negotiation-master/ai/opponent/opponent-context-builder.php');
$oppPrompt=file_get_contents($root.'/modules/negotiation-master/ai/opponent/opponent-prompt-builder.php');
$evalCtx=file_get_contents($root.'/modules/negotiation-master/evaluation/evaluation-context-builder.php');
$evalService=file_get_contents($root.'/modules/negotiation-master/evaluation/evaluation-service.php');
$player=file_get_contents($root.'/modules/negotiation-master/public/player-page.php');
$js=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-session.js');
$repos=file_get_contents($root.'/modules/negotiation-master/repositories.php');

r339(ckm_test_current_plugin_release($plugin),'plugin build updated');
r339(str_contains($boot,"domain/relationship-service.php"),'relationship service bootstrapped');
r339(str_contains($arbiter,'RelationshipService $relationships')&&str_contains($arbiter,"relationship_changed"),'arbiter applies formal relationship state');
r339(str_contains($oppCtx,"'relationship' => RelationshipService::internalProjection"),'opponent receives safe relationship projection');
r339(str_contains($oppPrompt,'Динамика отношений никогда не меняет'),'opponent prompt forbids relationship from changing hard rules');
r339(str_contains($evalCtx,"'relationship'=>RelationshipService::internalProjection"),'evaluation context receives relationship state');
r339(str_contains($evalService,"'relationship_dynamics'=>RelationshipService::postGameDynamics"),'evaluation summary contains dynamics');
r339(str_contains($player,'Динамика взаимодействия')&&str_contains($js,'relationship_dynamics'),'result UI renders dynamics only after game');
r339(!str_contains($js,'player_credibility')&&!str_contains($js,'opponent_credibility'),'live UI has no credibility gauge');
r339(substr_count($repos,"'relationship' => ['tension'=>'normal'")>=2 || str_contains($repos,"'relationship'=>['tension'=>'normal'"),'new runtime sessions start neutral');

fwrite(STDOUT,sprintf("NEG-RELATIONSHIP: %d/%d PASS\n",$checks-$fails,$checks));
exit($fails?1:0);
