<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$runtime=file_get_contents($root.'/core-source/includes/quiz/quiz-format-runtime.php');
$js=file_get_contents($root.'/assets/standalone-game.js');
$public=file_get_contents($root.'/includes/standalone-public.php');
$checks=[];
function ck206(&$checks,$name,$ok){$checks[]=[$name,(bool)$ok];}
ck206($checks,'current plugin version',ckm_test_current_plugin_release($main));
ck206($checks,'express snapshot normalized to 60',str_contains($runtime,"if (\$mode === 'express') \$settings['secondsPerTurn'] = 60;"));
ck206($checks,'express effective timer forced to 60',str_contains($runtime,"if (\$mode === 'express') return 60;"));
ck206($checks,'hub override evaluated after express normalization',strpos($runtime,"if (\$mode === 'express') return 60;") < strpos($runtime,"hubAnswerTimeOverride"));
ck206($checks,'dictation instruction exists',str_contains($js,'дождитесь красной надписи «Говорите»'));
ck206($checks,'dictation warns speak only after cue',str_contains($js,'Начинайте говорить только после её появления.'));
ck206($checks,'ready cue exact text',str_contains($js,"sttStatus('Говорите',true)"));
ck206($checks,'interim fallback exact cue',str_contains($js,"'Говорите',true"));
ck206($checks,'instruction mounted in common STT wrapper',str_contains($js,'sttWrap.append(sttButton,sttInterim,sttInstruction)'));
ck206($checks,'instruction styled globally',str_contains($public,'.voice-instruction{flex-basis:100%'));
ck206($checks,'live cue is red and emphasized',str_contains($public,'.voice-badge.live{border-color:#b53a4c;color:#ff6b6b;font-weight:700}'));
$fail=0;foreach($checks as [$name,$ok]){echo ($ok?'PASS':'FAIL')."\t$name\n";if(!$ok)$fail++;}
echo count($checks).'/'.count($checks).' checks, failures='.$fail."\n";
exit($fail?1:0);
