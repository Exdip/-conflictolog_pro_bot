<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$root=dirname(__DIR__,3);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$page=file_get_contents($root.'/modules/effective-sales/public/sales-page.php');
$js=file_get_contents($root.'/modules/effective-sales/assets/sales-session.js');
$css=file_get_contents($root.'/modules/effective-sales/assets/sales-app.css');
$passed=0;
function s503($name,$ok){global $passed;if(!$ok)throw new RuntimeException('FAIL: '.$name);$passed++;echo "PASS: {$name}\n";}
s503('plugin version',ckm_test_current_plugin_release($main));
s503('stagebar has semantic progress markup',str_contains($page,'id="ckm-sales-stagebar"')&&str_contains($page,'role="list"')&&str_contains($page,'data-stage-index'));
s503('future stages are neutral on first render',str_contains($page,"\$i===0?'is-current':'is-future'"));
s503('live stage renderer exists',str_contains($js,'function stageProgress(s)')&&str_contains($js,'function renderStageProgress(s)'));
s503('state render updates stages',str_contains($js,'renderDiscoveries(s);renderStageProgress(s);'));
s503('discovery gate requires customer facts',str_contains($js,'fullFacts.length>=2'));
s503('value stage requires grounded customer value',str_contains($js,'const value=')&&str_contains($js,'const grounded=')&&str_contains($js,'поможет'));
s503('objection stage follows explicit client resolution',str_contains($js,'stage3=false;if(stage2)')&&str_contains($js,'снят|закрыт|решен|решён')&&str_contains($js,'обоснован|приемлем|устраива'));
s503('next step excludes pressure',str_contains($js,'function isConcreteNextStepProposal(v)')&&str_contains($js,'if(isPressureText(t))return false')&&str_contains($js,'valueClaim&&!isPressureText(t)')&&str_contains($js,"badge.textContent=done?'✓'"));
s503('stages are visually passive',str_contains($css,'cursor:default')&&str_contains($css,'.ckm-sales-stagebar span.is-future'));
echo "{$passed} SALES-503 regression checks passed. No database required.\n";
