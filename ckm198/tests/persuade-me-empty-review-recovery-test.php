<?php
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/');
function add_action(...$x){}
function ckm_quiz_json_decode($s){return json_decode($s,true)?:[];}
require dirname(__DIR__).'/includes/persuade-me-content.php';
require dirname(__DIR__).'/includes/negotiation-show.php';
require dirname(__DIR__).'/includes/negotiation-show-dialogue.php';
require dirname(__DIR__).'/includes/negotiation-show-review.php';
require dirname(__DIR__).'/includes/negotiation-show-hidden.php';
require dirname(__DIR__).'/includes/negotiation-show-hard-question.php';
require dirname(__DIR__).'/includes/negotiation-show-manual-review.php';
$n=0;function ck167($b,$label){global $n;if(!$b){fwrite(STDERR,"FAIL $label\n");exit(1);}echo 'PASS '.(++$n)." $label\n";}
$s=ckmqp_show_initial();$s['round']=1;$s['attempt']=1;$s['phase']='review';$s['hiddenReviews'][0]=['status'=>'done','review'=>['total'=>300],'completedAt'=>90];$s['hiddenReviews'][1]=['status'=>'failed','error'=>'empty review','retryAfter'=>130];
ck167(ckmqp_show_can_restart_empty_failed_attempt($s),'empty failed current hidden review is recoverable');
$r=ckmqp_show_reduce($s,['role'=>'host'],['command'=>'restart_attempt','round'=>1,'attempt'=>1],100);$x=$r['session'];
ck167($r['ok']&&$x['round']===1&&$x['attempt']===1&&$x['phase']==='dialogue'&&$x['deadline']===0,'host restarts same attempt directly in untimed dialogue');
ck167(($x['hiddenReviews'][0]['status']??'')==='done'&&!isset($x['hiddenReviews'][1]),'prior score preserved and broken current review cleared');
$blocked=ckmqp_show_reduce($s,['role'=>'participant','slot'=>2,'team_id'=>102],['command'=>'restart_attempt','round'=>1,'attempt'=>1],100);ck167(!$blocked['ok']&&($blocked['code']??'')==='host_required','participant cannot recover attempt');
$s2=$s;$s2['messages'][]=['id'=>2,'teamId'=>102,'slot'=>2,'round'=>1,'attempt'=>1,'text'=>'Есть реплика','at'=>99,'requestId'=>'recovery_message_001'];
ck167(!ckmqp_show_can_restart_empty_failed_attempt($s2),'nonempty failed dialogue is not silently restartable');
$g=['id'=>7,'game_code'=>'DEMO','title'=>'Переговори другого','status'=>'waiting','host_mode_snapshot'=>'human'];$teams=[];foreach([1,2,3] as $i)$teams[]=['id'=>100+$i,'team_key'=>chr(64+$i),'team_name'=>'Команда '.chr(64+$i),'slot_no'=>$i];
$o=ckmqp_show_dialogue_project($g,['game_id'=>7,'role'=>'host'],$teams,$s)['negotiationShow'];ck167(!empty($o['canRestartAttempt']),'host projection exposes exceptional recovery');
$o=ckmqp_show_dialogue_project($g,['game_id'=>7,'role'=>'participant','team_id'=>102],$teams,$s)['negotiationShow'];ck167(empty($o['canRestartAttempt']),'participant projection cannot restart');
$js=file_get_contents(dirname(__DIR__).'/assets/standalone-game.js');
ck167(str_contains($js,"r.source!=='manual'&&String(r.recommendation||'').trim()!==''"),'manual recommendation is suppressed including legacy stored value');
ck167(str_contains($js,'showRestartAttempt')&&str_contains($js,"showAction('restart_attempt')"),'recovery button wired to server action');
echo "ALL $n PASS\n";
