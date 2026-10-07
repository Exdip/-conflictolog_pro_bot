<?php
if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
if (!function_exists('add_action')) { function add_action(...$x){} }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v,$f=0){ return json_encode($v,$f); } }
if (!function_exists('ckm_quiz_json_decode')) { function ckm_quiz_json_decode($s){ return json_decode($s,true)?:[]; } }
require dirname(__DIR__).'/includes/persuade-me-content.php';
require dirname(__DIR__).'/includes/negotiation-show-story.php';
require dirname(__DIR__).'/includes/negotiation-show-dialogue.php';
$ok=0;$bad=0;function ck176($c,$m){global $ok,$bad;if($c){$ok++;echo "PASS $m\n";}else{$bad++;echo "FAIL $m\n";}}
ck176(ckmqp_show_story_answer_limit_from_game(['format_settings_snapshot_json'=>'{}'])===0,'default final answers are untimed');
ck176(ckmqp_show_story_answer_limit_from_game(['format_settings_snapshot_json'=>'{"persuadeStoryAnswerSeconds":30}'])===0,'legacy saved seconds do not silently enable timer');
ck176(ckmqp_show_story_answer_limit_from_game(['format_settings_snapshot_json'=>'{"persuadeStoryAnswerLimitEnabled":true,"persuadeStoryAnswerSeconds":20}'])===20,'optional 20 second pressure mode remains available');
ck176(ckmqp_show_story_answer_limit_from_game(['format_settings_snapshot_json'=>'{"persuadeStoryAnswerLimitEnabled":true,"persuadeStoryAnswerSeconds":60}'])===60,'optional 60 second text limit remains available');
$s=ckmqp_show_initial([]);ck176((int)$s['storyAnswerLimit']===0,'new session default answer limit is zero');
$legacy=$s;$legacy['round']=3;$legacy['phase']='story_answer';$legacy['storyAnswerLimit']=30;$legacy['deadline']=100;$legacy['storyAnswerPolicyConfigured']=null;unset($legacy['storyAnswerPolicyConfigured']);$legacy=ckmqp_show_normalize_state($legacy);ck176((int)$legacy['deadline']===100,'normalization alone preserves legacy deadline until game policy is loaded');
$s['round']=3;$s['phase']='story_answer';$s['deadline']=0;$s['storyQuestionIndex']=0;$s['attempt']=0;$t=ckmqp_show_tick($s,999999);ck176($t['phase']==='story_answer'&&!isset($t['storyAnswers'][0][0]),'untimed answer does not auto-expire');
$timed=$s;$timed['storyAnswerLimit']=30;$timed['deadline']=100;$timed=ckmqp_show_tick($timed,101);ck176(!empty($timed['storyAnswers'][0][0]['timedOut']),'explicit timed mode still expires unanswered response');
echo "RESULT $ok PASS / $bad FAIL\n";exit($bad?1:0);
