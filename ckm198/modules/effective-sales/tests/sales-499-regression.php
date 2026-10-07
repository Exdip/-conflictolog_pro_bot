<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$root=dirname(__DIR__,3);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$signal=file_get_contents($root.'/includes/completion-signal.php');
$js=file_get_contents($root.'/assets/completion-signal/completion-signal.js');
$css=file_get_contents($root.'/assets/completion-signal/completion-signal.css');
$passed=0;
function s499(string $n,bool $ok):void{global $passed;if(!$ok)throw new RuntimeException('FAIL: '.$n);$passed++;echo "PASS: $n\n";}
s499('plugin version',ckm_test_current_plugin_release($main));
s499('completion signal module loaded',str_contains($main,"includes/completion-signal.php"));
s499('admin controls remain protected in wp-admin',!str_contains($signal,"wp_footer")&&str_contains($signal,"admin_footer")&&str_contains($signal,"manage_options"));
s499('completion signal REST route has public read and protected write',str_contains($signal,"register_rest_route('ckm/v1', self::ROUTE")&&str_contains($signal,"'permission_callback' => '__return_true'")&&str_contains($signal,'current_user_can(\'manage_options\')'));
s499('sound controls exist',str_contains($signal,'Звук завершения: ВЫКЛ')&&str_contains($signal,'Проверить звук'));
s499('browser polls only while enabled and at low frequency',str_contains($js,"if(!enabled)return")&&str_contains($js,'setInterval')&&str_contains($signal,"'pollMs' => 10000"));
s499('browser generates beep and Russian voice',str_contains($js,'createOscillator')&&str_contains($js,'SpeechSynthesisUtterance')&&str_contains($js,"u.lang='ru-RU'"));
s499('sound preference is browser-local',str_contains($js,'localStorage')&&str_contains($js,"ckm_completion_sound_enabled"));
s499('console widget styling remains available',str_contains($css,'position:fixed')&&str_contains($css,'z-index:100000'));
echo "$passed SALES-499 regression checks passed. No database tables required.\n";
