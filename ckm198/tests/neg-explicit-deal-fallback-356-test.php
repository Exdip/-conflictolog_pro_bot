<?php
// Item fixtures carry the current typed production context.
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
define('ABSPATH', __DIR__ . '/');
if (!function_exists('wp_json_encode')) { function wp_json_encode($v,$flags=0){ return json_encode($v,$flags); } }
require_once dirname(__DIR__).'/modules/negotiation-master/ai/arbiter/arbiter-service.php';
use CKM\NegotiationMaster\ArbiterService;
$checks=0;
function e356($ok,$msg){global $checks;$checks++;if(!$ok){fwrite(STDERR,"FAIL: $msg\n");exit(1);}echo "PASS: $msg\n";}
$ref=new ReflectionClass(ArbiterService::class);
$values=$ref->getMethod('explicitDealValues');$values->setAccessible(true);
$fallback=$ref->getMethod('deterministicExplicitDeals');$fallback->setAccessible(true);
$canon=$ref->getMethod('canonicalizeExplicitDeals');$canon->setAccessible(true);
$assert=$ref->getMethod('assertSemanticCompleteness');$assert->setAccessible(true);
$ctx=[
 'target_message'=>['id'=>77,'actor'=>'player','content'=>'Предлагаю итоговый пакет: цена контракта 960 000 рублей, срок поставки 40 дней.'],
 'items'=>[
   ['code'=>'price','value_type'=>'integer','title'=>'Цена контракта','unit'=>'RUB','current_value'=>[]],
   ['code'=>'delivery_days','value_type'=>'integer','title'=>'Срок поставки','unit'=>'days','current_value'=>[]],
 ]
];
$v=$values->invoke(null,$ctx);
e356(($v['price']??null)===960000,'extracts spaced RUB amount');
e356(($v['delivery_days']??null)===40,'extracts delivery days');
$fb=$fallback->invoke(null,$ctx);
e356(count($fb)===2 && $fb[0]['action']==='proposed' && $fb[1]['action']==='proposed','standalone explicit package becomes proposals');
e356($fb[0]['bundle_key']==='msg-77' && $fb[1]['bundle_key']==='msg-77','explicit package receives stable bundle');
$analysis=['deal_updates'=>[],'events'=>[]];
$c=$canon->invoke(null,$analysis,$ctx);
e356(count($c['deal_updates'])===2,'server fallback fills omitted explicit items');
$assert->invoke(null,$c,$ctx); e356(true,'canonicalized package passes semantic completeness');
$ctx2=$ctx;$ctx2['target_message']=['id'=>78,'actor'=>'opponent','content'=>'Согласен: цена контракта 960 000 рублей, срок поставки 40 дней. Подтверждаю пакет.'];
$ctx2['items'][0]['current_value']=['offers'=>['player'=>['value'=>960000]]];
$ctx2['items'][1]['current_value']=['offers'=>['player'=>['value'=>40]]];
$fb2=$fallback->invoke(null,$ctx2);
e356($fb2[0]['action']==='acceptance_candidate' && $fb2[1]['action']==='acceptance_candidate','explicit matching confirmation becomes acceptance candidates');
$ctx3=['target_message'=>['id'=>79,'actor'=>'player','content'=>'Цена 20,5 млн рублей.'],'items'=>[['code'=>'price','value_type'=>'integer','title'=>'Цена','unit'=>'RUB','current_value'=>[]]]];
$v3=$values->invoke(null,$ctx3);
e356(($v3['price']??null)===20500000,'normalizes decimal millions to base RUB');
$plugin=file_get_contents(dirname(__DIR__).'/ckm-quiz-pro.php');
e356(ckm_test_current_plugin_release($plugin),'plugin build updated');
e356(str_contains(file_get_contents(dirname(__DIR__).'/modules/negotiation-master/ai/arbiter/arbiter-service.php'),"neg-arbiter-1.7"),'arbiter version bumped');
echo "TOTAL: $checks/$checks PASS\n";
