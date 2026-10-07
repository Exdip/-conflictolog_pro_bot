<?php
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/');
interface CKM_Quiz_Host_Adapter_Interface { public function compose(string $event,array $context): array; }
function ckm_quiz_host_output(string $mode,string $event,string $text,array $meta=[]): array{return ['mode'=>$mode,'event'=>$event,'text'=>['content'=>$text],'meta'=>$meta];}
function wp_strip_all_tags($s){return strip_tags((string)$s);} 
function sanitize_key($s){return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/','',(string)$s));}
require dirname(__DIR__).'/includes/standalone-auto-host.php';
$n=0;function ck($b,$m){global $n;if(!$b){fwrite(STDERR,"FAIL $m\n");exit(1);}echo 'PASS '.(++$n)." $m\n";}
$host=new CKM_Quiz_AI_Host();
$game=['id'=>7,'format_key_snapshot'=>'negotiation_duel'];
$o=$host->compose('negotiation_show_finished',['game'=>$game,'showScores'=>[
 ['name'=>'Команда A','score'=>950],['name'=>'Команда B','score'=>1150],['name'=>'Команда C','score'=>800],
],'winners'=>['Команда B'],'winningScore'=>1150]);
$t=$o['text']['content']??'';
ck(str_contains($t,'Игра «Переговори другого» завершена.'),'names game in final announcement');
ck(str_contains($t,'Команда A — 950 очков')&&str_contains($t,'Команда B — 1150 очков')&&str_contains($t,'Команда C — 800 очков'),'announces all three totals');
ck(str_contains($t,'Победитель — Команда B. Результат — 1150 очков.'),'announces single winner once');
$o=$host->compose('negotiation_show_finished',['game'=>$game,'showScores'=>[
 ['name'=>'Команда A','score'=>1000],['name'=>'Команда B','score'=>1000],['name'=>'Команда C','score'=>900],
],'winners'=>['Команда A','Команда B'],'winningScore'=>1000]);
$t=$o['text']['content']??'';
ck(str_contains($t,'Первое место разделили Команда A и Команда B. Результат — 1000 очков.'),'announces tied first place without choosing one winner');
ck(substr_count($t,'Первое место разделили')===1,'tie announcement is not duplicated');
echo "ALL $n PASS\n";
