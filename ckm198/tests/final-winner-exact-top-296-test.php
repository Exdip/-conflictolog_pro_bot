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
function ok296($v,$m){static $n=0;$n++;if(!$v){fwrite(STDERR,"FAIL $n: $m\n");exit(1);}echo "PASS $n: $m\n";}
function rev296($speaker,$slot){return ['status'=>'done','review'=>['participants'=>['speaker'=>['total'=>$speaker,'slot'=>$slot]],'total'=>$speaker]];}
$s=ckmqp_show_initial();$s['teamCount']=2;$s['round']=3;$s['attempt']=1;$s['phase']='game_complete';
// Two teams, each round has a completed score. A totals 47, B totals 50.
foreach([0,1] as $a){$slot=$a+1;$s['reviews'][$a]=rev296(0,$slot);$s['hiddenReviews'][$a]=rev296(0,$slot);$s['hardReviews'][$a]=rev296(0,$slot);$s['storyResults'][$a]=['attempt'=>$a,'storytellerSlot'=>$slot,'mode'=>'truth','modeLabel'=>'Соответствует досье','votes'=>[]];}
$s['storyReviews'][0]=rev296(47,1);$s['storyReviews'][1]=rev296(50,2);
$g=['id'=>236,'game_code'=>'QUIZ-4Y209','title'=>'[ТЕСТ] Двойка, которой не было','status'=>'finished','host_mode_snapshot'=>'ai'];
$teams=[['id'=>364,'team_key'=>'A','team_name'=>'Команда A','slot_no'=>1],['id'=>365,'team_key'=>'B','team_name'=>'Команда B','slot_no'=>2]];
$o=ckmqp_show_dialogue_project($g,['game_id'=>236,'role'=>'scoreboard'],$teams,$s);
ok296($o['negotiationShow']['winningScore']===50,'winning score is 50');
ok296($o['negotiationShow']['winners']===['Команда B'],'47:50 has one overall winner: team B');
ok296(($o['negotiationShow']['winnerMarginRule']??null)===0,'overall winner uses exact-top rule');
$s['storyReviews'][0]=rev296(50,1);
$o=ckmqp_show_dialogue_project($g,['game_id'=>236,'role'=>'scoreboard'],$teams,$s);
ok296($o['negotiationShow']['winners']===['Команда A','Команда B'],'50:50 is an exact shared first place');
echo "ALL PASS\n";
