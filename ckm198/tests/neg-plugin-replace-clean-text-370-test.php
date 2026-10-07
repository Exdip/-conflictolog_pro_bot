<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$checks=[];
function t370(&$c,$ok,$name){$c[]=[$ok,$name]; if(!$ok){fwrite(STDERR,"FAIL: $name\n");}}
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$opp=file_get_contents($root.'/modules/negotiation-master/ai/opponent/opponent-service.php');
$agr=file_get_contents($root.'/modules/negotiation-master/ai/opponent/agreement-response-service.php');
$coach=file_get_contents($root.'/modules/negotiation-master/ai/coach/coach-service.php');
$js=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-session.js');
$prompt=file_get_contents($root.'/modules/negotiation-master/ai/opponent/opponent-prompt-builder.php');
t370($checks,ckm_test_current_plugin_release($plugin),'plugin version bumped');
t370($checks,str_contains($opp,"str_replace('**', '', ".'$text'.")")&&str_contains($opp,"str_replace('*', '', ".'$text'.")"),'opponent strips stars');
t370($checks,str_contains($opp,'\\d{1,3}[\\.)]')&&str_contains($opp,"'$1- '"),'opponent numbered lists become hyphens');
t370($checks,str_contains($agr,"str_replace(['**','*'],'',".'$text'.")"),'agreement reply strips stars');
t370($checks,str_contains($coach,"str_replace(['**','*'],'',".'$text'.")"),'coach strips stars');
t370($checks,str_contains($js,'function cleanUserFacingText(text)'),'client has historical display cleaner');
t370($checks,str_contains($js,".replace(/\\*+/g,'')"),'client strips stars');
t370($checks,str_contains($js,"[.)]\\s+/g,'$1- '") ,'client normalizes numbered lists');
t370($checks,str_contains($prompt,'Не используй Markdown, звёздочки')&&str_contains($prompt,'каждый пункт пиши строго в одну строку'),'opponent prompt requests plain one-line hyphen lists');
t370($checks,basename($root)==='ckm198','working plugin directory is canonical ckm198');
$failed=count(array_filter($checks,fn($x)=>!$x[0]));
echo (count($checks)-$failed).'/'.count($checks)." PASS\n"; exit($failed?1:0);
