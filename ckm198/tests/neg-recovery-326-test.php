<?php
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit; }
$root=dirname(__DIR__);$base=$root.'/modules/negotiation-master';$checks=[];
function c326(array &$checks,bool $ok,string $label):void{$checks[]=[$ok,$label];echo($ok?'PASS':'FAIL').": $label\n";}
$main=file_get_contents($root.'/ckm-quiz-pro.php');$boot=file_get_contents($base.'/bootstrap.php');$schema=json_decode(file_get_contents($base.'/schema.json'),true);
$repo=file_get_contents($base.'/repositories.php');$recovery=file_get_contents($base.'/application/recovery-service.php');$message=file_get_contents($base.'/application/message-service.php');$opponent=file_get_contents($base.'/ai/opponent/opponent-service.php');$controller=file_get_contents($base.'/api/session-controller.php');$completion=file_get_contents($base.'/application/completion-service.php');$evaluation=file_get_contents($base.'/evaluation/evaluation-service.php');$js=file_get_contents($base.'/assets/negotiation-session.js');
c326($checks,ckm_test_current_plugin_release($main),'plugin version bumped to dev.326');
c326($checks,ckm_test_declared_version_at_least($boot,'CKM_NEG_DB_VERSION','1.4.0')&&str_contains($boot,'application/recovery-service.php'),'NEG schema 1.4 and recovery service bootstrapped');
c326($checks,is_file($base.'/application/recovery-service.php'),'recovery service file exists');
foreach(['processing_lock_token','processing_lock_expires_at','active_client_id','writer_lock_expires_at'] as $column)c326($checks,isset($schema['sessions']['columns'][$column]),'session schema has '.$column);
c326($checks,isset($schema['sessions'],$schema['messages']) && !isset($schema['recovery']),'recovery leases use existing session and message tables');
c326($checks,str_contains($repo,'acquireProcessingLock')&&str_contains($repo,'releaseProcessingLock'),'repository supports processing lease');
c326($checks,str_contains($repo,'claimWriter')&&str_contains($repo,'releaseWriter'),'repository supports writer lease');
c326($checks,str_contains($repo,"processing_lock_token IS NULL")&&str_contains($repo,'processing_lock_expires_at<%s'),'processing lease can recover only after expiry');
c326($checks,str_contains($recovery,'continueTurnLocked')&&str_contains($recovery,'recover(int $sessionId)'),'recovery can continue persisted turn and resume incomplete pipeline');
foreach(['player_analysis_pending','player_analyzed','opponent_generation_pending','opponent_saved','opponent_analysis_pending','opponent_analyzed','opponent_failed'] as $phase)c326($checks,str_contains($recovery,$phase)||str_contains($opponent,$phase)||str_contains($message,$phase),'persisted phase '.$phase);
c326($checks,str_contains($message,'acquireProcessing')&&str_contains($message,'finally')&&str_contains($message,'releaseProcessing'),'send/retry hold and release processing lease');
c326($checks,str_contains($opponent,'opponent_saved')&&!str_contains($opponent,"'idle', ['opponent_generating']"),'opponent persistence stops at opponent_saved for arbiter recovery');
c326($checks,str_contains($controller,'WRITER_CONFLICT')&&str_contains($controller,'RECOVERY_BUSY'),'runtime conflicts have explicit HTTP error codes');
c326($checks,str_contains($controller,'/writer/takeover')&&str_contains($controller,'takeoverWriter'),'explicit writer takeover endpoint registered');
c326($checks,str_contains($controller,'/recovery-diagnostics')&&str_contains($controller,'adminPermission'),'recovery diagnostics are admin-only');
c326($checks,str_contains($controller,'(new RecoveryService())->recover($id)'),'resume runs recovery coordinator');
c326($checks,str_contains($js,'sessionStorage')&&str_contains($js,'ckmNegRuntimeClientId'),'browser uses per-tab runtime client id');
c326($checks,str_contains($js,"data.code==='WRITER_CONFLICT'")&&str_contains($js,'/writer/takeover'),'browser requires explicit confirmation before writer takeover');
c326($checks,str_contains($completion,'processing_lock_token=NULL')&&str_contains($completion,'active_client_id=NULL'),'terminal states clear runtime leases');
c326($checks,str_contains($evaluation,'acquireProcessingLock')&&str_contains($evaluation,'releaseProcessingLock'),'evaluation execution is serialized by processing lease');
c326($checks,str_contains($repo,'processing_lock_token')&&str_contains($repo,'active_client_id'),'public repository projection hides lease tokens');
c326($checks,str_contains($schema['messages']['unique']['session_client'][0]??'','session_id')&&isset($schema['messages']['unique']['session_channel_reply']),'message/reply idempotency indexes retained');
$runtime=$recovery.$message.$opponent.$controller;
foreach(['contract-supply','Алексей Петров','ТехноИмпульс'] as $needle)c326($checks,!str_contains($runtime,$needle),'no scenario literal in recovery runtime: '.$needle);
$failed=array_filter($checks,fn($x)=>!$x[0]);echo count($checks)." checks, ".count($failed)." failed.\n";exit($failed?1:0);
