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
$n=0;function ok172($b,$label){global $n;if(!$b){fwrite(STDERR,"FAIL $label\n");exit(1);}echo 'PASS '.(++$n).' '.$label."\n";}
$s=ckmqp_show_initial();
$s['round']=3;$s['attempt']=0;$s['phase']='story_questions';$s['deadline']=0;$s['storyModes']=['distortion','truth','truth'];
$s['storyStories'][0]=['text'=>'','at'=>160,'timedOut'=>true];
$s['reviews'][0]=['status'=>'done','review'=>['total'=>200]];
$s['hiddenReviews'][0]=['status'=>'done','review'=>['total'=>300]];
$s['hardReviews'][0]=['status'=>'done','review'=>['total'=>100]];
ok172($s['phase']==='story_questions','legacy empty timed-out story state is recognized after upgrade');
ok172(!empty($s['storyStories'][0]['timedOut'])&&trim((string)$s['storyStories'][0]['text'])==='','legacy timed-out empty story is preserved until recovery');
ok172(ckmqp_show_can_restart_empty_failed_attempt($s),'host recovery eligibility recognizes empty timed-out story');
$participant=ckmqp_show_reduce($s,['role'=>'participant','slot'=>1,'team_id'=>101],['command'=>'restart_attempt','round'=>3,'attempt'=>0],170);
ok172(!$participant['ok']&&($participant['code']??'')==='host_required','participant cannot restart storyteller');
$beforeMode=$s['storyModes'][0];
$r=ckmqp_show_reduce($s,['role'=>'host'],['command'=>'restart_attempt','round'=>3,'attempt'=>0],170);
ok172($r['ok'],'host can restart empty timed-out story');
$s2=$r['session'];
ok172($s2['round']===3&&$s2['attempt']===0&&$s2['phase']==='preparation','same storyteller restarts at preparation');
ok172($s2['deadline']===0,'restart grants untimed preparation under the new final-round contract');
ok172(!isset($s2['storyStories'][0]),'timed-out story cleared');
ok172(($s2['storyModes'][0]??'')===$beforeMode,'secret truth/distortion mode preserved');
ok172(($s2['reviews'][0]['review']['total']??0)===200&&($s2['hiddenReviews'][0]['review']['total']??0)===300&&($s2['hardReviews'][0]['review']['total']??0)===100,'previous round scores preserved');
ok172(!empty($s2['recoveredTimedOutStory']),'recovery marker set');
// Once cross-examination actually starts, rewind must be blocked.
$s3=$s;$s3['storyQuestions'][0][2][]= ['text'=>'Где это было?','at'=>161,'requestId'=>'story_recovery_q_0001'];
ok172(!ckmqp_show_can_restart_empty_failed_attempt($s3),'recovery blocked after first cross-examination question');
$blocked=ckmqp_show_reduce($s3,['role'=>'host'],['command'=>'restart_attempt','round'=>3,'attempt'=>0],170);
ok172(!$blocked['ok']&&($blocked['code']??'')==='restart_unavailable','server rejects unsafe rewind after question');
// Projection exposes recovery only to this game's host/admin.
$game=['id'=>7,'game_code'=>'TEST','title'=>'Test','status'=>'live','host_mode_snapshot'=>'human'];
$teams=[];foreach([1,2,3] as $i)$teams[]=['id'=>100+$i,'team_key'=>chr(64+$i),'team_name'=>'Team '.$i,'slot_no'=>$i,'score'=>0];
$out=ckmqp_show_dialogue_project($game,['role'=>'host','game_id'=>7],$teams,$s)['negotiationShow'];
ok172(!empty($out['canRestartAttempt']),'current game host sees retry button');
$out=ckmqp_show_dialogue_project($game,['role'=>'participant','game_id'=>7,'team_id'=>101],$teams,$s)['negotiationShow'];
ok172(empty($out['canRestartAttempt']),'participant never sees retry button');
echo "ALL $n PASS\n";
