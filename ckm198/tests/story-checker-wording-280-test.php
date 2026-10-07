<?php
require_once __DIR__.'/support/plugin-release.php';
// This feature survives later releases; validate the current header/constant contract.
if (PHP_SAPI!=='cli') exit;
$src=file_get_contents(dirname(__DIR__).'/includes/negotiation-show-dialogue.php');
$main=file_get_contents(dirname(__DIR__).'/ckm-quiz-pro.php');
$n=0;
function t280($ok,$name){global $n;if(!$ok){fwrite(STDERR,"FAIL $name\n");exit(1);}echo 'PASS '.(++$n).' '.$name."\n";}
t280(ckm_test_current_plugin_release($main),'current plugin release header and constant are synchronized');
t280(str_contains($src,'$checkerCount=max(1,ckmqp_show_team_count($after)-1);'),'checker count derives from team count');
t280(str_contains($src,'История зафиксирована. Проверяющая команда, задайте два уточняющих вопроса.'),'two-team host message singular');
t280(str_contains($src,'История зафиксирована. Две проверяющие команды, задайте по два уточняющих вопроса.'),'three-team compatibility message retained');
t280(str_contains($src,'Ответы завершены. Проверяющая команда, проголосуйте:'),'two-team vote message singular');
t280(str_contains($src,'Проверь историю · проверяющая команда задаёт два уточняющих вопроса.'),'two-team status singular');
t280(str_contains($src,'Проверь историю · две проверяющие команды задают по два уточняющих вопроса.'),'three-team status retained');
echo "ALL $n PASS\n";
