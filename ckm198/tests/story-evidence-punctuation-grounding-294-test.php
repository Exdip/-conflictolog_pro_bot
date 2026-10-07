<?php
require_once __DIR__.'/support/plugin-release.php';
// This feature survives later releases; validate the current header/constant contract.
if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
function ckmqp_show_role(int $slot,int $attempt,int $teamCount=3): string {return $slot===($attempt+1)?'speaker':'opponent';}
require_once __DIR__.'/../includes/negotiation-show-review.php';
function t294($ok,$msg){if(!$ok){fwrite(STDERR,"FAIL: $msg\n");exit(1);}echo "PASS: $msg\n";}
$messages=[
 ['id'=>40200,'slot'=>2,'text'=>'После урока Лера пришла к Виктору Палычу.','requestId'=>'story-main-1'],
 ['id'=>40201,'slot'=>1,'text'=>'Чего именно Лера добивалась: изменить оценку или перепроверить работу?','requestId'=>'story-question-1-0'],
 ['id'=>40202,'slot'=>2,'text'=>'Лера хотела не немедленно изменить оценку, а сначала перепроверить спорную работу: сопоставить её ответы с ключом варианта Б.','requestId'=>'story-answer-1-0'],
 ['id'=>40203,'slot'=>1,'text'=>'Что должно стать основанием окончательного решения?','requestId'=>'story-question-1-1'],
 ['id'=>40204,'slot'=>2,'text'=>'Основанием должен быть результат фактической сверки работы с правильным ключом варианта Б. Окончательный вывод — только после проверки.','requestId'=>'story-answer-1-1'],
];
$criteria=[];foreach(array_keys(ckmqp_show_story_review_criteria()) as $k)$criteria[$k]=['points'=>6,'reason'=>'Умеренное проявление','evidence'=>[]];
$criteria['story_clarity']=['points'=>8,'reason'=>'Есть конкретный проверяемый запрос','evidence'=>[['messageId'=>40202,'quote'=>'сначала перепроверить спорную работу сопоставить её ответы с ключом варианта Б']]];
$criteria['answer_directness']=['points'=>7,'reason'=>'Корректный ответ на вопрос','evidence'=>[['messageId'=>40202,'quote'=>'Лера хотела не немедленно изменить оценку а сначала перепроверить спорную работу']]];
$criteria['task_fidelity']=['points'=>7,'reason'=>'Объяснена причина проверки','evidence'=>[['messageId'=>40202,'quote'=>'сопоставить её ответы с ключом варианта Б']]];
$criteria['answer_grounding']=['points'=>7,'reason'=>'На сомнение дан содержательный ответ','evidence'=>[['messageId'=>40204,'quote'=>'Окончательный вывод только после проверки']]];
$criteria['communication_quality']=['points'=>7,'reason'=>'Ответ деловой и без давления','evidence'=>[['messageId'=>40204,'quote'=>'Основанием должен быть результат фактической сверки работы с правильным ключом варианта Б']]];
$raw=['participants'=>['speaker'=>['criteria'=>$criteria]]];
$err=null;$out=ckmqp_show_validate_speaker_only_review($raw,$messages,1,$err,2);
t294(is_array($out),'punctuation-only citation variation is grounded: '.($err??''));
$ev=$out['participants']['speaker']['criteria']['story_clarity']['evidence'][0]??[];
t294(($ev['messageId']??0)===40202,'message id remains grounded to speaker answer');
t294(($ev['quote']??'')==='сначала перепроверить спорную работу: сопоставить её ответы с ключом варианта Б','source punctuation is restored from transcript');
$bad=$raw;$bad['participants']['speaker']['criteria']['story_clarity']['evidence']=[['messageId'=>40202,'quote'=>'сначала перепроверить другую работу сопоставить её ответы с ключом варианта Б']];
$err=null;$rejected=ckmqp_show_validate_speaker_only_review($bad,$messages,1,$err,2);
t294($rejected===null&&str_contains((string)$err,'цитата не найдена'),'word substitution is still rejected');
$foreign=$raw;$foreign['participants']['speaker']['criteria']['story_clarity']['points']=6;$foreign['participants']['speaker']['criteria']['story_clarity']['evidence']=[['messageId'=>40201,'quote'=>'Чего именно Лера добивалась изменить оценку или перепроверить работу']];
$err=null;$filtered=ckmqp_show_validate_speaker_only_review($foreign,$messages,1,$err,2);
t294(is_array($filtered)&&empty($filtered['participants']['speaker']['criteria']['story_clarity']['evidence']),'punctuation-varied checker quote is filtered, not reassigned');
$main=file_get_contents(__DIR__.'/../ckm-quiz-pro.php');
t294(ckm_test_current_plugin_release($main),'current plugin release header and constant are synchronized');
echo "story evidence punctuation grounding 294 test OK\n";
