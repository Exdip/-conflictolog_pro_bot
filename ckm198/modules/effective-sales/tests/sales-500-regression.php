<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
$root=dirname(__DIR__,3);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$service=file_get_contents($root.'/modules/effective-sales/application/sales-competition-service.php');
$api=file_get_contents($root.'/modules/effective-sales/api/sales-controller.php');
$js=file_get_contents($root.'/modules/effective-sales/assets/sales-session.js');
function s500(string $label,bool $ok):void{echo ($ok?'PASS ':'FAIL ').$label."\n";if(!$ok)exit(1);}
s500('plugin version',ckm_test_current_plugin_release($main));
s500('team token presence is explicit',str_contains($service,'teamAuthRequested(): bool'));
s500('signed team identity wins over existing login',str_contains($api,'SalesCompetitionService::teamAuthRequested()')&&str_contains($api,"sales_team_link_invalid")&&strpos($api,'teamAuthRequested()')<strpos($api,"elseif (!is_user_logged_in())"));
s500('tenant origin is preserved for REST and navigation',str_contains($js,'function tenantUrl(raw)')&&str_contains($js,"u.host=location.host")&&str_contains($js,'const restBase=tenantUrl(cfg.rest)'));
s500('team REST calls omit wp nonce',str_contains($js,"if(!cfg.teamJoin&&cfg.nonce&&!headers['X-WP-Nonce'])"));
s500('launch URL is normalized through team auth helper',str_contains($js,'location.href=withTeamAuth(data.launch.url)'));
