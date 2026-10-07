<?php
require_once __DIR__ . '/support/plugin-release.php';
$root = dirname(__DIR__);
$js = file_get_contents($root . '/assets/completion-signal/completion-signal.js');
$php = file_get_contents($root . '/includes/completion-signal.php');
$main = file_get_contents($root . '/ckm-quiz-pro.php');
$checks = [
  'version' => ckm_test_current_plugin_release($main),
  'notification permission' => strpos($js, 'Notification.requestPermission') !== false,
  'persistent last signal' => strpos($js, "ckm_completion_sound_last_id") !== false,
  'missed signal replay' => strpos($js, 'if(baseline||!lastId)') !== false && strpos($js, 'if(id&&id!==lastId)') !== false,
  'notification fallback' => strpos($js, "new Notification('CKM — готово'") !== false,
  'honest state label' => strpos($js, 'НУЖЕН КЛИК') !== false,
  'test requests permission' => substr_count($js, 'ensureNotificationPermission()') >= 3,
  'admin hint updated' => strpos($php, 'разрешите уведомления') !== false,
];
foreach ($checks as $name => $ok) {
  if (!$ok) { fwrite(STDERR, "FAIL: $name\n"); exit(1); }
  echo "OK: $name\n";
}
echo 'PASS ' . count($checks) . '/' . count($checks) . "\n";
