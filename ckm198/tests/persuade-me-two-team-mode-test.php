<?php
require_once __DIR__.'/support/plugin-release.php';
// This feature survives later releases; validate the current header/constant contract.
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
function t267($ok,$name){global $n;if(!$ok){fwrite(STDERR,"FAIL $name\n");exit(1);}echo 'PASS '.(++$n).' '.$name."\n";}
function a267($slot){return ['role'=>'participant','slot'=>$slot,'team_id'=>200+$slot];}
function do267($s,$slot,$command,$now,$extra=[]){return ckmqp_show_reduce($s,a267($slot),array_merge(['command'=>$command,'attempt'=>$s['attempt']],$extra),$now);}
function rr267($speaker,$opponent){return ['status'=>'done','review'=>['participants'=>['speaker'=>['total'=>$speaker],'opponent'=>['total'=>$opponent]]],'completedAt'=>1];}
function sr267($score){return ['status'=>'done','review'=>['participants'=>['speaker'=>['total'=>$score]]],'completedAt'=>1];}

$game=['team_count'=>2,'format_settings_snapshot_json'=>'{}'];
$s=ckmqp_show_initial($game);
t267(ckmqp_show_team_count($s)===2,'new room stores two-team contract');
t267(ckmqp_show_attempt_indexes($s)===[0,1] && ckmqp_show_slot_indexes($s)===[1,2],'only two attempts and two slots');
t267(ckmqp_show_role(1,0,2)==='speaker' && ckmqp_show_role(2,0,2)==='opponent','attempt 1 is A speaker / B opponent');
t267(ckmqp_show_role(2,1,2)==='speaker' && ckmqp_show_role(1,1,2)==='opponent','attempt 2 swaps A and B');
t267(!in_array('observer',[ckmqp_show_role(1,0,2),ckmqp_show_role(2,0,2),ckmqp_show_role(1,1,2),ckmqp_show_role(2,1,2)],true),'two-team room has no observer role');
$g267=['id'=>9,'game_code'=>'TWO','title'=>'Переговори другого','status'=>'waiting','host_mode_snapshot'=>'ai','team_count'=>2,'format_settings_snapshot_json'=>'{}'];
$teams267=[['id'=>201,'team_key'=>'A','team_name'=>'Команда A','slot_no'=>1],['id'=>202,'team_key'=>'B','team_name'=>'Команда B','slot_no'=>2]];
$pA=ckmqp_show_dialogue_project($g267,['game_id'=>9,'role'=>'participant','team_id'=>201],$teams267,$s);
$pB=ckmqp_show_dialogue_project($g267,['game_id'=>9,'role'=>'participant','team_id'=>202],$teams267,$s);
t267(($pA['negotiationShow']['roleTitle']??'')==='Переговорщик' && ($pB['negotiationShow']['roleTitle']??'')==='Оппонент','two-team UI exposes only negotiating roles');

$s=do267($s,1,'ready',100)['session'];
t267($s['phase']==='waiting','one ready team does not start');
$s=do267($s,2,'ready',101)['session'];
t267($s['phase']==='dialogue','both ready teams start round 1');

$s['reviews']=[rr267(70,60),rr267(68,72)];
t267(ckmqp_show_round1_team_score($s,1)===71,'team A averages its speaker and opponent evaluations');
t267(ckmqp_show_round1_team_score($s,2)===64,'team B averages its speaker and opponent evaluations');

$flow=ckmqp_show_initial($game);$flow=do267($flow,1,'ready',200)['session'];$flow=do267($flow,2,'ready',201)['session'];
$flow['phase']='review';$flow['reviews'][0]=rr267(70,70);
$step=ckmqp_show_reduce($flow,['role'=>'host'],['command'=>'advance','round'=>0,'attempt'=>0],202);$flow=$step['session'];
t267(!empty($step['ok']) && $flow['round']===0 && $flow['attempt']===1,'after first review the second team gets its turn');
$flow['phase']='review';$flow['reviews'][1]=rr267(70,70);
$step=ckmqp_show_reduce($flow,['role'=>'host'],['command'=>'advance','round'=>0,'attempt'=>1],203);$flow=$step['session'];
t267(!empty($step['ok']) && $flow['round']===1 && $flow['attempt']===0,'after two attempts round 1 ends without a third observer turn');

$story=ckmqp_show_initial($game);$story['round']=3;$story['attempt']=0;$story['phase']='story_questions';$story['storyQuestionDone'][0]=[2=>true];
t267(ckmqp_show_story_opponent_slots(0,2)===[2],'final has one opposing checker');
t267(ckmqp_show_story_questions_finished($story,0),'one opposing checker can finish cross-examination');
$story['phase']='story_vote';$story['storyModes'][0]='truth';
$v=do267($story,2,'story_vote',500,['vote'=>'truth']);
t267(!empty($v['ok']) && $v['session']['phase']==='review','single opponent vote completes final vote');
t267(count($v['session']['storyResults'][0]['votes']??[])===1,'final result contains one checker vote');

$max=ckmqp_show_initial($game);
$max['reviews']=[rr267(70,70),rr267(70,70)];
$max['hiddenReviews']=[rr267(70,70),rr267(70,70)];
$max['hardReviews']=[sr267(70),sr267(70)];
$max['storyReviews']=[sr267(70),sr267(70)];
t267(ckmqp_show_story_cumulative_score($max,1)===280 && ckmqp_show_story_cumulative_score($max,2)===280,'280-point ceiling preserved for both teams');

$legacy=ckmqp_show_initial();
t267(ckmqp_show_team_count($legacy)===3,'legacy state without game snapshot remains three-team compatible');

$show=file_get_contents(dirname(__DIR__).'/includes/negotiation-show.php');
$catalog=file_get_contents(dirname(__DIR__).'/includes/ready-games-catalog.php');
$admin=file_get_contents(dirname(__DIR__).'/includes/standalone-admin.php');
$main=file_get_contents(dirname(__DIR__).'/ckm-quiz-pro.php');
$engine=file_get_contents(dirname(__DIR__).'/core-source/includes/quiz/quiz-engine.php');
t267(str_contains($show,"'min_teams'=>2") && str_contains($show,"'max_teams'=>2"),'communicate templates seed as exactly two teams');
t267(str_contains($catalog,"'persuade_teams','«Переговори другого» настроена на 2 команды'"),'ready-game preflight requires two teams');
t267(str_contains($admin,'$isPersuadeMe?2:'),'technical editor preserves two-team contract');
t267(str_contains($catalog,"'quiz_contract'=>") || str_contains($catalog,"'quiz_contract' =>"),'QA fingerprint includes technical contract');
t267(!str_contains($engine,"negotiationMode'] ?? '') === 'communicate') \$teamCount=3"),'room engine does not force communicate games back to three teams');
t267(str_contains($engine,'$teamCount = ckm_quiz_core_normalize_team_count'),'room engine uses quiz min/max contract for team count');
t267(ckm_test_current_plugin_release($main),'current plugin release header and constant are synchronized');

echo "ALL $n PASS\n";
