<?php
namespace {
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
function is_user_logged_in(){return true;}
function current_user_can($cap){return $cap==='manage_options';}
function get_current_user_id(){return 77;}
function current_time($type,$gmt=false){return '2026-10-02 09:00:00';}
function wp_unslash($v){return $v;}
function sanitize_textarea_field($v){return trim((string)$v);}
function sanitize_text_field($v){return trim((string)$v);}
function sanitize_key($v){$v=strtolower((string)$v);return preg_replace('/[^a-z0-9_\-]/','',$v);}
function sanitize_title($v){$v=strtolower(trim((string)$v));$v=preg_replace('/[^a-z0-9]+/','-',$v);return trim($v,'-');}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}

class FakeWpdb {
    public array $tables=[]; public int $insert_id=0; private array $seq=[];
    function __construct(){foreach(['scenarios','scenario_versions','hidden_facts','items','rules','evaluation_rules'] as $t){$this->tables[$t]=[];$this->seq[$t]=0;}}
    function prepare($sql,...$args){$i=0;return preg_replace_callback('/%[ds]/',function($m)use(&$i,$args){$v=$args[$i++]??null;return $m[0]==='%d'?(string)(int)$v:"'".str_replace("'","''",(string)$v)."'";},$sql);}
    function insert($table,$data){$id=++$this->seq[$table];$data=['id'=>$id]+$data;$this->tables[$table][$id]=$data;$this->insert_id=$id;return 1;}
    function update($table,$data,$where){$n=0;foreach($this->tables[$table] as $id=>$row){$ok=true;foreach($where as $k=>$v){if((string)($row[$k]??null)!==(string)$v){$ok=false;break;}}if($ok){$this->tables[$table][$id]=array_merge($row,$data);$n++;}}return $n;}
    function query($sql){$s=trim($sql);if(in_array($s,['START TRANSACTION','COMMIT','ROLLBACK'],true))return 1;if(preg_match('/DELETE FROM `([^`]+)` WHERE scenario_version_id=(\d+)/',$s,$m)){[$all,$t,$vid]=$m;$n=0;foreach($this->tables[$t] as $id=>$r){if((int)$r['scenario_version_id']===(int)$vid){unset($this->tables[$t][$id]);$n++;}}return $n;}return 1;}
    private function filter($table, callable $fn){return array_values(array_filter(array_values($this->tables[$table]??[]),$fn));}
    function get_row($sql,$fmt=null){
        if(preg_match('/SELECT \* FROM `scenarios` WHERE id=(\d+)/',$sql,$m)) return $this->tables['scenarios'][(int)$m[1]]??null;
        if(preg_match("/SELECT \* FROM `scenario_versions` WHERE scenario_id=(\d+) AND status='draft'/",$sql,$m)){$rows=$this->filter('scenario_versions',fn($r)=>(int)$r['scenario_id']===(int)$m[1]&&$r['status']==='draft');usort($rows,fn($a,$b)=>[$b['version_number'],$b['id']]<=>[$a['version_number'],$a['id']]);return $rows[0]??null;}
        if(preg_match('/SELECT \* FROM `scenario_versions` WHERE id=(\d+) AND scenario_id=(\d+)/',$sql,$m)){ $r=$this->tables['scenario_versions'][(int)$m[1]]??null; return $r && (int)$r['scenario_id']===(int)$m[2] ? $r:null; }
        return null;
    }
    function get_results($sql,$fmt=null){if(preg_match('/SELECT \* FROM `([^`]+)` WHERE scenario_version_id=(\d+)/',$sql,$m)){return $this->filter($m[1],fn($r)=>(int)$r['scenario_version_id']===(int)$m[2]);}return [];}
    function get_var($sql){if(preg_match("/SELECT COUNT\(\*\) FROM `scenarios` WHERE tenant_id=(\d+) AND slug='([^']*)'/",$sql,$m)){return count($this->filter('scenarios',fn($r)=>(int)$r['tenant_id']===(int)$m[1]&&(string)$r['slug']===(string)$m[2]));}return 0;}
}
$GLOBALS['wpdb']=new FakeWpdb();
}

