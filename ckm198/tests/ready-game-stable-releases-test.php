<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$c=file_get_contents($root.'/includes/ready-games-catalog.php');
$p=file_get_contents($root.'/ckm-quiz-pro.php');
$checks=[];
function r263(&$c,$ok,$label){$c[]=[$ok,$label];if(!$ok)fwrite(STDERR,"FAIL: $label\n");}
r263($checks,ckm_test_current_plugin_release($p),'version');
r263($checks,str_contains($c,'function ckm_quiz_pro_ready_game_release_snapshot'),'release snapshot reader');
r263($checks,str_contains($c,'function ckm_quiz_pro_ready_game_capture_release'),'release capture');
r263($checks,str_contains($c,'_ckm_ready_release_snapshot'),'release stored in post meta');
r263($checks,str_contains($c,'_ckm_ready_release_quiz_id'),'immutable release quiz id stored');
r263($checks,str_contains($c,'ready-release-'),'release templates marked as technical');
r263($checks,str_contains($c,"'post_status'=>['publish','draft','private']"),'registry keeps released drafts discoverable');
r263($checks,str_contains($c,'$item[\'orderable\']=$status!==\'private\''),'live release stays orderable while working draft exists');
r263($checks,str_contains($c,"if(!\$includeNonPublic && \$status==='private') continue"),'hidden status still removes public release');
r263($checks,str_contains($c,'ckm_quiz_pro_ready_game_capture_release($postId,$catalogRevision)'),'publish freezes tested release');
r263($checks,str_contains($c,'$fields[\'product_key\']=sanitize_key((string)$existingRelease[\'product\'])'),'product key locked after release');
r263($checks,str_contains($c,'Покупатели продолжают видеть последнюю опубликованную версию'),'admin explains stable release');
r263($checks,str_contains($c,'В каталоге v'),'admin table shows live revision');
r263($checks,str_contains($c,'ckm_quiz_pro_ready_release_seed_v1'),'upgrade bridge seeds existing published release');
$failed=count(array_filter($checks,fn($x)=>!$x[0]));
echo (count($checks)-$failed).'/'.count($checks)."\n";
exit($failed?1:0);
