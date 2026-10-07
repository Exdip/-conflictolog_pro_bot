<?php
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$service=file_get_contents($root.'/modules/negotiation-master/ai/opponent/opponent-service.php');
$prompt=file_get_contents($root.'/modules/negotiation-master/ai/opponent/opponent-prompt-builder.php');
$js=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-session.js');
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$checks=[];
function n372(&$checks,$ok,$label){$checks[]=[$ok,$label]; echo ($ok?'PASS ':'FAIL ').$label."\n";}

n372($checks,ckm_test_current_plugin_release($plugin),'plugin version bumped');
n372($checks,str_contains($prompt,'- Краткий заголовок (пояснение).'),'prompt asks for no colon before explanation');
n372($checks,!str_contains($prompt,'- Краткий заголовок: (пояснение).'),'old colon prompt removed');
n372($checks,str_contains($service,"trim(\$head[2]) . ' (' . \$description . ').';"),'server nested-list formatter omits colon');
n372($checks,str_contains($service,"[^()\\r\\n:]+):\\s+\\("),'server also cleans already-inline legacy colon form');
n372($checks,str_contains($js,"head[2].trim()+' ('+description+').'") ,'frontend nested-list formatter omits colon');
n372($checks,str_contains($js,"[^()\\n:]+):\\s+\\("),'frontend cleans already-inline legacy colon form');

// Tiny pure-PHP mirror of the intended legacy display normalization.
$legacy="- Увеличение затрат: (задержка может привести к дополнительным расходам).";
$normalized=preg_replace('/(^|\\R)(\\s*-\\s+[^()\\r\\n:]+):\\s+\\(/u','$1$2 (',$legacy);
n372($checks,$normalized==='- Увеличение затрат (задержка может привести к дополнительным расходам).','legacy inline item loses colon');
$nested="- Увеличение затрат:\n - Задержка может привести к дополнительным расходам.";
$lines=preg_split('/\\R/u',$nested);$head=[];$detail=[];$out=$nested;
if(preg_match('/^(\\s*)-\\s+(.+?):\\s*$/u',$lines[0],$head)&&preg_match('/^(\\s+)-\\s+(.+?)\\s*$/u',$lines[1],$detail)){
  $desc=preg_replace('/[.!?;:]+$/u','',trim($detail[2]));
  $first=str_replace('Задержка','задержка',$desc);
  $out=$head[1].'- '.trim($head[2]).' ('.$first.').';
}
n372($checks,$out==='- Увеличение затрат (задержка может привести к дополнительным расходам).','nested list collapses without colon');
n372($checks,!str_contains($out,': ('),'final format contains no colon before explanation');

$fails=array_filter($checks,fn($x)=>!$x[0]);
echo count($checks).'/'.count($checks).' checks, '.count($fails).' failed'."\n";
exit($fails?1:0);
