<?php
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);$checks=[];
function d335(&$c,$ok,$name){$c[]=[$ok,$name];echo($ok?'PASS ':'FAIL ').$name."\n";}
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$boot=file_get_contents($root.'/modules/negotiation-master/bootstrap.php');
$schema=json_decode(file_get_contents($root.'/modules/negotiation-master/schema.json'),true);
$policy=file_get_contents($root.'/modules/negotiation-master/domain/difficulty-policy.php');
$repo=file_get_contents($root.'/modules/negotiation-master/repositories.php');
$svc=file_get_contents($root.'/modules/negotiation-master/application/session-service.php');
$api=file_get_contents($root.'/modules/negotiation-master/api/session-controller.php');
$snap=file_get_contents($root.'/modules/negotiation-master/application/player-session-snapshot-builder.php');
$page=file_get_contents($root.'/modules/negotiation-master/public/player-page.php');
$js=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-session.js');
$css=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-session.css');
$ctx=file_get_contents($root.'/modules/negotiation-master/ai/opponent/opponent-context-builder.php');
$prompt=file_get_contents($root.'/modules/negotiation-master/ai/opponent/opponent-prompt-builder.php');
$assign=file_get_contents($root.'/modules/negotiation-master/application/assignment-service.php');
$assignPage=file_get_contents($root.'/modules/negotiation-master/public/assignment-page.php');
$assignJs=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-assignments.js');
$rules=file_get_contents($root.'/modules/negotiation-master/domain/rule-engine.php');
$calc=file_get_contents($root.'/modules/negotiation-master/evaluation/criterion-calculator.php');

d335($checks,ckm_test_current_plugin_release($plugin),'plugin build');
d335($checks,ckm_test_declared_version_at_least($boot,'CKM_NEG_DB_VERSION','1.8.0')&&ckm_test_declared_version_at_least($boot,'CKM_NEG_CONTENT_VERSION','1.4.0'),'DB bump only; content unchanged');
d335($checks,count($schema)===17,'no new NEG tables');
d335($checks,($schema['sessions']['columns']['difficulty']??'')==="varchar(32) NOT NULL DEFAULT 'medium'",'session difficulty persisted with safe default');
d335($checks,($schema['assignments']['columns']['difficulty']??'')==="varchar(32) NOT NULL DEFAULT 'medium'",'assignment difficulty persisted with safe default');
d335($checks,str_contains($boot,'domain/difficulty-policy.php'),'difficulty policy bootstrapped');
d335($checks,str_contains($policy,"['soft','medium','hard','expert']"),'four strategy levels');
d335($checks,str_contains($policy,'allowed_difficulties')&&str_contains($policy,'default_difficulty'),'scenario mechanics may constrain levels');
d335($checks,str_contains($policy,'жёсткость — это стратегия, а не грубость')&&str_contains($policy,'не становись искусственно несговорчивым'),'hard/expert remain professional and negotiable');
d335($checks,str_contains($svc,'DifficultyPolicy::assertAllowed')&&str_contains($svc,'createRuntime')&&str_contains($svc,'$difficulty'),'session start validates difficulty');
d335($checks,str_contains($api,"\$data['difficulty'] ?? 'medium'"),'REST start accepts difficulty');
d335($checks,str_contains($repo,"'difficulty' => \$difficulty")&&str_contains($repo,"'difficulty'=>\$difficulty"),'runtime rows pin difficulty');
d335($checks,str_contains($snap,"'difficulty' => DifficultyPolicy::normalize")&&str_contains($snap,"'difficulty_label' => DifficultyPolicy::label"),'player-safe snapshot exposes level, not policy secrets');
d335($checks,substr_count($page,'name="ckm-neg-difficulty"')>=1&&str_contains($page,'Уровень оппонента'),'prestart level selector');
d335($checks,str_contains($page,'DifficultyPolicy::allowedForVersion')&&str_contains($page,'DifficultyPolicy::defaultForVersion'),'prestart is scenario-data driven');
d335($checks,str_contains($js,'difficulty=(root.querySelector')&&str_contains($js,'mode,difficulty,voice_enabled'),'frontend sends selected level');
d335($checks,str_contains($js,'ckm-neg-difficulty-label'),'active game shows pinned level');
d335($checks,str_contains($css,'.ckm-neg-difficulty-grid')&&str_contains($css,'.ckm-neg-difficulty:has(input:checked)'),'difficulty cards styled');
d335($checks,str_contains($ctx,"'difficulty' => DifficultyPolicy::normalize"),'opponent context receives server-pinned level');
d335($checks,str_contains($prompt,'DifficultyPolicy::instruction')&&str_contains($prompt,'УРОВЕНЬ ОППОНЕНТА'),'opponent prompt applies strategy policy');
d335($checks,str_contains($prompt,'не меняет факты сценария, hard constraints, скрытые границы, правила соглашения или итоговую оценку'),'prompt explicitly preserves formal rules');
d335($checks,str_contains($assign,'DifficultyPolicy::assertAllowed')&&str_contains($assign,"'difficulty'=>\$difficulty"),'organizer assignment validates/pins level');
d335($checks,str_contains($assign,'createAssignedRuntime')&&str_contains($assign,"\$assignment['difficulty']"),'assigned session inherits forced level');
d335($checks,str_contains($assignPage,'id="na-difficulty"')&&str_contains($assignJs,"difficulty:$('na-difficulty').value"),'assignment UI selects level');
d335($checks,!str_contains($rules,'difficulty')&&!str_contains($calc,'difficulty'),'difficulty cannot alter formal rules or scoring');

// Executable policy smoke test without WordPress.
if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');
require_once $root.'/modules/negotiation-master/domain/difficulty-policy.php';
$cls='CKM\\NegotiationMaster\\DifficultyPolicy';
$version=['mechanics_json'=>json_encode(['allowed_difficulties'=>['soft','hard'],'default_difficulty'=>'hard'])];
d335($checks,$cls::allowedForVersion($version)===['soft','hard'],'mechanics allowed-level filter works');
d335($checks,$cls::defaultForVersion($version)==='hard','mechanics default level works');
d335($checks,$cls::label('expert')==='Эксперт','localized label works');
d335($checks,str_contains($cls::instruction('soft'),'МЯГКИЙ УРОВЕНЬ')&&str_contains($cls::instruction('expert'),'ЭКСПЕРТНЫЙ УРОВЕНЬ'),'level instructions differ');

if(!function_exists('wp_json_encode')){function wp_json_encode($v,$flags=0){return json_encode($v,$flags|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}}
require_once $root.'/modules/negotiation-master/ai/opponent/opponent-prompt-builder.php';
$pb='CKM\NegotiationMaster\OpponentPromptBuilder';
$base=['identity'=>['name'=>'Тест','role'=>'оппонент','persona'=>[]],'recent_dialogue'=>[],'validated_state'=>[]];
$soft=(new $pb())->messages($base+['difficulty'=>'soft'])[0]['content'];
$hard=(new $pb())->messages($base+['difficulty'=>'hard'])[0]['content'];
d335($checks,$soft!==$hard&&str_contains($soft,'МЯГКИЙ УРОВЕНЬ')&&str_contains($hard,'ЖЁСТКИЙ УРОВЕНЬ'),'actual opponent system prompts differ by level');
$failed=array_filter($checks,fn($x)=>!$x[0]);echo count($checks).' checks, '.count($failed)." failed\n";exit($failed?1:0);
