<?php
require_once __DIR__ . '/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__);
$root=dirname(__DIR__);$base=$root.'/modules/negotiation-master';$checks=[];
function c327(array &$checks,bool $ok,string $label):void{$checks[]=[$ok,$label];echo($ok?'PASS':'FAIL').": $label\n";}

$main=file_get_contents($root.'/ckm-quiz-pro.php');
$boot=file_get_contents($base.'/bootstrap.php');
$content=require $base.'/content/system-v1.php';
$page=file_get_contents($base.'/public/player-page.php');
$repo=file_get_contents($base.'/repositories.php');
$agreement=file_get_contents($base.'/domain/agreement-validator.php');
$rules=file_get_contents($base.'/domain/rule-engine.php');
$evaluation=file_get_contents($base.'/evaluation/evaluation-service.php');

c327($checks,ckm_test_current_plugin_release($main),'current plugin release declarations agree');
c327($checks,ckm_test_declared_version_at_least($boot,'CKM_NEG_DB_VERSION','1.4.0')&&ckm_test_declared_version_at_least($boot,'CKM_NEG_CONTENT_VERSION','1.2.0')&&preg_match("/CKM_NEG_CONTENT_VERSION', '([0-9.]+)'/",$boot,$contentPin)&&($content['pack_version']??'')===$contentPin[1],'schema retains MVP migration and declared content version matches pack');
c327($checks,is_array($content)&&version_compare((string)($content['pack_version']??'0'),'1.2.0','>='),'current content pack retains MVP version contract');
$scenarios=(array)($content['scenarios']??[]);
// Later releases added thematic and sales libraries. The original MVP contract
// describes the basic library, while every new scenario is audited below.
$basic=array_values(array_filter($scenarios,fn($s)=>($s['scenario']['library_slug']??'')==='basic'));
c327($checks,count($basic)===3&&count($scenarios)>=count($basic),'three original MVP scenarios remain in basic library');
$slugs=array_column(array_column($basic,'scenario'),'slug');
c327($checks,$slugs===['contract-supply','project-under-pressure','difficult-colleague'],'expected scenario slugs present in deterministic order');
$titles=array_column(array_column($basic,'scenario'),'title');
c327($checks,$titles===['Контракт на поставку','Проект под давлением','Трудный разговор с коллегой'],'expected scenario titles present');

$total=['items'=>0,'hidden_facts'=>0,'rules'=>0,'evaluation_rules'=>0];
foreach($scenarios as $entry){
    $slug=(string)$entry['scenario']['slug'];
    c327($checks,($entry['scenario']['status']??'')==='published'&&($entry['version']['status']??'')==='published',$slug.' is published');
    foreach($total as $kind=>$_){$rows=(array)($entry['components'][$kind]??[]);$total[$kind]+=count($rows);c327($checks,count($rows)===(int)($entry['expected'][$kind]??-1),$slug.' '.$kind.' manifest matches');}
    $weight=array_sum(array_map(fn($r)=>(float)($r['weight']??0),(array)$entry['components']['evaluation_rules']));
    c327($checks,abs($weight-100.0)<0.0001,$slug.' evaluation weight is 100');
    $codes=[];
    foreach((array)$entry['components']['items'] as $item){$codes[]=(string)$item['code'];}
    c327($checks,count($codes)===count(array_unique($codes)),$slug.' item codes unique');
}
$manifestTotals=[];$basicTotals=[];
foreach(array_keys($total) as $kind){$manifestTotals[$kind]=array_sum(array_map(fn($s)=>(int)($s['expected'][$kind]??-1),$scenarios));$basicTotals[$kind]=array_sum(array_map(fn($s)=>count($s['components'][$kind]??[]),$basic));}
c327($checks,$total===$manifestTotals&&$basicTotals===['items'=>15,'hidden_facts'=>12,'rules'=>18,'evaluation_rules'=>18],'all current content matches manifests and original MVP counts remain 15/12/18/18');

$project=$basic[1];
$projectTypes=array_column($project['components']['items'],'value_type','code');
c327($checks,($projectTypes['scope_level']??'')==='select'&&($projectTypes['followup_phase']??'')==='boolean','project scenario proves categorical and boolean items');
$projectRuleTypes=array_column($project['components']['rules'],'rule_type','code');
$projectActions=[];foreach($project['components']['rules'] as $r){$projectActions[$r['code']]=$r['action_json']['type']??'';}
c327($checks,($projectRuleTypes['P1']??'')==='hard_constraint'&&($projectActions['P1']??'')==='block','project scenario has data-defined hard package constraint');

$colleague=$basic[2];
$money=false;foreach($colleague['components']['items'] as $item){if(($item['unit']??'')==='RUB'||($item['value_type']??'')==='money')$money=true;}
c327($checks,!$money,'colleague scenario contains no money item');
c327($checks,count($colleague['components']['items'])===6&&count($colleague['components']['hidden_facts'])===4,'colleague scenario has six items and four hidden facts');

c327($checks,str_contains($repo,'firstPublishedSystem')&&str_contains($page,'return $library ? self::renderLibrary($library) : self::renderCatalog();')&&str_contains($page,'ProductCatalog::cards('),'catalog replaces automatic first-scenario launch and remains repository-driven');
c327($checks,!str_contains($page,"'contract-supply'")&&!str_contains($page,'"contract-supply"'),'player page has no hard-coded default scenario slug');
c327($checks,str_contains($agreement,"\$type==='block'")&&str_contains($agreement,"'hard_constraint'"),'agreement validator supports generic block rules');
c327($checks,str_contains($rules,"\$type==='block'")&&str_contains($rules,"hard_constraint_candidate"),'runtime rule engine recognizes generic block rules');
c327($checks,str_contains($evaluation,'$total>=80')&&str_contains($evaluation,'$total>=55'),'agreement result classification is score-driven and generic');
c327($checks,!str_contains($evaluation,"scores['economic_result']")&&!str_contains($evaluation,"scores['boundary_protection']"),'result classification no longer requires contract-specific criterion codes');

