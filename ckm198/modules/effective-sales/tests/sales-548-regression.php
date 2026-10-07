<?php
if (PHP_SAPI !== 'cli') { exit(1); }
$root=dirname(__DIR__,3);
$src=file_get_contents($root.'/modules/effective-sales/api/sales-controller.php');
if(strpos($src,'public static function result')===false) throw new RuntimeException('Result route missing.');
if(strpos($src,'$result=(new EvaluationService())->evaluate($id);')===false) throw new RuntimeException('Result route must refresh stale evaluation versions.');
if(strpos($src,'$result=(new EvaluationService())->result($id);')!==false) throw new RuntimeException('Legacy stale result reader still present.');
echo "SALES-548 regression passed.
";
