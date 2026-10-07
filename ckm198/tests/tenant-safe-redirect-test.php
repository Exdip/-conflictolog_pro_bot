<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$tenant=file_get_contents($root.'/includes/tenant-foundation.php');
$n=0;
function tsr271($ok,$label){global $n;$n++;if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}echo "OK: $label\n";}
tsr271(ckm_test_current_plugin_release($main),'version marker');
tsr271(str_contains($tenant,"add_filter('allowed_redirect_hosts','ckmqp_tenant_allowed_redirect_hosts',10,2)"),'allowed_redirect_hosts filter registered');
tsr271(str_contains($tenant,"ckmqp_tenant_table('domains')"),'uses tenant domains table');
tsr271(str_contains($tenant,'d.hostname=%s'),'exact hostname lookup');
tsr271(str_contains($tenant,"d.status IN ('active','reserved')"),'domain status guarded');
tsr271(str_contains($tenant,"t.status IN ('active','staged')"),'tenant status guarded');
tsr271(!str_contains($tenant,'$host===\'222.ckkm.ru\'') && !str_contains($tenant,'222.ckkm.ru'), 'no 222 hardcode');
tsr271(str_contains($tenant,'ckmqp_tenant_hostname($host)'),'host normalized');
tsr271(str_contains($tenant,'array_unique($hosts)'),'deduplicates allowed hosts');
$public=file_get_contents($root.'/includes/standalone-public.php');
tsr271(str_contains($public,"wp_safe_redirect(\$target,302,'CKM Quiz Pro')"),'public stale-version refresh uses safe redirect');
echo "ALL $n PASS\n";
