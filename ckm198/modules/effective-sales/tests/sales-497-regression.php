<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
$root=dirname(__DIR__,3);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$service=file_get_contents(dirname(__DIR__).'/application/sales-competition-service.php');
$passed=0;
function s497(string $name,bool $ok): void { global $passed; if(!$ok){fwrite(STDERR,"FAIL: $name\n"); exit(1);} $passed++; }
s497('plugin version',ckm_test_current_plugin_release($main));
s497('team key uses normalized hash',str_contains($service,'return \'team-\' . substr(hash(\'sha256\', $normalized), 0, 24);'));
s497('team key no longer truncates percent encoded slug',!str_contains($service,'return substr($base, 0, 80);'));
s497('team names still checked for duplicates',str_contains($service,"Названия команд не должны повторяться."));
s497('competition still supports two to six teams',str_contains($service,'count($rawTeams) < 2 || count($rawTeams) > 6'));
echo "$passed SALES-497 regression checks passed. No database required.\n";
