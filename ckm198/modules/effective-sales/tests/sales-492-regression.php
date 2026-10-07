<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
$root=dirname(__DIR__,3);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$validator=file_get_contents($root.'/modules/negotiation-master/ai/coach/coach-response-validator.php');
$checks=[
    'plugin version'=>ckm_test_current_plugin_release($main),
    'russian coach length error'=>str_contains($validator,'Ответ ИИ-тренера слишком длинный для выбранного уровня подсказки.'),
    'english coach length error removed'=>!str_contains($validator,'Coach response exceeds level length.'),
];
$failed=0; foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL')." - $name\n"; if(!$ok)$failed++;}
exit($failed?1:0);
