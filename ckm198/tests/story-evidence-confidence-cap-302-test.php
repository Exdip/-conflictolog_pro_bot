<?php
require_once __DIR__.'/support/plugin-release.php';
// This feature survives later releases; validate the current header/constant contract.
if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
function ckmqp_show_role(int $slot,int $attempt,int $teamCount=3): string {return $slot===($attempt+1)?'speaker':'opponent';}
require_once __DIR__.'/../includes/negotiation-show-review.php';
function t302($ok,$msg){if(!$ok){fwrite(STDERR,"FAIL: $msg\n");exit(1);}echo "PASS: $msg\n";}
$messages=[
 ['id'=>41000,'slot'=>1,'text'=>'Лера выполняла вариант Б и попросила перепроверить контрольную по правильному ключу.','requestId'=>'story-main-0'],
 ['id'=>41001,'slot'=>2,'text'=>'Какой вариант выполняла Лера?','requestId'=>'story-question-0-0'],
 ['id'=>41002,'slot'=>1,'text'=>'Лера выполняла вариант Б, это прямо сказано в рассказе.','requestId'=>'story-answer-0-0'],
 ['id'=>41003,'slot'=>2,'text'=>'Зачем нужна перепроверка?','requestId'=>'story-question-0-1'],
 ['id'=>41004,'slot'=>1,'text'=>'Чтобы установить, применили ли правильный ключ и справедливо ли выставлена оценка.','requestId'=>'story-answer-0-1'],
];
$criteria=[];foreach(array_keys(ckmqp_show_story_review_criteria()) as $k)$criteria[$k]=['points'=>6,'reason'=>'Умеренное проявление','evidence'=>[]];
// Real production failure: strong task_fidelity with no admissible quote should not kill
// the entire review. It is downgraded to the highest unsupported score, 6.
$criteria['task_fidelity']=['points'=>9,'reason'=>'Условие истории соблюдено','evidence'=>[]];
$criteria['story_clarity']=['points'=>8,'reason'=>'История ясная','evidence'=>[['messageId'=>41000,'quote'=>'Лера выполняла вариант Б']]];
$err=null;$out=ckmqp_show_validate_speaker_only_review(['participants'=>['speaker'=>['criteria'=>$criteria]]],$messages,0,$err,2);
t302(is_array($out),'review survives unsupported high task_fidelity: '.($err??''));
$row=$out['participants']['speaker']['criteria']['task_fidelity']??[];
t302(($row['points']??null)===6,'unsupported high task_fidelity is conservatively capped to 6');
t302(empty($row['evidence']??null),'no evidence is invented locally');
// A real quote preserves the high score.
$criteria['task_fidelity']=['points'=>9,'reason'=>'Условие истории соблюдено','evidence'=>[['quote'=>'попросила перепроверить контрольную по правильному ключу']]];
$err=null;$out=ckmqp_show_validate_speaker_only_review(['participants'=>['speaker'=>['criteria'=>$criteria]]],$messages,0,$err,2);
t302(is_array($out),'quote-only task_fidelity evidence is safely grounded: '.($err??''));
$row=$out['participants']['speaker']['criteria']['task_fidelity']??[];
t302(($row['points']??null)===9,'grounded high task_fidelity score is preserved');
t302(($row['evidence'][0]['messageId']??0)===41000,'grounded evidence points to the real storyteller message');
// A fabricated non-empty quote must still be rejected rather than capped away.
$criteria['task_fidelity']=['points'=>9,'reason'=>'Условие истории соблюдено','evidence'=>[['messageId'=>41000,'quote'=>'Лера потребовала немедленно поставить пятёрку']]];
$err=null;$out=ckmqp_show_validate_speaker_only_review(['participants'=>['speaker'=>['criteria'=>$criteria]]],$messages,0,$err,2);
t302($out===null&&str_contains((string)$err,'цитата не найдена'),'fabricated non-empty evidence remains strictly rejected');
$story=file_get_contents(__DIR__.'/../includes/negotiation-show-story.php');
t302(!str_contains($story,"['story_answer','story_vote','review','game_complete']"),'story answer UI keeps the planned two-question total');
$dialogue=file_get_contents(__DIR__.'/../includes/negotiation-show-dialogue.php');
t302(str_contains($dialogue,'специальной шкале Раунда 4'),'round-4 scoring announcement uses dedicated rubric wording');
$main=file_get_contents(__DIR__.'/../ckm-quiz-pro.php');
t302(ckm_test_current_plugin_release($main),'current plugin release header and constant are synchronized');
echo "story evidence confidence cap 302 test OK\n";