// Call the current player and repository lookup paths, rather than preserving
// a source-string assertion for the removed automatic first-scenario default.
define('ARRAY_A','ARRAY_A');
function get_current_user_id(){return 17;}
function ckmqp_scope_id(){return 43;}
function current_user_can($cap){return false;}
function ckmqp_tenant_is_member($tenant,$user){return $tenant===43&&$user===17;}
function absint($value){return abs((int)$value);}
function wp_unslash($value){return $value;}
final class LocatorFixture327 {
    public string $prefix='wp_';
    public array $queries=[];
    public array $rows=[
        701=>['id'=>701,'tenant_id'=>43,'slug'=>'fixture-shared','title'=>'Own fixture','status'=>'published','current_version_id'=>801],
        702=>['id'=>702,'tenant_id'=>null,'slug'=>'fixture-shared','title'=>'System fixture','status'=>'published','current_version_id'=>802],
        703=>['id'=>703,'tenant_id'=>44,'slug'=>'fixture-foreign','title'=>'Foreign fixture','status'=>'published','current_version_id'=>803],
        704=>['id'=>704,'tenant_id'=>43,'slug'=>'fixture-draft','title'=>'Draft fixture','status'=>'draft','current_version_id'=>804],
    ];
    public function prepare($sql,...$args){$index=0;return preg_replace_callback('/%[ds]/',function($m)use($args,&$index){$value=$args[$index++];return $m[0]==='%d'?(string)(int)$value:"'".str_replace("'","''",(string)$value)."'";},$sql);}
    public function get_row($sql,$mode){$this->queries[]=$sql;if(preg_match('/WHERE id=(\d+)/',$sql,$m))return $this->rows[(int)$m[1]]??null;if(preg_match("/WHERE tenant_id=(\\d+) AND slug='([^']+)'/",$sql,$m)){foreach($this->rows as $row)if($row['tenant_id']===(int)$m[1]&&$row['slug']===$m[2]&&$row['status']==='published')return $row;}return null;}
    public function get_results($sql,$mode){$this->queries[]=$sql;if(preg_match("/tenant_id IS NULL AND slug='([^']+)' AND status='published'/",$sql,$m))return array_values(array_filter($this->rows,fn($r)=>$r['tenant_id']===null&&$r['slug']===$m[1]&&$r['status']==='published'));if(str_contains($sql,'INNER JOIN')&&str_contains($sql,"s.tenant_id IS NULL AND s.status='published' AND v.status='published'"))return array_values(array_filter($this->rows,fn($r)=>$r['tenant_id']===null&&$r['status']==='published'));return [];}
    public function get_var($sql){$this->queries[]=$sql;return 0;}
}
$wpdb=new LocatorFixture327();
require_once $base.'/schema.php';require_once $base.'/security.php';require_once $base.'/repositories.php';require_once $base.'/public/product-catalog.php';require_once $base.'/public/player-page.php';
$lookup=new ReflectionMethod(CKM\NegotiationMaster\PlayerPage::class,'findScenario');
$previousGet=$_GET;
$_GET=[];$before=count($wpdb->queries);$found=$lookup->invoke(null);
c327($checks,$found===null&&count($wpdb->queries)===$before,'unselected current player opens catalog without querying an arbitrary scenario');
$_GET=['neg_scenario_id'=>701,'neg_scenario'=>'fixture-foreign'];
c327($checks,($lookup->invoke(null)['id']??0)===701,'current numeric scenario locator takes priority over legacy slug');
$_GET=['neg_scenario'=>'fixture-shared'];
c327($checks,($lookup->invoke(null)['id']??0)===701,'legacy slug resolves published scenario in current tenant before system template');
$_GET=['neg_scenario_id'=>704];$denied=false;try{$lookup->invoke(null);}catch(RuntimeException){$denied=true;}
c327($checks,$denied,'production player access denies an unpublished draft for an ordinary participant');
$_GET=['neg_scenario_id'=>703];$denied=false;try{$lookup->invoke(null);}catch(RuntimeException){$denied=true;}
c327($checks,$denied,'production player and access repository reject foreign tenant scenario');
$cards=CKM\NegotiationMaster\ProductCatalog::cards();
c327($checks,array_column($cards,'id')===[702],'production catalog selects published system scenarios instead of arbitrary default');
$_GET=$previousGet;

$runtimeFiles=[];
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS));
foreach($it as $file){
    $path=$file->getPathname();
    if(!$file->isFile()||!preg_match('/\.(php|js)$/',$path))continue;
    if(str_contains($path,DIRECTORY_SEPARATOR.'content'.DIRECTORY_SEPARATOR)||str_contains($path,DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR))continue;
    $runtimeFiles[]=$path;
}
$runtime='';foreach($runtimeFiles as $path)$runtime.=file_get_contents($path);
foreach(['contract-supply','project-under-pressure','difficult-colleague','Алексей Петров','Ирина Волкова','Антон Смирнов'] as $needle){
    c327($checks,!str_contains($runtime,$needle),'no scenario literal in runtime: '.$needle);
}

$failed=array_filter($checks,fn($x)=>!$x[0]);
echo count($checks)." checks, ".count($failed)." failed.\n";
exit($failed?1:0);
