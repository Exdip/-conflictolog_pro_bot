<?php
if(PHP_SAPI!=='cli')exit;
$root=dirname(__DIR__);$public=file_get_contents($root.'/includes/standalone-public.php');$show=file_get_contents($root.'/includes/negotiation-show.php');$dialogue=file_get_contents($root.'/includes/negotiation-show-dialogue.php');$host=file_get_contents($root.'/includes/standalone-auto-host.php');$voice=file_get_contents($root.'/includes/standalone-voice.php');$checks=[];
function ck130($ok,$name){global $checks;$checks[]=[$ok,$name];}
ck130(!str_contains($public,'if (!ckmqp_show_is_game($showPrepGame ?: []))'),'show no longer suppresses AI-host/voice scripts');
ck130(str_contains($public,'assets/standalone-ai-host.js')&&str_contains($public,'assets/standalone-voice.js'),'AI host and voice assets are loaded');
ck130(str_contains($show,'ckmqp_show_ai_host_events')&&str_contains($show,"==='ai_host_message'"),'show state exposes only public AI-host messages');
foreach(['negotiation_show_preparation_started','negotiation_show_dialogue_started','negotiation_show_hard_question','negotiation_show_review_started','negotiation_show_review_finished','negotiation_show_story_started','negotiation_show_story_questions','negotiation_show_story_answer','negotiation_show_story_vote','negotiation_show_story_result','negotiation_show_next_attempt','negotiation_show_round_started'] as $event) ck130(str_contains($dialogue,$event),$event.' transition exists');
ck130(str_contains($dialogue,"'ai-negotiation-show-'.\$suffix"),'AI host transition has idempotent event key');
ck130(str_contains($host,"str_starts_with(\$event,'negotiation_show_')")&&str_contains($host,"showHostText"),'AI host composes show transition text');
ck130(str_contains($voice,"'negotiation_show_story_result'")&&str_contains($voice,"'negotiation_show_next_attempt'"),'voice bridge categorizes show events');
$fail=array_filter($checks,fn($x)=>!$x[0]);foreach($checks as [$ok,$name])echo ($ok?'PASS':'FAIL')."\t$name\n";exit($fail?1:0);

