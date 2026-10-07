<?php
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__).'/modules/negotiation-master/ai/arbiter/arbiter-service.php';
use CKM\NegotiationMaster\ArbiterService;

$checks=0;
function f365(bool $ok,string $label):void{global $checks;if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);} $checks++;}
$ref=new ReflectionClass(ArbiterService::class);
$m=$ref->getMethod('deterministicFactUpdates');$m->setAccessible(true);
$merge=$ref->getMethod('canonicalizeFactUpdates');$merge->setAccessible(true);
$fact=[
 'code'=>'deadline_risk','title'=>'Риск срыва запуска','content'=>'Для заказчика задержка запуска дороже небольшой переплаты.',
 'current_reveal_level'=>0,
 'reveal_rules'=>['partial'=>'Если участник спрашивает о последствиях задержки.','revealed'=>'Если участник уточняет стоимость или риск срыва запуска.','automatic_reveal'=>false],
];
$base=['hidden_facts'=>[$fact]];
$c=$base;$c['target_message']=['actor'=>'player','content'=>'Что произойдёт, если поставка задержится, и какой риск для проекта наиболее критичен?'];
$u=$m->invoke(null,$c);
f365(count($u)===1 && $u[0]['fact_code']==='deadline_risk' && $u[0]['suggested_level']==='partial','player consequence probe creates partial fact update');
$c=$base;$c['target_message']=['actor'=>'player','content'=>'Правильно ли я понимаю, что срыв запуска обойдётся дороже небольшой переплаты?'];
$u=$m->invoke(null,$c);
f365(count($u)===1 && $u[0]['suggested_level']==='partial','player direct probe never self-reveals hidden fact');
$c=$base;$c['target_message']=['actor'=>'opponent','content'=>'Да. Срыв запуска для нас критичнее экономии: задержка запуска обойдётся дороже небольшой переплаты.'];
$u=$m->invoke(null,$c);
f365(count($u)===1 && $u[0]['suggested_level']==='revealed','opponent explicit disclosure creates revealed update');
$c=$base;$c['target_message']=['actor'=>'opponent','content'=>'Задержка создаёт для проекта риск и дополнительные расходы.'];
$u=$m->invoke(null,$c);
f365(count($u)===1 && $u[0]['suggested_level']==='partial','opponent generic risk disclosure can be partial');
$c=$base;$c['target_message']=['actor'=>'player','content'=>'Добрый день. Предлагаю обсудить сотрудничество.'];
f365($m->invoke(null,$c)===[],'unrelated player statement does not reveal fact');
$c=$base;$c['hidden_facts'][0]['current_reveal_level']=2;$c['target_message']=['actor'=>'opponent','content'=>'Срыв запуска дороже небольшой переплаты.'];
f365($m->invoke(null,$c)===[],'already revealed fact is not repeated');
$c=$base;$c['target_message']=['actor'=>'opponent','content'=>'Срыв запуска для нас критичнее: задержка запуска обойдётся дороже небольшой переплаты.'];
$merged=$merge->invoke(null,['fact_updates'=>[['fact_code'=>'deadline_risk','suggested_level'=>'partial','confidence'=>0.8,'reason'=>'ai','public_summary'=>'','discovery_event'=>'interest_discovered']]],$c);
f365(($merged['fact_updates'][0]['suggested_level']??'')==='revealed','deterministic revealed update upgrades weaker AI update');
$c=$base;$c['target_message']=['actor'=>'player','content'=>'Что произойдёт, если поставка задержится?'];
$merged=$merge->invoke(null,['fact_updates'=>[['fact_code'=>'deadline_risk','suggested_level'=>'partial','confidence'=>0.99,'reason'=>'ai','public_summary'=>'','discovery_event'=>'interest_discovered']]],$c);
f365(count($merged['fact_updates'])===1 && ($merged['fact_updates'][0]['reason']??'')==='ai','equal-level AI update is preserved');
$svc=file_get_contents(dirname(__DIR__).'/modules/negotiation-master/ai/arbiter/arbiter-service.php');
f365(str_contains($svc,"neg-arbiter-1.7"),'arbiter version bumped');
f365(str_contains($svc,'canonicalizeFactUpdates'),'fact semantic fallback wired into arbiter');
$plugin=file_get_contents(dirname(__DIR__).'/ckm-quiz-pro.php');
f365(ckm_test_current_plugin_release($plugin),'plugin version bumped');
echo "$checks/$checks NEG-HIDDEN-FACT-SEMANTIC-365 PASS\n";
