<?php
require_once __DIR__ . '/support/plugin-release.php';
$root = dirname(__DIR__);
$css = file_get_contents($root . '/modules/negotiation-master/assets/negotiation-app.css');
$plugin = file_get_contents($root . '/ckm-quiz-pro.php');
$checks = [];
function d358(&$checks,$ok,$label){$checks[] = [$ok,$label]; if(!$ok){fwrite(STDERR,"FAIL: $label\n");}}
d358($checks, ckm_test_current_plugin_release($plugin), 'plugin version');
d358($checks, str_contains($css,'NEG-DIALOGUE-VIEWPORT 358'), 'viewport patch marker');
d358($checks, preg_match('/\.ckm-neg-app-main \.ckm-neg-dialogue\{[^}]*min-height:max\(780px,calc\(100vh - 128px\)\)[^}]*max-height:none/s',$css)===1, 'dialogue may grow beyond viewport');
d358($checks, preg_match('/\.ckm-neg-app-main \.ckm-neg-messages\{[^}]*min-height:320px[^}]*max-height:52vh[^}]*overflow-y:auto/s',$css)===1, 'message history gets visible scrollable window');
d358($checks, str_contains($css,'.ckm-neg-app-main .ckm-neg-opponent,') && str_contains($css,'.ckm-neg-app-main .ckm-neg-compose{flex:0 0 auto}'), 'header and composer do not shrink');
d358($checks, preg_match('/\.ckm-neg-app-main \.ckm-neg-compose textarea\{[^}]*height:112px[^}]*max-height:160px/s',$css)===1, 'composer textarea compact');
d358($checks, str_contains($css,'@media(max-width:1080px)') && str_contains($css,'min-height:300px'), 'laptop breakpoint keeps history visible');
d358($checks, str_contains($css,'@media(max-width:720px)') && str_contains($css,'min-height:280px'), 'mobile breakpoint keeps history visible');
$passed = count(array_filter($checks, fn($x)=>$x[0]));
echo "NEG-DIALOGUE-VIEWPORT: $passed/".count($checks)." PASS\n";
exit($passed===count($checks)?0:1);
