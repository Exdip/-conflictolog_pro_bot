<?php
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit; }
$root=dirname(__DIR__);$base=$root.'/modules/negotiation-master';$checks=[];
function c324(array &$checks,bool $ok,string $label):void{$checks[]=[$ok,$label];echo($ok?'PASS':'FAIL').": $label\n";}
$files=['domain/agreement-validator.php','ai/opponent/agreement-response-service.php','application/completion-service.php','application/agreement-service.php','application/no-deal-service.php'];
foreach($files as $f)c324($checks,is_file($base.'/'.$f),'agreement file '.$f);
$main=file_get_contents($root.'/ckm-quiz-pro.php');$boot=file_get_contents($base.'/bootstrap.php');$schema=json_decode(file_get_contents($base.'/schema.json'),true);
$controller=file_get_contents($base.'/api/session-controller.php');$service=file_get_contents($base.'/application/agreement-service.php');$validator=file_get_contents($base.'/domain/agreement-validator.php');$completion=file_get_contents($base.'/application/completion-service.php');$nodeal=file_get_contents($base.'/application/no-deal-service.php');$repo=file_get_contents($base.'/repositories.php');$session=file_get_contents($base.'/application/session-service.php');$message=file_get_contents($base.'/application/message-service.php');$recovery=file_get_contents($base.'/application/recovery-service.php');$snapshot=file_get_contents($base.'/application/player-session-snapshot-builder.php');$js=file_get_contents($base.'/assets/negotiation-session.js');$page=file_get_contents($base.'/public/player-page.php');
c324($checks,ckm_test_current_plugin_release($main),'plugin version bumped to dev.324');
c324($checks,ckm_test_declared_version_at_least($boot,'CKM_NEG_DB_VERSION','1.3.0')&&str_contains($boot,'agreement-service.php')&&str_contains($boot,'no-deal-service.php'),'schema 1.3 and agreement services bootstrapped');
c324($checks,isset($schema['agreements']['columns']['state_revision']),'agreement snapshot stores state revision');
c324($checks,str_contains($controller,'/agreements/draft')&&str_contains($controller,'/agreements/propose'),'agreement REST endpoints registered');
c324($checks,str_contains($controller,'/finish-without-agreement/preview')&&str_contains($controller,'/finish-without-agreement/confirm'),'no-deal REST endpoints registered');
c324($checks,str_contains($controller,'STATE_CONFLICT')&&str_contains($controller,'SESSION_COMPLETED'),'critical state errors mapped');
c324($checks,str_contains($validator,'buildDraft')&&str_contains($validator,'item_state'),'draft derives from validated server item state');
c324($checks,str_contains($service,'appendAgreementProposal')&&!str_contains($controller,"package_json"),'client cannot submit authoritative agreement package');
c324($checks,str_contains($repo,"'input_type'=>'agreement'")&&str_contains($repo,'\'client_message_id\'=>$clientId'),'final package persisted as idempotent player action');
c324($checks,str_contains($service,"['accept','partial','reject']")===false && str_contains(file_get_contents($base.'/ai/opponent/agreement-response-service.php'),"['accept','partial','reject']"),'opponent final decision constrained to accept/partial/reject');
c324($checks,str_contains($completion,"status='completed_agreement'")&&str_contains($completion,"final_agreement_id=%d"),'accepted package completes session transactionally');
c324($checks,str_contains($completion,"red_line_breached")&&str_contains($validator,"player_boundary_json"),'player red-line breach is recorded');
c324($checks,str_contains($validator,'$enforceOpponentHardConstraints')&&str_contains($completion,'validatePackage($sessionId,$package,true)'),'hidden opponent hard limits enforced only at finalization');
c324($checks,str_contains($service,'validatePackage($sessionId,$package,true,false)'),'proposal stage does not leak hidden opponent hard limits');
c324($checks,str_contains($nodeal,'hash_hmac')&&str_contains($nodeal,"'rev'")&&str_contains($nodeal,"'seq'"),'no-deal confirmation token binds revision and dialogue position');
c324($checks,str_contains($completion,"completed_no_agreement_player")&&str_contains($completion,"completed_no_agreement_opponent"),'both no-deal terminal states supported');
c324($checks,str_contains($message,'$this->recovery->continueTurnLocked')&&str_contains($recovery,'isPlayerWalkawayCandidate')&&str_contains($js,'player_walkaway_candidate'),'textual player walkaway opens confirmation instead of auto-close');
c324($checks,str_contains($nodeal,'maybeCompleteOpponentWalkaway')&&str_contains($nodeal,'repeat_threshold'),'opponent walkaway requires server-checkable scenario rule');
c324($checks,str_contains($session,'str_starts_with((string)$session[\'status\'], \'completed_\')'),'completed session can resume read-only');
c324($checks,str_contains($snapshot,"'final_agreement'")&&str_contains($snapshot,"'can_create_agreement'")&&str_contains($snapshot,"'can_finish_without_agreement'"),'player snapshot exposes safe completion projection');
c324($checks,str_contains($page,'Зафиксировать соглашение')&&str_contains($page,'Завершить без соглашения'),'game UI exposes both completion branches');
c324($checks,str_contains($js,'/agreements/draft')&&str_contains($js,'/agreements/propose')&&str_contains($js,'/finish-without-agreement/confirm'),'browser uses server completion endpoints');
c324($checks,str_contains($completion,"evaluation_status='pending'") ,'completion prepares later evaluation without calculating it');
$runtime='';foreach(array_merge($files,['repositories.php','application/message-service.php','application/player-session-snapshot-builder.php']) as $f)$runtime.=file_get_contents($base.'/'.$f);
foreach(['contract-supply','Алексей Петров','ТехноИмпульс'] as $needle)c324($checks,!str_contains($runtime,$needle),'no scenario literal in agreement runtime: '.$needle);
$failed=array_filter($checks,fn($x)=>!$x[0]);echo count($checks)." checks, ".count($failed)." failed.\n";exit($failed?1:0);
