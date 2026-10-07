<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$root=dirname(__DIR__,3);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$page=file_get_contents($root.'/modules/effective-sales/public/sales-page.php');
$passed=0;
function s504($name,$ok){global $passed;if(!$ok)throw new RuntimeException('FAIL: '.$name);$passed++;echo "PASS: {$name}\n";}
s504('plugin version',ckm_test_current_plugin_release($main));
s504('home flow uses explicit action wording',str_contains($page,'4. Договориться о следующем шаге'));
s504('session fallback uses explicit action wording',str_contains($page,"['Разобраться','Показать ценность','Снять сомнение','Договориться о следующем шаге']"));
s504('stored mechanics fourth stage is normalized for display',str_contains($page,"if(isset(\$stages[3]))\$stages[3]='Договориться о следующем шаге';"));
s504('stage remains passive indicator',str_contains($page,'id="ckm-sales-stagebar"')&&str_contains($page,'role="list"'));
echo "{$passed} SALES-504 regression checks passed. No database required.\n";
