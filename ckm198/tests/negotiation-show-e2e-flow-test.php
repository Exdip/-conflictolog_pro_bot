<?php
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/');
function add_action(...$x){}
function ckm_quiz_json_decode($s){return json_decode($s,true)?:[];}
require dirname(__DIR__).'/includes/persuade-me-content.php';
require dirname(__DIR__).'/includes/negotiation-show.php';
require dirname(__DIR__).'/includes/negotiation-show-dialogue.php';
require dirname(__DIR__).'/includes/negotiation-show-review.php';
require dirname(__DIR__).'/includes/negotiation-show-hidden.php';
require dirname(__DIR__).'/includes/negotiation-show-hard-question.php';
require dirname(__DIR__).'/includes/negotiation-show-story.php';
require dirname(__DIR__).'/includes/games-hub-smoke.php';
$r=ckmqp_games_hub_smoke_persuade_full_flow();
if(empty($r['ok'])){fwrite(STDERR,'FAIL '.($r['detail']??'unknown')."\n");exit(1);} 
echo 'PASS '.($r['detail']??'')."\n";
