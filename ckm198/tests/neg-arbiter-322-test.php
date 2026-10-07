<?php
if (PHP_SAPI !== 'cli') { exit; }
$root = dirname(__DIR__);
$base = $root.'/modules/negotiation-master';
$checks=[];
function c322(array &$checks,bool $ok,string $label):void{$checks[]=[$ok,$label];echo($ok?'PASS':'FAIL').": $label\n";}
$files=[
 'ai/arbiter/arbiter-service.php','ai/arbiter/arbiter-context-builder.php','ai/arbiter/arbiter-prompt-builder.php','ai/arbiter/arbiter-response-parser.php','ai/arbiter/arbiter-validator.php',
 'domain/event-service.php','domain/item-state-service.php','domain/fact-state-service.php','domain/rule-engine.php'
];
foreach($files as $file)c322($checks,is_file($base.'/'.$file),'arbiter file '.$file);
$bootstrap=file_get_contents($base.'/bootstrap.php');
$message=file_get_contents($base.'/application/message-service.php');
$recovery=file_get_contents($base.'/application/recovery-service.php');
$arbiter=file_get_contents($base.'/ai/arbiter/arbiter-service.php');
$validator=file_get_contents($base.'/ai/arbiter/arbiter-validator.php');
$rules=file_get_contents($base.'/domain/rule-engine.php');
$snapshot=file_get_contents($base.'/application/player-session-snapshot-builder.php');
$opctx=file_get_contents($base.'/ai/opponent/opponent-context-builder.php');
$eventsvc=file_get_contents($base.'/domain/event-service.php');
c322($checks,str_contains($bootstrap,'arbiter-service.php')&&str_contains($bootstrap,'rule-engine.php'),'bootstrap loads arbiter/domain layer');
c322($checks,str_contains($message,'$this->recovery->continueTurnLocked')&&substr_count($recovery,'$this->arbiter->analyze')===2&&str_contains($recovery,'$this->opponent->ensureReply'),'message pipeline analyzes around opponent reply');
c322($checks,strpos($recovery,'$this->arbiter->analyze')<strpos($recovery,'$this->opponent->ensureReply'),'player analyzed before opponent generation');
c322($checks,str_contains($arbiter,'ckm_quiz_pro_aipuffer_post'),'arbiter reuses server AI transport');
c322($checks,str_contains($arbiter,"'temperature'=>0.05")&&str_contains($arbiter,'for ($attempt=0;$attempt<2;$attempt++)'),'low-temperature JSON pass with one retry');
c322($checks,str_contains($validator,'acceptance_candidate')&&str_contains($validator,'willing_to_consider'),'acceptance/willingness distinction guarded');
c322($checks,str_contains($rules,'red_line_candidate')&&str_contains($rules,'conditional_concession'),'PHP derives red-line and concession events');
c322($checks,str_contains($eventsvc,'session_id=%d AND message_id=%d AND event_key=%s'),'event idempotency enforced before insert');
c322($checks,str_contains($opctx,"'validated_state'")&&!str_contains($message,'scenario_slug'),'opponent receives validated state without scenario branches');
c322($checks,str_contains($snapshot,"unset(\$row['hidden_fact_id'], \$row['code'])"),'partial fact technical identifiers hidden from player');
$runtime='';foreach(array_merge($files,['application/message-service.php','application/player-session-snapshot-builder.php','ai/opponent/opponent-context-builder.php']) as $f)$runtime.=file_get_contents($base.'/'.$f);
foreach(['contract-supply','Алексей Петров','ТехноИмпульс'] as $needle)c322($checks,!str_contains($runtime,$needle),'no scenario literal in runtime: '.$needle);
$failed=array_filter($checks,fn($x)=>!$x[0]);
echo count($checks)." checks, ".count($failed)." failed.\n";
exit($failed?1:0);
