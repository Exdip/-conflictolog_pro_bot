<?php
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/');
function add_action(...$x){}
function ckm_quiz_json_decode($s){return json_decode($s,true)?:[];}
require dirname(__DIR__).'/includes/persuade-me-content.php';
require dirname(__DIR__).'/includes/negotiation-show.php';
require dirname(__DIR__).'/includes/negotiation-show-dialogue.php';
require dirname(__DIR__).'/includes/negotiation-show-story.php';
$n=0;function ck174($b,$label){global $n;if(!$b){fwrite(STDERR,"FAIL $label\n");exit(1);}echo 'PASS '.(++$n)." $label\n";}
function a174($slot){return ['role'=>'participant','slot'=>$slot,'team_id'=>100+$slot];}
function x174($s,$slot,$cmd,$at,$extra=[]){return ckmqp_show_reduce($s,a174($slot),array_merge(['command'=>$cmd,'round'=>$s['round'],'attempt'=>$s['attempt'],'question_index'=>$s['questionIndex']??($s['storyQuestionIndex']??0)],$extra),$at);}

// Rounds 1-2: no mandatory prep timer.
$s=ckmqp_show_initial();foreach([1,2,3] as $slot){$r=x174($s,$slot,'ready',100);ck174($r['ok'],'initial ready '.$slot);$s=$r['session'];}
ck174($s['phase']==='dialogue'&&(int)$s['deadline']===0,'round 1 opens directly into untimed dialogue');
$s['phase']='review';$s['reviews'][0]=['status'=>'done','review'=>['total'=>0],'completedAt'=>101];
$r=ckmqp_show_reduce($s,['role'=>'host'],['command'=>'advance','round'=>0,'attempt'=>0],102);$s=$r['session'];
ck174($s['phase']==='dialogue'&&(int)$s['deadline']===0,'next round-1 attempt also skips prep countdown');
$s['round']=0;$s['attempt']=2;$s['phase']='review';foreach([0,1,2] as $i)$s['reviews'][$i]=['status'=>'done','review'=>['total'=>0],'completedAt'=>103];
$r=ckmqp_show_reduce($s,['role'=>'host'],['command'=>'advance','round'=>0,'attempt'=>2],104);$s=$r['session'];
ck174((int)$s['round']===1&&$s['phase']==='dialogue'&&(int)$s['deadline']===0,'round 2 opens directly into untimed dialogue');

// Round 3: «Неудобный вопрос» is untimed; legacy timer settings are ignored.
$g=['format_settings_snapshot_json'=>'{"persuadeHardAnswerSeconds":45}'];
ck174(ckmqp_show_hard_answer_limit_from_game($g)===0,'legacy hard-answer timer setting is ignored');
ck174(ckmqp_show_story_answer_limit_from_game(['format_settings_snapshot_json'=>'{"persuadeStoryAnswerLimitEnabled":true,"persuadeStoryAnswerSeconds":30}'])===30,'story-answer enabled setting reads 30 seconds');
ck174(ckmqp_show_hard_answer_limit_from_game(['format_settings_snapshot_json'=>'{}'])===0,'hard-answer default is untimed');
ck174(ckmqp_show_story_answer_limit_from_game(['format_settings_snapshot_json'=>'{}'])===0,'story-answer default is untimed');
ck174(ckmqp_show_hard_answer_limit_from_game(['format_settings_snapshot_json'=>'{"persuadeHardAnswerSeconds":31}'])===0,'invalid legacy hard-answer setting is ignored');
ck174(ckmqp_show_story_answer_limit_from_game(['format_settings_snapshot_json'=>'{"persuadeStoryAnswerLimitEnabled":true,"persuadeStoryAnswerSeconds":25}'])===30,'invalid enabled story-answer setting falls back safely');

$h=ckmqp_show_initial();$h['round']=2;$h['attempt']=0;$h=ckmqp_show_begin_attempt($h,2,0,200);
ck174($h['phase']==='hard_answer'&&$h['deadline']===0,'round 3 starts without a deadline');
$h['hardAnswerLimit']=30;$h=ckmqp_show_begin_attempt($h,2,0,300);
ck174($h['deadline']===0,'legacy hard-answer limit cannot re-enable the timer');

// Round 4: prep/story/questions/answers/vote untimed by default; answer pressure is opt-in.
$f=ckmqp_show_initial();$f['round']=3;$f['attempt']=0;$f=ckmqp_show_begin_attempt($f,3,0,400);$f['storyModes']=['truth','distortion','truth'];
ck174($f['phase']==='preparation'&&$f['deadline']===0,'round 4 preparation untimed');
$r=x174($f,1,'story_ready',401);$f=$r['session'];ck174($f['phase']==='story_tell'&&$f['deadline']===0,'story untimed by default');
$r=x174($f,1,'story_submit',402,['text'=>'История','request_id'=>'timer_policy_story_001']);$f=$r['session'];ck174($f['phase']==='story_questions'&&$f['deadline']===0,'question formulation untimed');
foreach([[2,'q1'],[3,'q2'],[2,'q3'],[3,'q4']] as $i=>$row){$r=x174($f,$row[0],'story_question',403+$i,['text'=>$row[1].'?','request_id'=>'timer_policy_question_'.$i]);$f=$r['session'];}
ck174($f['phase']==='story_answer'&&$f['deadline']===0,'storyteller answer is untimed by default');
for($q=0;$q<4;$q++){$r=x174($f,1,'story_answer',407+$q,['question_index'=>$q,'text'=>'Ответ '.$q,'request_id'=>'timer_policy_answer_'.$q]);$f=$r['session'];}
ck174($f['phase']==='story_vote'&&$f['deadline']===0,'secret vote has no forced timer');
echo "ALL $n PASS\n";
