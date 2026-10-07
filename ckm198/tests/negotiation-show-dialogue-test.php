<?php
if (PHP_SAPI!=='cli') exit;
define('ABSPATH',__DIR__.'/');
function add_action(...$args){}
function ckm_quiz_json_decode($s){return json_decode($s,true)?:[];}
require dirname(__DIR__).'/includes/persuade-me-content.php';
require dirname(__DIR__).'/includes/negotiation-show.php';
require dirname(__DIR__).'/includes/negotiation-show-dialogue.php';
require dirname(__DIR__).'/includes/negotiation-show-review.php';
require dirname(__DIR__).'/includes/negotiation-show-hidden.php';
require dirname(__DIR__).'/includes/negotiation-show-hard-question.php';
require dirname(__DIR__).'/includes/negotiation-show-story.php';
$n=0;
function check($ok,$name){global $n;if(!$ok){fwrite(STDERR,"FAIL $name\n");exit(1);}echo 'PASS '.(++$n)." $name\n";}
function actor($slot){return ['role'=>'participant','slot'=>$slot,'team_id'=>100+$slot];}
function action($s,$slot,$cmd,$now,$extra=[]){return ckmqp_show_reduce($s,actor($slot),array_merge(['command'=>$cmd,'attempt'=>$s['attempt']],$extra),$now);}
$s=ckmqp_show_initial();
$r=action($s,1,'message',100,['text'=>'рано','request_id'=>'request_before_start']);check(!$r['ok']&&$r['code']==='dialogue_closed','cannot speak before readiness');
foreach([1,2] as $i)$s=action($s,$i,'ready',100)['session'];
check($s['phase']==='waiting','two ready teams do not start');
$r=action($s,1,'ready',101);check($r['session']===$s,'repeated readiness is idempotent');
$s=action($s,3,'ready',102)['session'];check($s['phase']==='dialogue'&&$s['deadline']===0,'third ready opens free dialogue immediately without prep countdown');
check((int)$s['dialogueStartedAt']===0,'untimed dialogue does not start an idle clock before the first message');
$r=action($s,3,'message',133,['text'=>'подсказка','request_id'=>'request_observer_1']);check(!$r['ok']&&$r['code']==='observer_read_only','observer cannot speak');
$r=action($s,1,'message',133,['text'=>'Предлагаю договориться','request_id'=>'request_speaker_1']);check($r['ok']&&count($r['session']['messages'])===1,'speaker message accepted');$s=$r['session'];
$r=action($s,1,'message',134,['text'=>'Предлагаю договориться','request_id'=>'request_speaker_1']);check($r['ok']&&$r['session']===$s,'network retry adds no duplicate');
$r=action($s,1,'message',134,['text'=>'Другой текст','request_id'=>'request_speaker_1']);check(!$r['ok']&&$r['code']==='request_conflict','request ID cannot overwrite text');
$r=action($s,2,'message',135,['text'=>'Согласен','request_id'=>'request_opponent_1']);check($r['ok'],'opponent can speak');$s=$r['session'];
$r=action($s,1,'message',136,['text'=>'','request_id'=>'request_empty_001']);check(!$r['ok'],'empty message rejected');
$r=action($s,1,'message',136,['text'=>'a','request_id'=>'x']);check(!$r['ok']&&$r['code']==='request_id_invalid','invalid id rejected');
$r=action($s,1,'message',136,['text'=>str_repeat('a',8001),'request_id'=>'request_large_001']);check(!$r['ok'],'large payload rejected');
$r=action($s,1,'pause',137);check(!$r['ok']&&$r['code']==='host_required','team cannot pause as host');
$r=ckmqp_show_reduce($s,['role'=>'host'],['command'=>'pause','attempt'=>0],140);check(!$r['ok']&&$r['code']==='phase_closed','dialogue has no pause because it has no timer');
$r=action($s,1,'message',10000,['text'=>'Диалог продолжается без таймера','request_id'=>'request_late_free_01']);check($r['ok']&&$r['session']['phase']==='dialogue','free dialogue does not expire');$s=$r['session'];
$r=ckmqp_show_reduce($s,['role'=>'participant','slot'=>1,'team_id'=>101],['command'=>'finish_dialogue','attempt'=>0],10001);check($r['ok']&&$r['session']['phase']==='review'&&$r['session']['dialogueClosedBy']==='participant','participant explicitly finishes free dialogue');$s=$r['session'];
$r=ckmqp_show_reduce($s,['role'=>'host'],['command'=>'finish_dialogue','attempt'=>0],10002);check(!$r['ok']&&$r['code']==='phase_closed','closed dialogue cannot be finished twice');
$r=action($s,1,'message',10003,['text'=>'Предлагаю договориться','request_id'=>'request_speaker_1']);check($r['ok']&&count($r['session']['messages'])===3,'retry accepted after close without duplicate');
$limited=ckmqp_show_initial();$limited['dialogueLimit']=90;foreach([1,2,3] as $i)$limited=action($limited,$i,'ready',20000)['session'];check($limited['phase']==='dialogue'&&$limited['deadline']===20090,'optional dialogue limit starts immediately when explicitly enabled');$limited=ckmqp_show_tick($limited,20090);check($limited['phase']==='review'&&$limited['dialogueClosedBy']==='timer','optional limit closes dialogue at configured deadline');
$ai=ckmqp_show_initial();$ai['phase']='dialogue';$ai['dialogueStartedAt']=1;$ai['messages']=[
 ['id'=>1,'teamId'=>101,'slot'=>1,'round'=>0,'attempt'=>0,'text'=>'Нужны данные к 16:00','at'=>2,'requestId'=>'ai_close_message_01'],
 ['id'=>2,'teamId'=>102,'slot'=>2,'round'=>0,'attempt'=>0,'text'=>'Могу к 16:00','at'=>3,'requestId'=>'ai_close_message_02'],
 ['id'=>3,'teamId'=>101,'slot'=>1,'round'=>0,'attempt'=>0,'text'=>'Тогда фиксирую срок','at'=>4,'requestId'=>'ai_close_message_03'],
 ['id'=>4,'teamId'=>102,'slot'=>2,'round'=>0,'attempt'=>0,'text'=>'Согласен, договорились','at'=>5,'requestId'=>'ai_close_message_04'],
];check(ckmqp_show_ai_dialogue_should_finish($ai),'AI host closure heuristic recognizes explicit agreement');
$restore=json_decode(json_encode($s),true);check($restore===$s,'stored session round trips without loss');
$s['reviews'][0]=['status'=>'done','review'=>['total'=>0],'completedAt'=>223];
$r=action($s,1,'continue',224);check(!$r['ok']&&($r['code']??'')==='confirmation_removed','team confirmation removed after review');
$r=ckmqp_show_reduce($s,['role'=>'host'],['command'=>'advance','round'=>0,'attempt'=>0],225);$s=$r['session'];check($r['ok']&&$s['phase']==='dialogue'&&$s['deadline']===0&&$s['attempt']===1&&$s['ready']===[]&&count($s['messages'])===3,'single host action opens next untimed dialogue and retains transcript');
$r=action($s,1,'ready',226);check(!$r['ok']&&$r['code']==='ready_not_needed','next attempt does not require readiness');
$r=ckmqp_show_reduce($s,['role'=>'host'],['command'=>'pause','round'=>0,'attempt'=>0],226);check(!$r['ok']&&$r['code']==='stale_attempt','stale host action rejected');
foreach([0,1,2] as $attempt){
 $roles=[];foreach([1,2,3] as $slot)$roles[]=ckmqp_show_role($slot,$attempt);
 check(count(array_unique($roles))===3,'unique roles in attempt '.$attempt);
 check(ckmqp_show_role($attempt+1,$attempt)==='speaker','speaker rotates in attempt '.$attempt);
}
for($attempt=1;$attempt<=2;$attempt++){
 $s=ckmqp_show_reduce($s,['role'=>'host'],['command'=>'finish_dialogue','round'=>0,'attempt'=>$attempt],300*$attempt+31)['session'];
 $s['reviews'][$attempt]=['status'=>'done','review'=>['total'=>0],'completedAt'=>300*$attempt+31];
 $r=ckmqp_show_reduce($s,['role'=>'host'],['command'=>'advance','round'=>0,'attempt'=>$attempt],300*$attempt+32);check($r['ok'],'host advances attempt '.$attempt);$s=$r['session'];
}
check($s['round']===1&&$s['attempt']===0&&$s['phase']==='dialogue'&&$s['deadline']===0,'third review opens hidden-task dialogue directly without prep timer');
$r=action($s,1,'ready',999);check(!$r['ok']&&$r['code']==='ready_not_needed','next round cannot request repeated readiness');
$g=['id'=>7,'game_code'=>'DEMO','title'=>'Переговори другого','status'=>'waiting','host_mode_snapshot'=>'ai'];
$teams=[];foreach([1,2,3] as $i)$teams[]=['id'=>100+$i,'team_key'=>chr(64+$i),'team_name'=>'Команда '.chr(64+$i),'slot_no'=>$i,'team_token'=>'SECRET_TOKEN'];
foreach([0,1,2] as $attempt){
 $session=ckmqp_show_initial();$session['attempt']=$attempt;
 $brief=ckmqp_show_cases()[$attempt]['opponent'];
 foreach([1,2,3] as $slot){
  $o=ckmqp_show_dialogue_project($g,['game_id'=>7,'role'=>'participant','team_id'=>100+$slot],$teams,$session);
  $allowed=ckmqp_show_role($slot,$attempt)==='opponent';
  check(($o['negotiationShow']['brief']===$brief)===$allowed,'private opponent brief scoped attempt '.$attempt.' slot '.$slot);
 }
 foreach(['host','scoreboard'] as $role){$o=ckmqp_show_dialogue_project($g,['game_id'=>7,'role'=>$role],$teams,$session);check(!isset($o['negotiationShow']['brief']),'no brief for '.$role.' attempt '.$attempt);}
}
$o=ckmqp_show_dialogue_project($g,['game_id'=>99,'role'=>'participant','team_id'=>102],$teams,ckmqp_show_initial());check(!isset($o['negotiationShow']['brief']),'foreign room auth gets no brief');
$o=ckmqp_show_dialogue_project($g,['game_id'=>7,'role'=>'scoreboard'],$teams,$s);
check(count($o['negotiationShow']['messages'])===3,'completed transcript available to board');
check(!str_contains(json_encode($o),'request_speaker_1')&&!str_contains(json_encode($o),'SECRET_TOKEN'),'projection excludes request IDs and credentials');
$s=ckmqp_show_initial();$s['phase']='dialogue';$s['deadline']=500;
$s['messages']=array_fill(0,100,['teamId'=>102,'requestId'=>'old_request_001','attempt'=>0,'text'=>'old']);
$r=action($s,1,'message',400,['text'=>'new','request_id'=>'request_limit_001']);check(!$r['ok']&&$r['code']==='message_limit','bounded transcript rejects 101st message');
echo "ALL $n PASS\n";
