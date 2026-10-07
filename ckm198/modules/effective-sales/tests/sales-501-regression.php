<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
$root=dirname(__DIR__,3);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$signal=file_get_contents($root.'/includes/completion-signal.php');
function s501($name,$ok){echo ($ok?'PASS':'FAIL')." sales-501 $name\n";if(!$ok)exit(1);}
s501('version',ckm_test_current_plugin_release($main));
s501('completion signal module loaded',str_contains($main,"includes/completion-signal.php"));
s501('admin assets remain',str_contains($signal,"add_action('admin_enqueue_scripts'")&&str_contains($signal,"add_action('admin_footer'"));
s501('frontend hooks removed',!str_contains($signal,"add_action('wp_enqueue_scripts'")&&!str_contains($signal,"add_action('wp_footer'"));
s501('frontend enqueue method removed',!str_contains($signal,'function enqueue_frontend'));
s501('signal endpoint preserved',str_contains($signal,"private const ROUTE  = '/completion-signal'")&&str_contains($signal,"'methods' => 'POST'"));
s501('console controls preserved',str_contains($signal,'Звук завершения: ВЫКЛ')&&str_contains($signal,'Проверить звук'));
