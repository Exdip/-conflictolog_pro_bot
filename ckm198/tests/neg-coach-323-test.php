<?php
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit; }
$root = dirname(__DIR__);
$base = $root.'/modules/negotiation-master';
$checks=[];
function c323(array &$checks,bool $ok,string $label):void{$checks[]=[$ok,$label];echo($ok?'PASS':'FAIL').": $label\n";}
$files=[
 'ai/coach/in-game-coach-context-builder.php','ai/coach/coach-prompt-builder.php','ai/coach/coach-response-validator.php','ai/coach/coach-service.php'
];
foreach($files as $file)c323($checks,is_file($base.'/'.$file),'coach file '.$file);
$bootstrap=file_get_contents($base.'/bootstrap.php');
$controller=file_get_contents($base.'/api/session-controller.php');
$context=file_get_contents($base.'/ai/coach/in-game-coach-context-builder.php');
$prompt=file_get_contents($base.'/ai/coach/coach-prompt-builder.php');
$service=file_get_contents($base.'/ai/coach/coach-service.php');
$repo=file_get_contents($base.'/repositories.php');
$snapshot=file_get_contents($base.'/application/player-session-snapshot-builder.php');
$opctx=file_get_contents($base.'/ai/opponent/opponent-context-builder.php');
$arbctx=file_get_contents($base.'/ai/arbiter/arbiter-context-builder.php');
$schema=json_decode(file_get_contents($base.'/schema.json'),true);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$js=file_get_contents($base.'/assets/negotiation-session.js');
$page=file_get_contents($base.'/public/player-page.php');

c323($checks,ckm_test_current_plugin_release($main),'plugin version bumped to dev.323');
c323($checks,ckm_test_declared_version_at_least($bootstrap,'CKM_NEG_DB_VERSION','1.2.0') && str_contains($bootstrap,'ai/coach/coach-service.php'),'schema 1.2 and coach bootstrapped');
c323($checks,isset($schema['messages']['columns']['coach_level'],$schema['messages']['columns']['related_message_id']),'coach metadata columns present');
c323($checks,($schema['messages']['indexes']['session_coach_related']??null)===['session_id','channel','related_message_id'],'coach lookup index present');
c323($checks,str_contains($controller,"/negotiation/sessions/(?P<id>\\d+)/coach") && str_contains($controller,'COACH_NOT_AVAILABLE_IN_EXAM'),'coach endpoint and server exam denial present');
c323($checks,str_contains($service,"mode'] ?? '') !== 'training'") && str_contains($service,'CoachNotAvailableException'),'coach service blocks exam mode before AI');
c323($checks,str_contains($service,'ckm_quiz_pro_aipuffer_post') && str_contains($service,"'temperature'=>0.35"),'coach reuses server AI transport');
c323($checks,str_contains($service,"['attention','direction','example','review_last_move']"),'four coach help levels enforced');
c323($checks,str_contains($context,'$this->snapshots->build($sessionId)') && str_contains($context,"'visible_facts'") && str_contains($context,"'player_card'"),'coach context derives from player-safe snapshot');
foreach(['opponent_hidden_interests_json','opponent_constraints_json','opponent_alternative_json','opponent_concession_space_json','opponent_walkaway_json','reveal_rules_json','opponent_boundary_json','opponent_target_json'] as $secret){
 c323($checks,!str_contains($context,$secret),'coach context source has no secret field: '.$secret);
}
c323($checks,str_contains($prompt,'Если в данных чего-то нет, не угадывай это как факт') && str_contains($prompt,"'visible_facts'"),'prompt requires hypotheses instead of secret claims');
c323($checks,str_contains($repo,"channel='coach' AND actor='coach'") && str_contains($repo,'appendCoach'),'coach channel persisted separately');
c323($checks,str_contains($repo,"channel IN ('dialogue','negotiation')") && str_contains($opctx,'listNegotiationForContext') && str_contains($arbctx,'listNegotiationForContext'),'opponent and arbiter contexts stay negotiation-only');
c323($checks,str_contains($snapshot,"'coach_messages'") && str_contains($snapshot,"'coach_counts'") && str_contains($snapshot,"'can_use_coach'"),'safe snapshot exposes separate coach projection');
c323($checks,str_contains($js,"data-coach-level")===false ? str_contains($page,'data-coach-level') : true,'coach controls present in UI');
c323($checks,str_contains($js,"/coach") && str_contains($js,"snapshot.session?.mode!=='training'"),'browser calls coach only for training session');
c323($checks,str_contains($page,'ИИ-тренер') && str_contains($page,'Показать пример реплики'),'training UI exposes coach menu');
c323($checks,!str_contains($service,'updateState(') && !str_contains($service,'ArbiterService'),'coach service cannot mutate negotiation state or invoke arbiter');
$runtime=$context.$prompt.$service;
foreach(['contract-supply','Алексей Петров','ТехноИмпульс'] as $needle)c323($checks,!str_contains($runtime,$needle),'no scenario literal in coach runtime: '.$needle);
$failed=array_filter($checks,fn($x)=>!$x[0]);
echo count($checks)." checks, ".count($failed)." failed.\n";
exit($failed?1:0);
