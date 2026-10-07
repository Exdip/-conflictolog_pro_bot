<?php
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__).'/modules/negotiation-master/application/topicality-guard.php';
use CKM\NegotiationMaster\TopicalityGuard;

$checks=0;
function t367(bool $ok,string $label):void{global $checks;if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);} $checks++;}
$ctx=[
 'scenario_title'=>'Контракт на поставку оборудования',
 'situation'=>'Вы представляете поставщика оборудования и обсуждаете с заказчиком цену и срок поставки тестового контракта.',
 'task'=>'Договоритесь о цене и сроке поставки, не нарушая минимально допустимых условий.',
 'player_role'=>'Коммерческий директор поставщика','opponent_role'=>'Директор по закупкам заказчика',
 'items'=>['Цена контракта','Срок поставки'],
 'known_facts'=>['Бюджет проекта Заказчик рассчитывает уложиться примерно в 1 млн рублей.','Срок Оборудование желательно получить не позднее чем через 45 дней.'],
];
t367(TopicalityGuard::classify('Предлагаю цену 960 000 рублей и срок поставки 40 дней.',$ctx)==='relevant','explicit package allowed');
t367(TopicalityGuard::classify('Что произойдёт при задержке и какой риск для вас критичнее?',$ctx)==='relevant','interest/risk probe allowed');
t367(TopicalityGuard::classify('Расскажите подробнее о вашем бюджете.',$ctx)==='relevant','scenario paraphrase allowed');
t367(TopicalityGuard::classify('А если 35?',$ctx)==='relevant','short numeric follow-up allowed');
t367(TopicalityGuard::classify('Почему?',$ctx)==='relevant','short contextual follow-up allowed');
t367(TopicalityGuard::classify('Добрый день',$ctx)==='relevant','courtesy allowed');
t367(TopicalityGuard::classify('Какая сегодня погода?',$ctx)==='off_topic','weather blocked');
t367(TopicalityGuard::classify('Расскажи анекдот про контракт.',$ctx)==='off_topic','entertainment blocked even with scenario keyword');
t367(TopicalityGuard::classify('Напиши код на Python.',$ctx)==='off_topic','general assistant coding request blocked');
t367(TopicalityGuard::classify('Кто президент Франции?',$ctx)==='off_topic','unrelated factual question blocked');
$root=dirname(__DIR__);
$msg=file_get_contents($root.'/modules/negotiation-master/application/message-service.php');
$ctl=file_get_contents($root.'/modules/negotiation-master/api/session-controller.php');
$alt=file_get_contents($root.'/modules/negotiation-master/domain/alternative-value-service.php');
$zopa=file_get_contents($root.'/modules/negotiation-master/domain/zopa-service.php');
$js=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-session.js');
$page=file_get_contents($root.'/modules/negotiation-master/public/player-page.php');
$boot=file_get_contents($root.'/modules/negotiation-master/bootstrap.php');
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
t367(str_contains($boot,"application/topicality-guard.php"),'topic guard loaded by bootstrap');
t367(str_contains($msg,'$this->topicGuard->assertRelevant($sessionId, $content)'),'guard runs before new player message is processed');
t367(str_contains($ctl,"'code'=>'NEG_OFF_TOPIC'"),'dedicated REST error code exists');
t367(str_contains($ctl,'OffTopicMessageException'),'off-topic exception mapped');
t367(str_contains($alt,'Итоговое соглашение выгоднее отказа от сделки.'),'future alternative copy humanized');
t367(!str_contains($alt,'формализованной альтернативы'),'formalized-alternative jargon removed from future copy');
t367(str_contains($zopa,'У сторон были условия, при которых можно было договориться.'),'zopa lead is human');
t367(str_contains($zopa,'elseif($bestPublic'),'final agreement comparison takes precedence over best-mutual duplicate');
t367(str_contains($js,'humanizeZopaSummary'),'old saved results humanized at render time');
t367(str_contains($js,"open:'Договориться было возможно'"),'zopa status chip humanized');
t367(str_contains($page,'Можно ли было договориться'),'result heading humanized');
preg_match('/Version:\s*([0-9.]+)/', $plugin, $versionMatch);
t367(version_compare($versionMatch[1] ?? '0', '0.3.23.346', '>='),'plugin version supports topic guard');
echo "$checks/$checks NEG-TOPIC-GUARD-HUMAN-ZOPA-367 PASS\n";
