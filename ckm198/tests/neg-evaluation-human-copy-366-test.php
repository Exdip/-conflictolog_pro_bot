<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$svc=file_get_contents($root.'/modules/negotiation-master/evaluation/evaluation-service.php');
$js=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-session.js');
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$checks=[];
function h366(&$c,$ok,$m){$c[]=$ok;if(!$ok)fwrite(STDERR,"FAIL: $m\n");}
h366($checks,ckm_test_current_plugin_release($plugin),'plugin version bumped');
h366($checks,str_contains($svc,'humanFallbackExplanation'),'server human fallback helper exists');
h366($checks,str_contains($svc,'if($degraded)$explanation=self::humanFallbackExplanation'),'degraded result uses human copy');
h366($checks,!str_contains($svc,'$explanation=\'Резервная формальная оценка:'),'server no longer stores technical fallback prefix');
h366($checks,str_contains($svc,'Итоговое соглашение соответствует целевым условиям'),'result-quality copy is human');
h366($checks,str_contains($svc,'Ключевые интересы и риски оппонента выявлены'),'interest-discovery copy is human');
h366($checks,str_contains($js,'humanizeEvaluationText'),'front-end compatibility humanizer exists');
h366($checks,str_contains($js,'Резервная формальная оценка|Качественная ИИ-часть временно недоступна'),'legacy technical copy is recognized');
h366($checks,str_contains($js,"why.textContent=cleanUserFacingText(humanizeEvaluationText(c.explanation,c.code,c.raw_score))"),'criterion note humanized and cleaned');
h366($checks,str_contains($js,"scoreByCode[String(c.code||'')]=c"),'summary has criterion score context');
h366($checks,str_contains($js,"scoreByCode);renderFindingList($('ckm-neg-improvements')"),'strengths and improvements humanized');
h366($checks,!str_contains($js,"why.textContent=String(c.explanation)"),'raw explanation is not rendered directly');
if(in_array(false,$checks,true))exit(1);echo 'PASS '.count($checks).'/'.count($checks)."\n";
