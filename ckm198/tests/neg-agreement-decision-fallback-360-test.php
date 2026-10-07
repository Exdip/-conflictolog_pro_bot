<?php
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__).'/modules/negotiation-master/ai/arbiter/arbiter-response-parser.php';
require_once dirname(__DIR__).'/modules/negotiation-master/ai/opponent/agreement-response-service.php';
use CKM\NegotiationMaster\AgreementResponseService;
$checks=0;
function a360($ok,$msg){global $checks;$checks++;if(!$ok){fwrite(STDERR,"FAIL: $msg\n");exit(1);}echo "PASS: $msg\n";}
$svc=(new ReflectionClass(AgreementResponseService::class))->newInstanceWithoutConstructor();
$package=[
 ['code'=>'price','title'=>'Цена контракта','value'=>960000,'unit'=>'RUB'],
 ['code'=>'delivery_days','title'=>'Срок поставки','value'=>40,'unit'=>'дней'],
];
$accept=$svc->classifyExisting('Подтверждаю весь пакет целиком: цена контракта 960 000 рублей, срок поставки 40 дней.',$package);
a360(is_array($accept)&&$accept['decision']==='accept','existing explicit whole-package acceptance classified');
a360($accept['reply']!=='' && $accept['counteroffers']===[],'accept keeps visible reply and no invented counters');
$accept2=$svc->classifyExisting('Согласен на эти условия без изменений.',$package);
a360(is_array($accept2)&&$accept2['decision']==='accept','strong no-change acceptance classified without repeating values');
$reject=$svc->classifyExisting('Не принимаю пакет: цена для нас неприемлема.',$package);
a360(is_array($reject)&&$reject['decision']==='reject','explicit rejection classified');
$partial=$svc->classifyExisting('Принимаю часть условий, но цену нужно изменить.',$package);
a360(is_array($partial)&&$partial['decision']==='partial','explicit partial acceptance classified');
$amb=$svc->classifyExisting('Давайте продолжим обсуждение деталей.',$package);
a360($amb===null,'ambiguous text is not guessed');
$ref=new ReflectionClass(AgreementResponseService::class);$parse=$ref->getMethod('parse');$parse->setAccessible(true);
$alias=$parse->invoke(null,json_encode(['status'=>'accepted','message'=>'Подтверждаю весь пакет целиком.'],JSON_UNESCAPED_UNICODE),$package);
a360($alias['decision']==='accept','common accepted/status alias normalized');
$plain=$parse->invoke(null,'Подтверждаю весь пакет целиком: цена контракта 960 000 рублей, срок поставки 40 дней.',$package);
a360($plain['decision']==='accept','plain-text strong acceptance survives malformed JSON');
$agreement=file_get_contents(dirname(__DIR__).'/modules/negotiation-master/application/agreement-service.php');
a360(str_contains($agreement,'classifyExisting'),'agreement retry checks an existing visible reply before new AI call');
a360(strpos($agreement,'findReplyTo($sessionId,$proposalMessageId)') < strpos($agreement,'$this->responses->decide($sessionId,$proposalMessageId,$package)'),'existing reply is checked before fresh agreement AI request');
$plugin=file_get_contents(dirname(__DIR__).'/ckm-quiz-pro.php');
a360(ckm_test_current_plugin_release($plugin),'plugin version updated');
echo "NEG-AGREEMENT-DECISION-FALLBACK: $checks/$checks PASS\n";
