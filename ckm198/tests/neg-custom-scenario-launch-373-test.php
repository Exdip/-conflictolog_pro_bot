<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);$checks=[];
function n373(&$c,$ok,$label){$c[]=[$ok,$label];echo($ok?'PASS ':'FAIL ').$label."\n";}
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$svc=file_get_contents($root.'/modules/negotiation-master/application/scenario-builder-service.php');
$repo=file_get_contents($root.'/modules/negotiation-master/repositories.php');
$player=file_get_contents($root.'/modules/negotiation-master/public/player-page.php');
$builder=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-builder.js');
$legacy='custom-1-e2e-351-%d0%bf%d1%80%d0%be%d0%b2%d0%b5%d1%80%d0%ba%d0%b0-%d0%ba%d0%be%d0%bd%d1%81%d1%82%d1%80%d1%83%d0%ba%d1%82%d0%be%d1';
$valid=function(string $slug):bool{return $slug!==''&&strlen($slug)<=191&&preg_match('/\A[a-z0-9](?:[a-z0-9_-]|%[0-9a-f]{2})*\z/D',$slug)===1;};
n373($checks,ckm_test_current_plugin_release($plugin),'plugin version bumped');
n373($checks,str_contains($player,"neg_scenario_id")&&str_contains($player,'absint($_GET[\'neg_scenario_id\'])'),'player accepts stable scenario id');
n373($checks,!str_contains($player,"sanitize_key(wp_unslash(\$_GET['neg_scenario']))"),'legacy scenario locator no longer uses sanitize_key');
n373($checks,str_contains($player,'return $repo->findPublishedBySlug($slug);'),'legacy slug fallback retained');
n373($checks,str_contains($repo,'validStoredSlug')&&str_contains($repo,"%[0-9a-f]{2}"),'repository accepts validated percent-octet legacy slugs');
n373($checks,$valid($legacy),'real truncated legacy slug remains syntactically accepted');
n373($checks,!$valid('custom-1-%zz')&&!$valid('../scenario')&&!$valid('custom-1-<script>'),'unsafe legacy locators rejected');
n373($checks,str_contains($svc,"\$base = 'custom-' . max(1, \$userId) . '-scenario';"),'new custom slugs are ASCII-only');
n373($checks,!str_contains($svc,'$base = sanitize_title($title);'),'new slug no longer derives from localized title');
n373($checks,!str_contains($svc,"substr(\$base, 0, 120)"),'unsafe byte truncation removed');
n373($checks,str_contains($builder,"'neg_scenario_id='+encodeURIComponent(String(s.id))"),'builder launch uses scenario id');
n373($checks,substr_count($player,'self::scenarioArgs(')>=4,'catalog/attempt links use scenario id helper');
$fails=array_filter($checks,fn($x)=>!$x[0]);echo count($checks).'/'.count($checks).' checks, '.count($fails).' failed'."\n";exit($fails?1:0);
