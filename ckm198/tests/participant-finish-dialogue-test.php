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
require dirname(__DIR__).'/includes/negotiation-show-story.php';
$n=0;function ok245($b,$label){global $n;if(!$b){fwrite(STDERR,"FAIL $label\n");exit(1);}echo 'PASS '.(++$n)." $label\n";}
function actor245($slot){return ['role'=>'participant','team_id'=>100+$slot,'slot'=>$slot];}
function finish245($s,$slot,$now=100){return ckmqp_show_reduce($s,actor245($slot),['command'=>'finish_dialogue','round'=>(int)$s['round'],'attempt'=>(int)$s['attempt']],$now);}

// Round 1: either dialogue participant may finish; observer may not.
$s=ckmqp_show_initial();$s['round']=0;$s['attempt']=0;$s['phase']='dialogue';
$r=finish245($s,1);ok245(!empty($r['ok'])&&$r['session']['phase']==='review'&&$r['session']['dialogueClosedBy']==='participant','round 1 participant can finish dialogue');
$r=finish245($s,2);ok245(!empty($r['ok'])&&$r['session']['phase']==='review','round 1 opponent can finish dialogue');
$r=finish245($s,3);ok245(empty($r['ok'])&&($r['code']??'')==='observer_read_only','round 1 observer cannot finish dialogue');

// Round 2: active hidden-task team may finish, other teams cannot end it for them.
$s=ckmqp_show_initial();$s['round']=1;$s['attempt']=0;$s['phase']='dialogue';
$r=finish245($s,1);ok245(!empty($r['ok'])&&$r['session']['phase']==='review','round 2 active team can finish dialogue');
$r=finish245($s,2);ok245(empty($r['ok'])&&($r['code']??'')==='active_team_required','round 2 non-active team cannot prematurely finish');

// Round 3: active team may stop answering; remaining questions become explicit early-finish blanks.
$s=ckmqp_show_initial();$s['round']=2;$s['attempt']=0;$s['phase']='hard_answer';$s['questionIndex']=1;$s['deadline']=0;
$s['hardAnswers'][0][0]=['questionIndex'=>0,'text'=>'Первый ответ','at'=>90,'timedOut'=>false];
$r=finish245($s,1);$rs=$r['session'];
ok245(!empty($r['ok'])&&$rs['phase']==='review','round 3 active team can finish answer block');
ok245(count($rs['hardAnswers'][0])===3&&!empty($rs['hardAnswers'][0][1]['endedEarly'])&&!empty($rs['hardAnswers'][0][2]['endedEarly']),'round 3 remaining answers are marked endedEarly');

// Round 4: each checker may signal no more questions independently.
$s=ckmqp_show_initial();$s['round']=3;$s['attempt']=0;$s['phase']='story_questions';$s['storyModes'][0]='truth';$s['storyStories'][0]=['text'=>'История','at'=>1];
$r=finish245($s,2);ok245(!empty($r['ok'])&&$r['session']['phase']==='story_questions'&&!empty($r['session']['storyQuestionDone'][0][2]),'round 4 first checker can finish own questions without ending stage for other checker');
$r=finish245($r['session'],3);ok245(!empty($r['ok'])&&$r['session']['phase']==='story_vote','round 4 with no questions proceeds to mandatory vote, not directly to review');

// If there is at least one question, finishing question collection opens answer stage.
$s=ckmqp_show_initial();$s['round']=3;$s['attempt']=0;$s['phase']='story_questions';$s['storyModes'][0]='truth';$s['storyStories'][0]=['text'=>'История','at'=>1];
$s['storyQuestions'][0][2][]=['text'=>'Один вопрос?','at'=>2,'requestId'=>'q-1'];
$r=finish245($s,2);$r=finish245($r['session'],3);$rs=$r['session'];
ok245($rs['phase']==='story_answer'&&count(ckmqp_show_story_question_order($rs,0))===1,'round 4 early question finish preserves existing questions');
$r=finish245($rs,1);ok245(!empty($r['ok'])&&$r['session']['phase']==='story_vote'&&!empty($r['session']['storyAnswers'][0][0]['endedEarly']),'round 4 storyteller can end remaining answers and still proceeds to vote');

// Public projection exposes the button only to the participant who is allowed to use it.
$game=['id'=>7,'game_code'=>'DEMO','title'=>'Переговори другого','status'=>'live','host_mode_snapshot'=>'ai'];
$teams=[];foreach([1,2,3] as $i)$teams[]=['id'=>100+$i,'team_key'=>chr(64+$i),'team_name'=>'Команда '.chr(64+$i),'slot_no'=>$i];
$s=ckmqp_show_initial();$s['round']=0;$s['attempt']=0;$s['phase']='dialogue';
$o=ckmqp_show_dialogue_project($game,['game_id'=>7,'role'=>'participant','team_id'=>101],$teams,$s);ok245(!empty($o['negotiationShow']['canFinishDialogue']),'round 1 UI enables finish for speaker');
$o=ckmqp_show_dialogue_project($game,['game_id'=>7,'role'=>'participant','team_id'=>103],$teams,$s);ok245(empty($o['negotiationShow']['canFinishDialogue']),'round 1 UI hides finish from observer');

// Scoring invariant remains 4 x 70 = 280.
function reviewed245($sp,$op,$spSlot,$opSlot){return ['status'=>'done','review'=>['participants'=>['speaker'=>['total'=>$sp,'slot'=>$spSlot],'opponent'=>['total'=>$op,'slot'=>$opSlot]],'total'=>$sp,'rubricVersion'=>2]];}
$f=ckmqp_show_initial();$f['reviews']=[reviewed245(70,70,1,2),reviewed245(70,70,2,3),reviewed245(70,70,3,1)];$f['hiddenReviews']=$f['reviews'];
foreach([0,1,2] as $a){$slot=$a+1;$f['hardReviews'][$a]=['status'=>'done','review'=>['participants'=>['speaker'=>['total'=>70,'slot'=>$slot]],'total'=>70]];$f['storyReviews'][$a]=['status'=>'done','review'=>['participants'=>['speaker'=>['total'=>70,'slot'=>$slot]],'total'=>70]];}
ok245(ckmqp_show_story_cumulative_score($f,1)===280,'maximum remains R1+R2+R3+R4 = 280');

echo "ALL $n PASS\n";
