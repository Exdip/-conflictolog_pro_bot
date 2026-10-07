<?php
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit; }
$root=dirname(__DIR__);$checks=0;$fails=0;
function b343(bool $ok,string $label): void {global $checks,$fails;$checks++;if(!$ok){$fails++;fwrite(STDERR,"FAIL: $label\n");}}
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$boot=file_get_contents($root.'/modules/negotiation-master/bootstrap.php');
$page=file_get_contents($root.'/modules/negotiation-master/public/builder-page.php');
$js=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-builder.js');
$svc=file_get_contents($root.'/modules/negotiation-master/application/scenario-builder-service.php');
$migration=file_get_contents($root.'/modules/negotiation-master/content-migration.php');
$seed=file_get_contents($root.'/modules/negotiation-master/content/system-v1.php');
b343(ckm_test_current_plugin_release($plugin),'plugin build updated');
b343(ckm_test_declared_version_at_least($boot,'CKM_NEG_DB_VERSION','1.8.0')&&ckm_test_declared_version_at_least($boot,'CKM_NEG_CONTENT_VERSION','1.5.0'),'DB unchanged and content bumped');
b343(str_contains($page,'Что вы сделаете, если соглашение не будет достигнуто')&&str_contains($page,'Ценность альтернативы, 0–100'),'Russian alternative fields');
b343(str_contains($page,'nb-player-alt-score-enabled')&&str_contains($page,'nb-player-alt-score')&&!str_contains($page,'id="nb-player-alt"'),'raw player alternative JSON removed from UI');
b343(str_contains($js,'playerAlternativeFromForm')&&str_contains($js,"model:'player_achievement_score'")&&str_contains($js,'score<0||score>100'),'builder composes formal alternative safely');
b343(str_contains($js,'playerAlternativeExtras')&&str_contains($js,'fillPlayerAlternative'),'unknown alternative fields preserved in editor cycle');
b343(str_contains($svc,'Ценность альтернативы без соглашения должна быть числом от 0 до 100.'),'server validation user-facing');
b343(str_contains($migration,'$targetVersionNumber')&&str_contains($migration,'$targetRows')&&str_contains($migration,'$targetFresh'),'content migration targets immutable version number');
b343(str_contains($migration,'System scenario current version is newer than content pack.'),'migration refuses downgrade');
b343(version_compare((json_decode(preg_replace("/^.*?CKM_NEG_SEED_JSON'\n(.*)\nCKM_NEG_SEED_JSON.*$/s", '$1', $seed),true)['pack_version']??'0'),'1.5.0','>=')&&str_contains($seed,'"slug": "contract-supply"'),'content pack bumped');
$start=strpos($seed,'"slug": "contract-supply"');$slice=substr($seed,$start,5500);
b343(str_contains($slice,'"version_number": 2')&&str_contains($slice,'"model": "player_achievement_score"')&&str_contains($slice,'"score": 65'),'contract supply v2 has formal alternative');
b343(!str_contains($page,'BATNA'),'builder keeps visible labels in Russian');
fwrite(STDOUT,sprintf("NEG-BATNA-BUILDER: %d/%d PASS\n",$checks-$fails,$checks));exit($fails?1:0);
