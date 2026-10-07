<?php
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/');
function add_action(...$a){}
function wp_unslash($v){return $v;}
function sanitize_textarea_field($v){return trim(strip_tags((string)$v));}
function ckm_quiz_json_decode($s){return json_decode($s,true)?:[];}
require dirname(__DIR__).'/includes/persuade-me-content.php';
require dirname(__DIR__).'/includes/negotiation-show.php';
require dirname(__DIR__).'/includes/negotiation-show-dialogue.php';
require dirname(__DIR__).'/includes/negotiation-show-story.php';
$n=0;function ck134($ok,$label){global $n;if(!$ok){fwrite(STDERR,"FAIL\t$label\n");exit(1);}echo 'PASS\t'.(++$n).' '.$label."\n";}
$raw=ckm_quiz_pro_persuade_me_default_content();
$raw['round1']['cases'][0]['situation']='CUSTOM CASE';
$raw['round1']['cases'][0]['speaker']='CUSTOM SPEAKER';
$raw['round1']['cases'][0]['opponent']='CUSTOM OPPONENT SECRET';
$raw['round2']['situation']='CUSTOM HIDDEN SITUATION';
$raw['round2']['tasks'][1][2]='CUSTOM HIDDEN TASK';
$raw['round3']['questions'][2][1]='CUSTOM HARD QUESTION';
$raw['round4']['dossiers'][2]['title']='CUSTOM DOSSIER';
$raw['round4']['dossiers'][2]['facts'][4]='CUSTOM FACT';
$raw['round4']['dossiers'][2]['distortion']='CUSTOM DISTORTION';
$game=['id'=>7,'game_code'=>'X','title'=>'Custom','status'=>'waiting','host_mode_snapshot'=>'ai','format_key_snapshot'=>'negotiation_duel','format_settings_snapshot_json'=>json_encode(['negotiationMode'=>'communicate','persuadeMeContent'=>$raw],JSON_UNESCAPED_UNICODE)];
$s=ckmqp_show_initial($game);
ck134(ckmqp_show_cases($s)[0]['situation']==='CUSTOM CASE','round 1 custom case enters room snapshot');
ck134(ckmqp_show_hidden_situation($s)==='CUSTOM HIDDEN SITUATION','round 2 custom situation enters room snapshot');
ck134(ckmqp_show_hidden_tasks($s)[1][2]==='CUSTOM HIDDEN TASK','round 2 custom secret task enters room snapshot');
ck134(ckmqp_show_hard_questions($s)[2][1]==='CUSTOM HARD QUESTION','round 3 custom question enters room snapshot');
ck134(ckmqp_show_story_dossiers($s)[2]['title']==='CUSTOM DOSSIER','round 4 custom dossier enters room snapshot');
$teams=[];foreach([1,2,3] as $slot)$teams[]=['id'=>100+$slot,'team_key'=>chr(64+$slot),'team_name'=>'Команда '.chr(64+$slot),'slot_no'=>$slot];
$a=ckmqp_show_project($game,['game_id'=>7,'role'=>'participant','team_id'=>101],$teams);
$b=ckmqp_show_project($game,['game_id'=>7,'role'=>'participant','team_id'=>102],$teams);
ck134($a['negotiationShow']['situation']==='Удержи цель. CUSTOM CASE','waiting screen uses custom situation');
ck134($a['negotiationShow']['brief']==='CUSTOM SPEAKER','speaker gets custom brief');
ck134($b['negotiationShow']['brief']==='CUSTOM OPPONENT SECRET','opponent gets custom secret brief');
ck134(!str_contains(json_encode($a,JSON_UNESCAPED_UNICODE),'CUSTOM OPPONENT SECRET'),'opponent secret is not leaked to speaker');
$org=file_get_contents(dirname(__DIR__).'/includes/standalone-organizer.php');
$admin=file_get_contents(dirname(__DIR__).'/includes/standalone-admin.php');
ck134(str_contains($org,'ckm_quiz_pro_persuade_me_front_editor'),'organizer uses dedicated four-round editor');
ck134(str_contains($admin,"['persuadeMeContent']"),'save stores authored content in format settings snapshot source');
ck134(str_contains($admin,"'persuade-me-contract'"),'saved custom game gets active engine contract marker');
ck134(!str_contains($org,'Редактор собственных заданий для каждого из четырёх раундов будет отдельным следующим этапом'),'old placeholder removed');
echo "ALL PASS\n";
