<?php
if(!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
if(!function_exists('add_action')){function add_action(...$x){}}
if(!function_exists('wp_json_encode')){function wp_json_encode($v,$f=0){return json_encode($v,$f);}}
if(!function_exists('ckm_quiz_json_decode')){function ckm_quiz_json_decode($s){return json_decode($s,true)?:[];}}
if(!function_exists('sanitize_key')){function sanitize_key($x){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$x));}}
require_once __DIR__.'/../includes/persuade-me-content.php';
require_once __DIR__.'/../includes/negotiation-show-dialogue.php';
function ck177($ok,$msg){if(!$ok){fwrite(STDERR,"FAIL: $msg\n");exit(1);}echo "PASS: $msg\n";}
$gameOff=['format_settings_snapshot_json'=>'{"persuadeStoryAnswerSeconds":30}'];
$s=ckmqp_show_initial([]);
$s['round']=3;$s['phase']='story_answer';$s['storyAnswerLimit']=30;$s['deadline']=12345;$s['storyAnswerPolicyConfigured']=true;$s['storyAnswerLimitConfigured']=true;
unset($s['storyAnswerPolicyVersion']);
$m=ckmqp_show_apply_story_answer_policy($s,$gameOff);
ck177((int)$m['storyAnswerLimit']===0,'legacy active room migrates to untimed answer policy');
ck177((int)$m['deadline']===0,'legacy active answer deadline is cleared');
ck177((int)$m['storyAnswerPolicyVersion']===2,'policy migration version is recorded');
$gameOn=['format_settings_snapshot_json'=>'{"persuadeStoryAnswerLimitEnabled":true,"persuadeStoryAnswerSeconds":60}'];
$t=$m;$t['storyAnswerLimit']=0;$t['storyAnswerPolicyVersion']=2;
$t=ckmqp_show_apply_story_answer_policy($t,$gameOn);
ck177((int)$t['storyAnswerLimit']===60,'explicit pressure mode remains enabled');
$u=$t;$u['deadline']=999;$u['storyAnswerLimit']=60;$u=ckmqp_show_apply_story_answer_policy($u,$gameOff);
ck177((int)$u['storyAnswerLimit']===0 && (int)$u['deadline']===0,'policy resync removes stale enabled timer when game says off');
