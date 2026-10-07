<?php
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
require_once dirname(__DIR__) . '/modules/negotiation-master/domain/reopen-policy.php';

use CKM\NegotiationMaster\ReopenPolicy;

$checks=0;$fails=0;
function r338(bool $ok,string $label):void{global $checks,$fails;$checks++;if(!$ok){$fails++;fwrite(STDERR,"FAIL: $label\n");}}

r338(ReopenPolicy::normalize('never')==='never','never policy retained');
r338(ReopenPolicy::normalize('free_until_final')==='free_until_final','free policy retained');
r338(ReopenPolicy::normalize('explicit_mutual_confirmation')==='explicit_mutual_confirmation','mutual policy retained');
r338(ReopenPolicy::normalize('unknown')==='with_reason','unknown policy safely defaults');
r338(ReopenPolicy::decision('never','new_information')==='deny','never denies reopening');
r338(ReopenPolicy::decision('free_until_final','none')==='immediate','free reopens immediately');
r338(ReopenPolicy::decision('with_reason','none')==='deny','reason policy rejects empty reason');
r338(ReopenPolicy::decision('with_reason','new_risk')==='immediate','reason policy accepts grounded reason');
r338(ReopenPolicy::decision('explicit_mutual_confirmation','new_information')==='request','mutual policy creates request');
r338(ReopenPolicy::validReasonType('scope_change'),'reason whitelist accepts scope change');
r338(!ReopenPolicy::validReasonType('because_i_want'),'reason whitelist rejects arbitrary reason');
r338(ReopenPolicy::sanitizeReason('<b>Новый риск</b>')==='Новый риск','reason is sanitized');

$root=dirname(__DIR__);
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$boot=file_get_contents($root.'/modules/negotiation-master/bootstrap.php');
$item=file_get_contents($root.'/modules/negotiation-master/domain/item-state-service.php');
$rule=file_get_contents($root.'/modules/negotiation-master/domain/rule-engine.php');
$prompt=file_get_contents($root.'/modules/negotiation-master/ai/arbiter/arbiter-prompt-builder.php');
$validator=file_get_contents($root.'/modules/negotiation-master/ai/arbiter/arbiter-validator.php');
$snapshot=file_get_contents($root.'/modules/negotiation-master/application/player-session-snapshot-builder.php');
$nodeal=file_get_contents($root.'/modules/negotiation-master/application/no-deal-service.php');
$opp=file_get_contents($root.'/modules/negotiation-master/ai/opponent/opponent-prompt-builder.php');
$builder=file_get_contents($root.'/modules/negotiation-master/public/builder-page.php');
$builderService=file_get_contents($root.'/modules/negotiation-master/application/scenario-builder-service.php');
$js=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-session.js');

r338(ckm_test_current_plugin_release($plugin),'plugin build updated');
r338(str_contains($boot,"domain/reopen-policy.php"),'reopen policy bootstrapped');
r338(str_contains($prompt,'reopen_request')&&str_contains($prompt,'reopen_confirm')&&str_contains($prompt,'reopen_reject'),'arbiter prompt understands reopen lifecycle');
r338(str_contains($validator,"'reopen_request','reopen_confirm','reopen_reject'"),'validator whitelists reopen actions');
r338(str_contains($item,"\$newStatus = 'reopen_requested'")&&str_contains($item,"\$newStatus = 'reopened'"),'item service persists reopen states');
r338(str_contains($item,"\$current['agreed'] =")&&str_contains($item,"'confirmed_by'=>[\$other,\$actor]"),'explicit acceptance creates item-level agreement');
r338(str_contains($rule,"'event_type'=>'item_reopened'")&&str_contains($rule,"'event_type'=>'item_agreed'"),'server derives reopen and agreement events');
r338(str_contains($rule,"'event_type'=>'unjustified_reopen_attempt'"),'denied reopen is audited');
r338(str_contains($snapshot,"\$safe['reopen_request']")&&str_contains($snapshot,"\$safe['previous_agreed']"),'player snapshot exposes only safe reopen state');
r338(str_contains($nodeal,"['agreed','reopen_requested']"),'pending mutual reopen keeps old agreement valid for no-deal snapshot');
r338(str_contains($opp,"status='agreed'")&&str_contains($opp,'reopen_request'),'opponent is told not to silently rewrite agreed terms');
r338(str_contains($builder,'Только по взаимному подтверждению')&&str_contains($builderService,'explicit_mutual_confirmation'),'builder supports mutual reopen policy');
r338(str_contains($js,'запросил пересмотр')&&str_contains($js,'условие пересматривается'),'Russian UI renders reopen state');

fwrite(STDOUT,sprintf("NEG-REOPEN: %d/%d PASS\n",$checks-$fails,$checks));
exit($fails?1:0);
