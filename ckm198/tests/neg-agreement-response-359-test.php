<?php
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__).'/modules/negotiation-master/ai/arbiter/arbiter-response-parser.php';
require_once dirname(__DIR__).'/modules/negotiation-master/ai/opponent/agreement-response-service.php';
use CKM\NegotiationMaster\AgreementResponseService;
$checks=0;
function a359($ok,$msg){global $checks;$checks++;if(!$ok){fwrite(STDERR,"FAIL: $msg\n");exit(1);}echo "PASS: $msg\n";}
$ref=new ReflectionClass(AgreementResponseService::class);
$parse=$ref->getMethod('parse');$parse->setAccessible(true);
$payload=['decision'=>'accept','reply'=>'Подтверждаю весь пакет.','counteroffers'=>[]];
$direct=$parse->invoke(null,json_encode($payload,JSON_UNESCAPED_UNICODE));
a359($direct['decision']==='accept' && $direct['reply']==='Подтверждаю весь пакет.','direct JSON accepted');
$markdown=$parse->invoke(null,"```json\n".json_encode($payload,JSON_UNESCAPED_UNICODE)."\n```");
a359($markdown['decision']==='accept','markdown wrapped JSON accepted');
$double=$parse->invoke(null,json_encode(json_encode($payload,JSON_UNESCAPED_UNICODE),JSON_UNESCAPED_UNICODE));
a359($double['decision']==='accept','double encoded JSON accepted');
$prefixed=$parse->invoke(null,"Ответ модели: ".json_encode($payload,JSON_UNESCAPED_UNICODE)." конец");
a359($prefixed['decision']==='accept','balanced JSON inside harmless text accepted');
$failed=false;try{$parse->invoke(null,'{"decision":"maybe","reply":"x"}');}catch(Throwable){$failed=true;}
a359($failed,'invalid decision still rejected');
$state=file_get_contents(dirname(__DIR__).'/modules/negotiation-master/domain/item-state-service.php');
a359(str_contains($state,'same_value_confirmation'),'same-value confirmation guard present');
a359(str_contains($state,"self::same(\$resolvedValue, \$current['agreed']['value'])"),'proposed same value compared with agreed value');
a359(str_contains($state,"self::same(\$request['value'], \$current['agreed']['value'])"),'reopen_request same value compared with agreed value');
a359(str_contains($state,"unset(\$current['reopen_request']);") && str_contains($state,"\$newStatus = 'agreed';"),'legacy same-value reopen can heal to agreed');
$plugin=file_get_contents(dirname(__DIR__).'/ckm-quiz-pro.php');
a359(ckm_test_current_plugin_release($plugin),'plugin build updated');
echo "NEG-AGREEMENT-RESPONSE-HARDENING: $checks/$checks PASS\n";
