<?php
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', __DIR__.'/fixtures/');
$root=dirname(__DIR__,3);
require $root.'/modules/negotiation-master/ai/opponent/sales-client-fallback.php';
$f=new CKM\NegotiationMaster\SalesClientFallback();
$facts=[
 ['code'=>'team_size','title'=>'Размер команды','content'=>'В отделе продаж 18 менеджеров.','reveal_rules'=>['partial'=>'Вопрос о размере отдела или структуре команды','revealed'=>'Прямой вопрос о количестве менеджеров']],
 ['code'=>'manual_control','title'=>'Ручной контроль','content'=>'Елена успевает вручную прослушивать только 5–7 звонков в неделю.','reveal_rules'=>['partial'=>'Вопрос о контроле качества разговоров','revealed'=>'Уточнение, сколько звонков реально проверяется вручную']],
];
$ctx=['mechanics'=>['training_domain'=>'sales'],'identity'=>['name'=>'Елена Соколова'],'hidden_facts'=>$facts,
 'validated_state'=>['discovered_facts'=>[['code'=>'manual_control','reveal_level'=>2]]]];
$q='А как вы сейчас понимаете, какие звонки менеджеров стоит проверить в первую очередь?';
if($f->buildFactReply($ctx,$q)!=='') throw new RuntimeException('Adjacent one-word overlap must not reveal team_size.');
echo "SALES-545 focused regression passed.
";