namespace CKM\NegotiationMaster {
final class Schema { public static function table(string $k): string { return $k; } }
final class Access {
    public static function context(): array { return ['tenant_id'=>5,'user_id'=>77,'participant_key'=>'user:77','admin'=>true]; }
    public static function version(int $id): array { global $wpdb; $r=$wpdb->tables['scenario_versions'][$id]??null; if(!$r) throw new \RuntimeException('version'); return $r; }
    public static function scenario(int $id): array { global $wpdb; $r=$wpdb->tables['scenarios'][$id]??null; if(!$r) throw new \RuntimeException('scenario'); return $r; }
}
final class AlternativeValueService { public static function model(array $a): ?array { $v=$a['valuation']??null; if(!is_array($v)||($v['model']??'')!=='player_achievement_score'||!is_numeric($v['score']??null))return null;$n=(float)$v['score'];return $n>=0&&$n<=100?['score'=>$n]:null; } }
final class SessionRepository {
    public static array $created=[];
    public function abandonBuilderTestsForScenario(int $scenarioId): void {}
    public function createBuilderTestRuntime(int $versionId,string $mode='training',bool $voiceEnabled=false,string $difficulty='medium'): int { $v=Access::version($versionId); if(!in_array($v['status'],['draft','published'],true))throw new \InvalidArgumentException('status'); self::$created[]=['version_id'=>$versionId,'status'=>$v['status'],'mode'=>$mode]; return 901; }
    public function initializeItems(int $sessionId,int $versionId): void {}
}
final class ProductCatalog { public static function url(array $args=[]): string { return '/negotiation-master/?neg_test_session='.(int)($args['neg_test_session']??0); } }
final class LibraryAccessService {}
final class ScenarioRepository {}
require dirname(__DIR__).'/modules/negotiation-master/application/scenario-builder-service.php';

$checks=0;$fails=0;
function e351($ok,$label){global $checks,$fails;$checks++;if(!$ok){$fails++;fwrite(STDERR,"FAIL: $label\n");}}
$svc=new ScenarioBuilderService();
$d=$svc->create('E2E поставка');
e351(($d['is_draft']??false)===true,'create -> draft');
$id=(int)$d['scenario']['id'];
$payload=[
 'title'=>'E2E поставка',
 'player_situation'=>'Покупатель и поставщик согласуют условия поставки.',
 'player_task'=>'Добиться приемлемых условий и оформить соглашение.',
 'player_known_facts_json'=>['budget'=>'100000'],
 'player_role'=>'Руководитель закупок',
 'player_ideal_result_json'=>['price'=>85000],
 'player_target_result_json'=>['price'=>['max'=>95000]],
 'player_alternative_json'=>['description'=>'Купить у резервного поставщика','valuation'=>['model'=>'player_achievement_score','score'=>62]],
 'player_red_lines_json'=>['price'=>['max'=>100000,'hard_boundary'=>true]],
 'opponent_name'=>'Сергей',
 'opponent_role'=>'Коммерческий директор поставщика',
 'opponent_persona_json'=>['style'=>'деловой'],
 'opponent_external_position_json'=>['price'=>110000],
 'opponent_hidden_interests_json'=>['Нужен быстрый платёж'],
 'opponent_constraints_json'=>['price'=>['min'=>90000]],
 'opponent_alternative_json'=>['description'=>'Продать другому клиенту'],
 'opponent_concession_space_json'=>['price'=>[90000,110000]],
 'opponent_walkaway_json'=>['after_repeated_boundary_violations'=>2],
 'mechanics_json'=>['allowed_modes'=>['training','exam'],'default_mode'=>'training','first_turn'=>'player','voice_input'=>true,'hard_timer'=>false,'coach_available_training'=>true,'coach_available_exam'=>false,'opponent_can_walkaway'=>true,'explicit_final_confirmation'=>true,'allow_finish_without_agreement'=>true],
 'hidden_facts'=>[['title'=>'Склад','content'=>'На складе избыток товара','importance'=>1,'initial_level'=>0,'reveal_rules_json'=>['auto'=>false]]],
 'items'=>[['title'=>'Цена','value_type'=>'money','unit'=>'₽','player_target_json'=>['max'=>95000],'player_boundary_json'=>['max'=>100000],'opponent_target_json'=>['min'=>105000],'opponent_boundary_json'=>['min'=>90000],'player_preference_direction'=>'lower_better','opponent_preference_direction'=>'higher_better','required_for_agreement'=>true,'reopen_policy'=>'with_reason','importance_weight'=>2,'config_json'=>['initial'=>110000,'step'=>5000]]],
 'rules'=>[['rule_type'=>'boundary','priority'=>100,'condition_json'=>['item'=>'item_1','op'=>'gt','value'=>100000],'action_json'=>['type'=>'red_line'],'is_active'=>true]],
 'evaluation_rules'=>[
   ['title'=>'Экономический результат','weight'=>60,'evaluation_type'=>'php','rubric_json'=>['min'=>0,'max'=>100],'config_json'=>[]],
   ['title'=>'Качество переговоров','weight'=>40,'evaluation_type'=>'ai','rubric_json'=>['min'=>0,'max'=>100],'config_json'=>['evidence_required'=>true]],
 ],
];
$saved=$svc->save($id,$payload);
e351(($saved['validation']['ok']??false)===true,'save -> valid draft');
e351(count($saved['components']['hidden_facts']??[])===1,'tab 1 situation + hidden fact saved');
e351(($saved['version']['player_role']??'')==='Руководитель закупок','tab 2 player saved');
e351(($saved['version']['opponent_name']??'')==='Сергей','tab 3 opponent saved');
e351(count($saved['components']['items']??[])===1,'tab 4 item saved');
e351(count($saved['components']['rules']??[])===1&&count($saved['components']['evaluation_rules']??[])===2,'tab 5 rules/evaluation saved');
$val=$svc->validate($id);e351(($val['ok']??false)===true&&abs(($val['evaluation_weight']??0)-100)<0.001,'validate -> publishable');
$pub=$svc->publish($id);$pubVid=(int)$pub['published_version_id'];
e351(($pub['scenario']['status']??'')==='published'&&($pub['is_draft']??true)===false,'publish -> current published');
$drafts=array_filter($GLOBALS['wpdb']->tables['scenario_versions'],fn($r)=>(int)$r['scenario_id']===$id&&$r['status']==='draft');
e351(count($drafts)===0,'publish leaves no redundant draft');
$test=$svc->testLaunch($id,'training');
e351((int)$test['scenario_version_id']===$pubVid,'test after publish uses published version');
e351((SessionRepository::$created[0]['status']??'')==='published','builder test accepts published own version');
$drafts=array_filter($GLOBALS['wpdb']->tables['scenario_versions'],fn($r)=>(int)$r['scenario_id']===$id&&$r['status']==='draft');
e351(count($drafts)===0,'test after publish does not create draft');
e351(str_contains((string)$test['url'],'neg_test_session=901'),'test URL generated');

$js=file_get_contents(dirname(__DIR__).'/modules/negotiation-master/assets/negotiation-builder.js');
$repo=file_get_contents(dirname(__DIR__).'/modules/negotiation-master/repositories.php');
$service=file_get_contents(dirname(__DIR__).'/modules/negotiation-master/application/scenario-builder-service.php');
e351(str_contains($js,"if(!dirty&&!current.is_draft)"),'frontend skips no-op save on published scenario');
e351(str_contains($js,"'Тестировать сценарий'"),'frontend labels published test correctly');
e351(str_contains($service,'$this->latestDraft($scenario) ?: $this->currentVersion($scenario)'),'service falls back to published version');
e351(str_contains($repo,"['draft','published']"),'repository permits draft/published builder test');

fwrite(STDOUT,sprintf("NEG-BUILDER-E2E-FIX: %d/%d PASS\n",$checks-$fails,$checks));
exit($fails?1:0);
}
