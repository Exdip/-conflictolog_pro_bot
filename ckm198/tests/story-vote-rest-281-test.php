<?php
require_once __DIR__.'/support/plugin-release.php';
// This feature survives later releases; validate the current header/constant contract.
$root=dirname(__DIR__);
$js=file_get_contents($root.'/assets/standalone-game.js');
$php=file_get_contents($root.'/includes/negotiation-show-dialogue.php');
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$checks=[];
function t281($ok,$label){global $checks;$checks[]=[$ok,$label];echo ($ok?'PASS':'FAIL')." | $label\n";}
t281(ckm_test_current_plugin_release($main),'current plugin release header and constant are synchronized');
t281(str_contains($js,"/wp-json/ckm-quiz-pro/v1/show-story-vote"),'client uses story-vote REST route');
t281(str_contains($js,"command==='story_vote'?postStoryVote(payload)"),'story_vote dispatches through REST');
t281(str_contains($js,"command==='story_ready'||command==='story_vote'"),'polling wait applies to story_ready and story_vote');
t281(str_contains($php,"register_rest_route('ckm-quiz-pro/v1','/show-story-vote'"),'REST route is registered');
t281(str_contains($php,"'command'=>'story_vote'"),'REST route calls original story_vote reducer command');
t281(str_contains($php,"ckm_quiz_pro_validate_team_request"),'REST route keeps team token/nonce validation');
t281(str_contains($php,"ckm_quiz_get_membership"),'REST route keeps participant membership validation');
$failed=array_filter($checks,fn($x)=>!$x[0]);
exit($failed?1:0);
