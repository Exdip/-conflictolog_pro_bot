<?php
require_once __DIR__ . '/support/plugin-release.php';
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/');
function add_action(...$x){}
function ckm_quiz_json_decode($s){return json_decode($s,true)?:[];}
require dirname(__DIR__).'/includes/persuade-me-content.php';
require dirname(__DIR__).'/includes/negotiation-show.php';
require dirname(__DIR__).'/includes/negotiation-show-dialogue.php';
$n=0;function t300($b,$label){global $n;if(!$b){fwrite(STDERR,"FAIL $label\n");exit(1);}echo 'PASS '.(++$n)." $label\n";}
$root=dirname(__DIR__);$js=file_get_contents($root.'/assets/standalone-game.js');$admin=file_get_contents($root.'/includes/standalone-admin.php');$org=file_get_contents($root.'/includes/standalone-organizer.php');$main=file_get_contents($root.'/ckm-quiz-pro.php');
t300(ckm_test_current_plugin_release($main),'version marker');
t300(ckmqp_show_hard_answer_limit_from_game(['format_settings_snapshot_json'=>'{"persuadeHardAnswerSeconds":30}'])===0,'legacy hard timer setting ignored');
$s=ckmqp_show_initial();$s=ckmqp_show_begin_attempt($s,2,0,100);t300($s['phase']==='hard_answer'&&(int)$s['deadline']===0,'hard round opens without deadline');
$s2=$s;$s2=ckmqp_show_tick($s2,9999);t300((int)$s2['questionIndex']===0&&empty($s2['hardAnswers'][0]??[]),'waiting does not auto-timeout or advance');
t300(!str_contains($admin,'name="persuade_hard_answer_seconds"')&&!str_contains($org,'name="persuade_hard_answer_seconds"'),'hard timer controls removed from builders');
t300(str_contains($js,"hard_answer:'Ответ'")&&!str_contains($js,"hard_answer:'Ответ · "),'hard-answer phase label has no countdown');
t300(str_contains($js,"if(show&&!deadline){el.hidden=true;el.textContent='';return;}el.hidden=false;"),'timer block hidden when no deadline');
t300(!str_contains($js,'Без таймера'),'no visible no-timer badge remains');
t300(str_contains($js,"Раунд 3 из 4 — «Неудобный вопрос»: три вопроса без отсчёта времени."),'round-3 rule note states untimed behavior without badge');
echo "ALL $n PASS\n";
