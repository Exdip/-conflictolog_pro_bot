<?php
$root=dirname(__DIR__);
$f=file_get_contents($root.'/includes/test-payment-bridge.php');
$checks=[];
function ck209(&$c,$n,$ok){$c[]=[$n,(bool)$ok];}
ck209($checks,'transport map school',str_contains($f,"'persuade_school_v1'  => 'classic_quiz'"));
ck209($checks,'transport map student',str_contains($f,"'persuade_student_v1' => 'classic_quiz'"));
ck209($checks,'transport map leader',str_contains($f,"'persuade_leader_v1'  => 'classic_quiz'"));
ck209($checks,'transport map family',str_contains($f,"'persuade_family_v1'  => 'classic_quiz'"));
ck209($checks,'remote create uses transport keys',str_contains($f,"'format_keys'=>\$transport_keys"));
ck209($checks,'accept verifies transport keys',str_contains($f,"(\$remote['format_keys']??null)!==\$transport_keys"));
ck209($checks,'local grants desired keys',str_contains($f,'foreach ($desired_keys as $format)'));
ck209($checks,'postpay remembers desired keys',str_contains($f,'ckmqp_after_payment_remember_cart((int)$order[\'user_id\'], $desired_keys'));
ck209($checks,'mixed scenario carts blocked',str_contains($f,"count((array)\$cart['format_keys'])!==1"));
ck209($checks,'price parity guard',str_contains($f,'ckmqp_test_validate_transport_prices'));
$bad=array_filter($checks,fn($x)=>!$x[1]);
foreach($checks as [$n,$ok]) echo ($ok?'PASS ':'FAIL ').$n."\n";
if($bad) exit(1);
