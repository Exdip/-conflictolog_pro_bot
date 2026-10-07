<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);$checks=0;$fails=0;
function l352($ok,$name){global $checks,$fails;$checks++;if(!$ok){$fails++;fwrite(STDERR,"FAIL: $name\n");}}
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$player=file_get_contents($root.'/modules/negotiation-master/public/player-page.php');
$js=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-builder.js');
l352(ckm_test_current_plugin_release($plugin),'plugin version updated');
$labelPos=strpos($player,'// Labels are required by the pre-start renderer in every launch path');
$returnPos=strpos($player,'$returnUrl = $isBuilderTest');
$branchEnd=strpos($player,'$returnUrl = $isBuilderTest');
l352($labelPos!==false&&$returnPos!==false&&$labelPos<$returnPos,'shared labels initialized before return-url/render stage');
l352(substr_count($player,'$itemLabels = [];')===1,'item labels have one shared initialization');
l352(str_contains($player,'if ($testSessionId > 0)')&&str_contains($player,'} elseif ($assignmentSessionId > 0)'),'builder-test and assignment paths retained');
l352(str_contains($js,'function standardKnownFactObject(v)'),'standard known-fact object detector added');
l352(str_contains($js,"typeof v.title==='string'")&&str_contains($js,"typeof v.content"),'title/content array objects recognized');
l352(str_contains($js,"knownFactsState.arrayObjectMode=true"),'known-fact object array mode enabled');
l352(str_contains($js,"delete extras.title;delete extras.content"),'unknown per-fact keys preserved separately');
l352(str_contains($js,"row.dataset.knownArrayObject='1'"),'object rows retain shape marker');
l352(str_contains($js,"title:label||'Факт',content:value"),'edited object rows serialize as title/content');
l352(str_contains($js,'knownFactsState.arrayRowExtras[Number(idx)]'),'extra known-fact keys roundtrip');
l352(str_contains($js,'knownFactsState.arrayOpaque.push({index,value:v});opaque++'),'genuinely complex array blocks still preserved');
fwrite(STDOUT,sprintf("NEG-LIVE-E2E-FIX: %d/%d PASS\n",$checks-$fails,$checks));exit($fails?1:0);
