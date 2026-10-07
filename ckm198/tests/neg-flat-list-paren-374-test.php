<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);$checks=[];
function n374(&$c,$ok,$label){$c[]=[$ok,$label];echo($ok?'PASS ':'FAIL ').$label."\n";}
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$service=file_get_contents($root.'/modules/negotiation-master/ai/opponent/opponent-service.php');
$js=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-session.js');
$prompt=file_get_contents($root.'/modules/negotiation-master/ai/opponent/opponent-prompt-builder.php');
n374($checks,ckm_test_current_plugin_release($plugin),'plugin version bumped');
n374($checks,str_contains($service,'preg_replace_callback'),'server normalizes flat colon bullets');
n374($checks,str_contains($service,'$title . \' (\' . $description . \').\''),'server outputs parenthetical bullet without colon');
n374($checks,str_contains($js,"String(title).trim()+' ('+description+').'"),'frontend normalizes stored flat colon bullets');
n374($checks,str_contains($prompt,'- Краткий заголовок (пояснение).'),'prompt keeps requested list format');

$lowerFirst=function(string $text):string{
  $map=['У'=>'у','З'=>'з','Е'=>'е','С'=>'с','П'=>'п','К'=>'к','И'=>'и'];
  foreach($map as $u=>$l){if(str_starts_with($text,$u))return $l.substr($text,strlen($u));}
  return strtolower(substr($text,0,1)).substr($text,1);
};
$normalize=function(string $text)use($lowerFirst):string{
  return preg_replace_callback('/(^|\\R)(\\s*-\\s+)([^()\\r\\n:]{1,120}):\\s+([A-Za-zА-Яа-яЁё][^\\r\\n]*)(?=\\R|$)/u',function($m)use($lowerFirst){
    $title=trim($m[3]);$d=trim($m[4]);$d=preg_replace('/[.!?;:]+$/u','',$d);$d=$lowerFirst($d);
    return $m[1].$m[2].$title.' ('.$d.').';
  },$text);
};
$live="- Увеличение затрат: Задержки могут привести к дополнительным расходам, связанным с хранением, простоями или необходимостью срочного заказа альтернативных материалов.\n- Срыв графика: Если поставка задерживается, это может нарушить общий график проекта.";
$out=$normalize($live);
n374($checks,str_contains($out,'- Увеличение затрат (задержки могут привести к дополнительным расходам, связанным с хранением, простоями или необходимостью срочного заказа альтернативных материалов).'),'live risk bullet normalized');
n374($checks,str_contains($out,'- Срыв графика (если поставка задерживается, это может нарушить общий график проекта).'),'second live risk bullet normalized');
$trade=$normalize("- Цена: Если стоимость является ключевым фактором, можно рассмотреть снижение цены.\n- Срок поставки: Если срок критичен, можно увеличить бюджет.");
n374($checks,$trade==="- Цена (если стоимость является ключевым фактором, можно рассмотреть снижение цены).\n- Срок поставки (если срок критичен, можно увеличить бюджет).",'live trade bullets normalized exactly');
n374($checks,!str_contains($out,'- Увеличение затрат:'),'colon removed after bullet title');
n374($checks,!str_contains($trade,'- Цена:'),'trade title colon removed');
$numeric="- Цена: 960 000 руб.\n- Срок: 40 дней.";
n374($checks,$normalize($numeric)===$numeric,'numeric deal bullets stay unchanged');

$fails=array_filter($checks,fn($x)=>!$x[0]);echo count($checks).'/'.count($checks).' checks, '.count($fails).' failed'."\n";exit($fails?1:0);
