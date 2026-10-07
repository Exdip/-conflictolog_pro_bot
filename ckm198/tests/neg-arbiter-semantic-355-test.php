<?php
// Item fixtures carry the current typed production context.
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__).'/modules/negotiation-master/ai/arbiter/arbiter-service.php';
use CKM\NegotiationMaster\ArbiterService;

$checks=0;
function a355(bool $ok,string $label):void{global $checks;if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);} $checks++;}
$ref=new ReflectionClass(ArbiterService::class);
$m=$ref->getMethod('explicitDealItemCodes');$m->setAccessible(true);
$guard=$ref->getMethod('assertSemanticCompleteness');$guard->setAccessible(true);
$context=[
 'target_message'=>['content'=>'Готовы зафиксировать пакет: цена 960 тысяч рублей и поставка за 40 дней.'],
 'items'=>[
  ['code'=>'price','value_type'=>'integer','title'=>'Цена контракта','unit'=>'RUB'],
  ['code'=>'delivery_days','value_type'=>'integer','title'=>'Срок поставки','unit'=>'дней'],
 ]
];
$codes=$m->invoke(null,$context); sort($codes);
a355($codes===['delivery_days','price'],'detects both explicit deal items');
$threw=false;try{$guard->invoke(null,['deal_updates'=>[]],$context);}catch(Throwable $e){$threw=true;}
a355($threw,'empty analysis rejected for explicit package');
$threw=false;try{$guard->invoke(null,['deal_updates'=>[['item_code'=>'price','action'=>'proposed','value'=>960000]]],$context);}catch(Throwable $e){$threw=true;}
a355($threw,'partial package rejected when second explicit item omitted');
$guard->invoke(null,['deal_updates'=>[
 ['item_code'=>'price','action'=>'proposed','value'=>960000],
 ['item_code'=>'delivery_days','action'=>'proposed','value'=>40],
]],$context);
a355(true,'complete explicit package accepted');
$noNumber=$context;$noNumber['target_message']['content']='Насколько критичен для вас срок поставки?';
a355($m->invoke(null,$noNumber)===[],'non-numeric discovery question does not force deal update');
$prompt=file_get_contents(dirname(__DIR__).'/modules/negotiation-master/ai/arbiter/arbiter-prompt-builder.php');
a355(str_contains($prompt,'deal_updates не может быть пустым'),'retry prompt requires deal updates for explicit values');
a355(str_contains(file_get_contents(dirname(__DIR__).'/modules/negotiation-master/ai/arbiter/arbiter-service.php'),"neg-arbiter-1.7"),'arbiter version bumped');
$plugin=file_get_contents(dirname(__DIR__).'/ckm-quiz-pro.php');
a355(ckm_test_current_plugin_release($plugin),'plugin version');
echo "$checks/$checks NEG-ARBITER-SEMANTIC-355 PASS\n";
