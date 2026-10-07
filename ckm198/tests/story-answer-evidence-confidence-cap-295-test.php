<?php
require_once __DIR__.'/support/plugin-release.php';
// This feature survives later releases; validate the current header/constant contract.
if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
function ckmqp_show_role(int $slot,int $attempt,int $teamCount=3): string {return $slot===($attempt+1)?'speaker':'opponent';}
require_once __DIR__.'/../includes/negotiation-show-review.php';
function t295($ok,$msg){if(!$ok){fwrite(STDERR,"FAIL: $msg\n");exit(1);}echo "PASS: $msg\n";}
$messages=[
 ['id'=>40200,'slot'=>2,'text'=>'После урока Лера спокойно попросила перепроверить работу.','requestId'=>'story-main-1'],
 ['id'=>40201,'slot'=>1,'text'=>'Чего именно Лера добивалась?','requestId'=>'story-question-1-0'],
 ['id'=>40202,'slot'=>2,'text'=>'Я прошу сначала перепроверить работу по ключу варианта Б, а не менять оценку заранее.','requestId'=>'story-answer-1-0'],
 ['id'=>40203,'slot'=>1,'text'=>'Что должно стать основанием решения?','requestId'=>'story-question-1-1'],
 ['id'=>40204,'slot'=>2,'text'=>'Давайте сначала сверим факты, а окончательный вывод сделаем после проверки.','requestId'=>'story-answer-1-1'],
];
$base=[];foreach(array_keys(ckmqp_show_story_review_criteria()) as $k)$base[$k]=['points'=>6,'reason'=>'Умеренное проявление','evidence'=>[]];
$base['story_clarity']=['points'=>8,'reason'=>'Конкретная просьба','evidence'=>[['messageId'=>40202,'quote'=>'сначала перепроверить работу по ключу варианта Б']]];
$base['task_fidelity']=['points'=>7,'reason'=>'Пояснён интерес к проверке','evidence'=>[['messageId'=>40202,'quote'=>'не менять оценку заранее']]];

// AI uses prepared story as proof of answer_directness. It must not kill the whole review:
// remove the inadmissible evidence and conservatively cap the unsupported high score to 6.
$c=$base;
$c['answer_directness']=['points'=>9,'reason'=>'Рассказчик говорит корректно','evidence'=>[['messageId'=>40200,'quote'=>'Лера спокойно попросила перепроверить работу']]];
$raw=['participants'=>['speaker'=>['criteria'=>$c]]];
$err=null;$out=ckmqp_show_validate_speaker_only_review($raw,$messages,1,$err,2);
t295(is_array($out),'story-only evidence no longer rejects whole review: '.($err??''));
$r=$out['participants']['speaker']['criteria']['answer_directness']??[];
t295(($r['points']??null)===6,'unsupported high answer_directness score is capped to 6');
t295(empty($r['evidence']??null),'story evidence is removed from interactional criterion');

// Proper answer evidence preserves a high score.
$c=$base;
$c['answer_directness']=['points'=>9,'reason'=>'Ответ корректный и без давления','evidence'=>[['messageId'=>40204,'quote'=>'Давайте сначала сверим факты']]];
$raw=['participants'=>['speaker'=>['criteria'=>$c]]];
$err=null;$out=ckmqp_show_validate_speaker_only_review($raw,$messages,1,$err,2);
t295(is_array($out),'answer-grounded answer_directness review passes: '.($err??''));
$r=$out['participants']['speaker']['criteria']['answer_directness']??[];
t295(($r['points']??null)===9,'answer-grounded high score is preserved');
t295(($r['evidence'][0]['messageId']??0)===40204,'answer evidence remains attached to real answer');

// Mixed evidence: discard story item, keep answer item, do not cap.
$c=$base;
$c['version_stability']=['points'=>8,'reason'=>'Версия устойчиво сохраняется в ответе','evidence'=>[
 ['messageId'=>40200,'quote'=>'Лера спокойно попросила перепроверить работу'],
 ['messageId'=>40204,'quote'=>'Давайте сначала сверим факты'],
]];
$raw=['participants'=>['speaker'=>['criteria'=>$c]]];
$err=null;$out=ckmqp_show_validate_speaker_only_review($raw,$messages,1,$err,2);
t295(is_array($out),'mixed interaction evidence is normalized: '.($err??''));
$r=$out['participants']['speaker']['criteria']['version_stability']??[];
t295(($r['points']??null)===8,'valid answer evidence prevents cap');
t295(count($r['evidence']??[])===1&&($r['evidence'][0]['messageId']??0)===40204,'only answer evidence remains');

$src=file_get_contents(__DIR__.'/../includes/negotiation-show-review.php');
t295(str_contains($src,"['answer_directness','answer_grounding','version_stability']")&&str_contains($src,'$row[\'points\']=6'),'confidence cap code present');
$main=file_get_contents(__DIR__.'/../ckm-quiz-pro.php');
t295(ckm_test_current_plugin_release($main),'current plugin release header and constant are synchronized');
echo "story answer evidence confidence cap 295 test OK\n";
