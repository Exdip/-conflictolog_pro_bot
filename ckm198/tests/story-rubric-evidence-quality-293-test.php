<?php
require_once __DIR__.'/support/plugin-release.php';
// This feature survives later releases; validate the current header/constant contract.
if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
function ckmqp_show_role(int $slot,int $attempt,int $teamCount=3): string {return $slot===($attempt+1)?'speaker':'opponent';}
require_once __DIR__.'/../includes/negotiation-show-review.php';
function t293($ok,$msg){if(!$ok){fwrite(STDERR,"FAIL: $msg\n");exit(1);}echo "PASS: $msg\n";}
$messages=[
 ['id'=>40000,'slot'=>1,'text'=>'Лера учится в десятом классе. Я прошу перепроверить работу.','requestId'=>'story-main-0'],
 ['id'=>40001,'slot'=>2,'text'=>'Какой вариант вы выполняли?','requestId'=>'story-question-0-0'],
 ['id'=>40002,'slot'=>1,'text'=>'Я выполняла вариант А. Прошу сверить мою работу с ключом.','requestId'=>'story-answer-0-0'],
 ['id'=>40003,'slot'=>2,'text'=>'Почему вы считаете, что ключ был не тот?','requestId'=>'story-question-0-1'],
 ['id'=>40004,'slot'=>1,'text'=>'В проверке есть несоответствие, поэтому предлагаю вместе сверить ответы с ключом.','requestId'=>'story-answer-0-1'],
];
$mk=function(){
 $c=[];foreach(array_keys(ckmqp_show_story_review_criteria()) as $k)$c[$k]=['points'=>6,'reason'=>'Проявление умеренное','evidence'=>[]];
 $c['story_clarity']=['points'=>8,'reason'=>'Есть конкретная просьба сверить работу с ключом','evidence'=>[['messageId'=>40002,'quote'=>'Прошу сверить мою работу с ключом.']]];
 $c['answer_directness']=['points'=>7,'reason'=>'Ответ корректный и без обесценивания','evidence'=>[['messageId'=>40004,'quote'=>'поэтому предлагаю вместе сверить ответы с ключом.']]];
 $c['communication_quality']=['points'=>8,'reason'=>'На критический вопрос дан спокойный деловой ответ','evidence'=>[['messageId'=>40004,'quote'=>'В проверке есть несоответствие, поэтому предлагаю вместе сверить ответы с ключом.']]];
 return $c;
};
$valid=['participants'=>['speaker'=>['criteria'=>$mk()]]];
$err=null;$out=ckmqp_show_validate_speaker_only_review($valid,$messages,0,$err,2);
t293(is_array($out),'valid story methodology review passes: '.($err??''));
$contradict=$valid;$contradict['participants']['speaker']['criteria']['answer_grounding']['reason']='Рассказчик не отвечает на вопросы соперника.';
$err=null;$bad=ckmqp_show_validate_speaker_only_review($contradict,$messages,0,$err,2);
t293($bad===null&&str_contains((string)$err,'ответил на все вопросы'),'contradictory no-answer claim is rejected');
$storyEmotion=$valid;$storyEmotion['participants']['speaker']['criteria']['version_stability']['evidence']=[['messageId'=>40000,'quote'=>'Лера учится в десятом классе.']];
$err=null;$normalized=ckmqp_show_validate_speaker_only_review($storyEmotion,$messages,0,$err,2);
t293(is_array($normalized),'neutral story evidence is normalized instead of killing the whole review');
$emotion=$normalized['participants']['speaker']['criteria']['version_stability']??[];
t293(($emotion['points']??null)===6&&empty($emotion['evidence']??null),'story evidence is removed from version-stability and unsupported high score is capped');
$noStrongEvidence=$valid;$noStrongEvidence['participants']['speaker']['criteria']['story_clarity']['evidence']=[];
$err=null;$capped=ckmqp_show_validate_speaker_only_review($noStrongEvidence,$messages,0,$err,2);
t293(is_array($capped)&&(($capped['participants']['speaker']['criteria']['story_clarity']['points']??null)===6),'unsupported 7-10 story score is capped because strong scores require direct evidence');
$src=file_get_contents(__DIR__.'/../includes/negotiation-show-review.php');
t293(str_contains($src,'interactionFacts')&&str_contains($src,'allQuestionsAnswered'),'prompt context carries server interaction facts');
t293(str_contains($src,'1 story_clarity — Ясность и структура истории')&&str_contains($src,'7 communication_quality — Корректность и ясность общения'),'story prompt contains full seven-criterion definitions');
t293(str_contains($src,'Не используй критерии обычных переговорных раундов'),'prompt explicitly separates story rubric from ordinary negotiation rubric');
$main=file_get_contents(__DIR__.'/../ckm-quiz-pro.php');
t293(ckm_test_current_plugin_release($main),'current plugin release header and constant are synchronized');
echo "story rubric evidence quality 293 test OK\n";
