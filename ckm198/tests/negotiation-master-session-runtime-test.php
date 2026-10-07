<?php
require_once __DIR__ . '/support/plugin-release.php';
if (PHP_SAPI !== 'cli') exit;
$root=dirname(__DIR__);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$boot=file_get_contents($root.'/modules/negotiation-master/bootstrap.php');
$repos=file_get_contents($root.'/modules/negotiation-master/repositories.php');
$session=file_get_contents($root.'/modules/negotiation-master/application/session-service.php');
$message=file_get_contents($root.'/modules/negotiation-master/application/message-service.php');
$snapshot=file_get_contents($root.'/modules/negotiation-master/application/player-session-snapshot-builder.php');
$api=file_get_contents($root.'/modules/negotiation-master/api/session-controller.php');
$page=file_get_contents($root.'/modules/negotiation-master/public/player-page.php');
$js=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-session.js');
$checks=0;
function ck320($ok,$label){global $checks;$checks++;echo ($ok?'PASS':'FAIL').': '.$label.PHP_EOL;if(!$ok)exit(1);}
ck320(ckm_test_current_plugin_release($main),'plugin version bumped to .320');
foreach(['player-session-snapshot-builder.php','session-service.php','message-service.php','session-controller.php','player-page.php'] as $name){ck320(str_contains($boot,$name),'bootstrap loads '.$name);}
foreach(['/negotiation/sessions/start','/messages','/resume','/pause'] as $route){ck320(str_contains($api,$route),'REST route '.$route);}
ck320(str_contains($repos,"'status'=>'not_discussed'") && str_contains($repos,"'status' => 'in_progress'"),'runtime session/item initial states');
ck320(str_contains($message,'clientMessageId') && str_contains($repos,'findByClientId'),'message idempotency path exists');
ck320(str_contains($session,'ACTIVE')===false && str_contains($session,'active_exists'),'active session handled by service result');
ck320(str_contains($session,"'abandoned'") && str_contains($session,"['in_progress','paused']"),'restart abandons old active attempt');
ck320(str_contains($page,"[ckm_negotiation_master]") && str_contains($page,'ckm-negotiation-master'),'standalone player page installed');
ck320(str_contains($js,'crypto.randomUUID') && str_contains($js,'pendingClientId'),'client retries reuse stable message ID');
foreach(['opponent_hidden_interests','opponent_constraints','opponent_concession_space','opponent_walkaway','reveal_rules_json'] as $secret){
    // A public result type such as opponent_walkaway_caused is not a secret field.
    $fieldPattern='/(?<![A-Za-z0-9_])'.preg_quote($secret,'/').'(?:_json)?(?![A-Za-z0-9_])/';
    ck320(!preg_match($fieldPattern,$snapshot),'snapshot builder excludes '.$secret);
    ck320(!preg_match($fieldPattern,$js),'frontend excludes '.$secret);
}
// Partial discoveries now replace hidden content with an approved public summary.
ck320(str_contains($snapshot, "\$row['reveal_level'] < 2") && str_contains($snapshot, "\$row['title'] = 'Частично выяснено'") && str_contains($snapshot, "\$decoded['public_summary']") && str_contains($snapshot, "\$row['content'] = \$summary !== '' ? \$summary :") && str_contains($snapshot, "unset(\$row['hidden_fact_id'], \$row['code'])"), 'partial fact exposes approved public summary instead of hidden title and full content');
ck320(str_contains($repos,"channel IN ('dialogue','negotiation')"),'legacy dialogue and new negotiation channels both readable');
ck320(str_contains($page,'Сохранить и выйти') && str_contains($page,'Осталось решить'),'minimal three-panel session UI present');
echo 'TOTAL '.$checks.'/'.$checks.' PASS'.PHP_EOL;
