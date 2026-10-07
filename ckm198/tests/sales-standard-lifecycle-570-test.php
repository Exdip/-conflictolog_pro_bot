<?php
/** Offline lifecycle regression: production services, fixtures only at WP/DB/API boundaries. */
namespace CKM\NegotiationMaster {
    final class Access {
        public static function context(): array {
            return ['tenant_id'=>$GLOBALS['tenant570'],'user_id'=>get_current_user_id(),
                'participant_key'=>'user:'.get_current_user_id(),'admin'=>true];
        }
    }
    final class Schema { public static function table(string $name): string { return 'fixture_'.$name; } }
    final class ResultBuilder {
        public static array $results=[];
        public function build(int $id): array { return self::$results[$id]??['ready'=>false]; }
    }
    final class AssignmentService {
        public static array $rows=[];
        public static int $nextId=1000;
        public static int $created=0;
        public static bool $manager=true;
        public static function canManage(): bool { return self::$manager; }
        public function listOwn(): array {
            return array_values(array_filter(self::$rows, static fn(array $r): bool =>
                $r['tenant_id']===$GLOBALS['tenant570'] && $r['created_by']===get_current_user_id()));
        }
        public function detail(int $id): array {
            $r=self::$rows[$id]??null;
            if(!$r || $r['tenant_id']!==$GLOBALS['tenant570'])throw new \RuntimeException('Fixture inaccessible');
            return $r;
        }
        public function create(array $data): array {
            $id=++self::$nextId;self::$created++;
            return self::$rows[$id]=['id'=>$id,'tenant_id'=>$GLOBALS['tenant570'],
                'created_by'=>get_current_user_id(),'status'=>'draft','participants'=>[],
                'created_at'=>'2026-10-07 12:00:00','assignment_url'=>'/fixture/'.$id]+$data;
        }
        public function addParticipants(int $id,array $participants): array {
            self::$rows[$id]['participants']=array_map(static fn(array $p): array =>
                ['user_id'=>(int)$p['identifier'],'completed'=>0,'active_session_id'=>0],$participants);
            return $this->detail($id);
        }
        public function setStatus(int $id,string $status): array {
            self::$rows[$id]['status']=$status;return $this->detail($id);
        }
    }
}
namespace CKM\EffectiveSales {
    final class SalesAiSellerWorkspaceService {
        public static array $dialogs=[];
        public static function recentDialogs(string $scriptId,int $limit): array {
            return array_slice(self::$dialogs[$GLOBALS['tenant570']][$scriptId]??[],0,$limit);
        }
        public static function findDialog(string $scriptId,string $sessionId): ?array {
            foreach(self::recentDialogs($scriptId,100) as $row)if($row['session_id']===$sessionId)return $row;
            return null;
        }
    }
}
namespace {
    if(PHP_SAPI!=='cli')exit(1);
    define('ABSPATH',__DIR__);
    define('ARRAY_A','ARRAY_A');
    $tenant570=11;$user570=19;$meta570=[];$checks570=0;
    function get_current_user_id(): int { return $GLOBALS['user570']; }
    function ckmqp_scope_id(): string { return 'tenant:'.$GLOBALS['tenant570']; }
    function get_user_meta(int $user,string $key,bool $single=true): mixed { return $GLOBALS['meta570'][$user][$key]??[]; }
    function update_user_meta(int $user,string $key,mixed $value): bool { $GLOBALS['meta570'][$user][$key]=$value;return true; }
    function current_time(string $format,bool $gmt=false): string { return '2026-10-07 11:00:00'; }
    function wp_strip_all_tags(string $value): string { return strip_tags($value); }
    function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_\-]/','',strtolower($value)); }
    function wp_json_encode(mixed $value,int $flags=0): string { return json_encode($value,$flags|JSON_THROW_ON_ERROR); }
    function wp_generate_uuid4(): string { static $i=0;return '00000000-0000-4000-8000-'.str_pad((string)++$i,12,'0',STR_PAD_LEFT); }
    function get_userdata(int $id): object|false {
        return $id===23 ? (object)['ID'=>23,'display_name'=>'Сотрудник','user_login'=>'employee'] : false;
    }
    function check570(bool $ok,string $name): void {
        if(!$ok)throw new \RuntimeException('FAIL: '.$name);
        $GLOBALS['checks570']++;echo 'PASS: '.$name."\n";
    }
    function rejects570(callable $call,string $name): void {
        try{$call();}catch(\InvalidArgumentException|\RuntimeException){check570(true,$name);return;}
        check570(false,$name);
    }
    final class FixtureDatabase570 {
        public int $insert_id=0;
        public array $queries=[];
        public array $writes=[];
        public array $attempts=[];
        public function prepare(string $sql,mixed ...$args): string {
            if(count($args)===1 && is_array($args[0]))$args=$args[0];
            $this->queries[]=['sql'=>$sql,'args'=>$args];return $sql;
        }
        public function get_results(string $sql,mixed $format=null): array {
            $query=end($this->queries);
            if(str_contains($sql,'evaluation_status')){
                if(!str_contains($sql,'se.tenant_id=%d') || !str_contains($sql,'se.participant_key=%s'))
                    throw new \RuntimeException('Missing tenant/participant query scope');
                if($query['args'][0]!==$GLOBALS['tenant570'] || $query['args'][1]!=='user:'.get_current_user_id())
                    throw new \RuntimeException('Wrong tenant/participant query arguments');
                return $this->attempts[(int)$query['args'][2]]??[];
            }
            throw new \RuntimeException('Unexpected fixture query');
        }
        public function get_var(string $sql): int { return 0; }
        public function query(string $sql): int { return 1; }
        public function insert(string $table,array $data): int {
            $this->insert_id++;$this->writes[]=['table'=>$table,'data'=>$data,'id'=>$this->insert_id];return 1;
        }
        public function update(string $table,array $data,array $where): int {
            $this->writes[]=['table'=>$table,'data'=>$data,'where'=>$where];return 1;
        }
    }
    $wpdb=new FixtureDatabase570();
    $root=dirname(__DIR__);
    foreach(['sales-script-service','sales-script-client-service','sales-development-center-service',
        'sales-competition-service','sales-team-development-service','sales-adaptive-polygon-service',
        'sales-practice-feedback-service','sales-standard-recertification-service',
        'sales-standard-impact-service','sales-standard-revision-decision-service'] as $service)
        require $root.'/modules/effective-sales/application/'.$service.'.php';

    use CKM\EffectiveSales\SalesAdaptivePolygonService as Adaptive;
    use CKM\EffectiveSales\SalesStandardImpactService as Impact;
    use CKM\EffectiveSales\SalesStandardRevisionDecisionService as Decisions;
    use CKM\EffectiveSales\SalesScriptService as Scripts;
    use CKM\EffectiveSales\SalesScriptClientService as Client;
    use CKM\EffectiveSales\SalesPracticeFeedbackService as Practice;
    use CKM\EffectiveSales\SalesTeamDevelopmentService as Team;
    use CKM\EffectiveSales\SalesStandardRecertificationService as Recert;
    use CKM\EffectiveSales\SalesAiSellerWorkspaceService as Workspace;
    use CKM\NegotiationMaster\AssignmentService as Assignments;
    use CKM\NegotiationMaster\ResultBuilder as Results;

    function script570(array $extra=[]): array {
        return $extra+['id'=>'methodology','title'=>'Методика','status'=>'approved','product'=>'Сервис',
            'client'=>'Представитель организации','goal'=>'Согласовать проверку','constraints'=>'Без давления',
            'situation_class'=>'Диагностика','ai_client_scenario_id'=>900,'adaptive_cases'=>[],
            'practice_standard_revision'=>1,'practice_methodology_notes'=>[
                ['focus_code'=>'customer_understanding','status'=>'active']],
            'practice_standard_events'=>[['revision'=>1,'focus_code'=>'customer_understanding',
                'action'=>'activated','at'=>'2026-10-07 10:00:00']]];
    }
    function save570(array $script): void {
        $GLOBALS['meta570'][get_current_user_id()]['ckm_sales_scripts_v1'][ckmqp_scope_id()]=[$script];
    }
    function history570(string $id,string $focus,float $score,float $total=85,bool $transfer=false): array {
        $criteria=[];
        foreach(array_keys(Adaptive::focusCatalog()) as $code)
            $criteria[]=['code'=>$code,'title'=>$code,'raw_score'=>$code===$focus?$score:95];
        return ['case_id'=>$id,'focus_code'=>$focus,'transfer'=>$transfer,
            'result'=>['criteria'=>$criteria,'final_score'=>$total,'completed_at'=>'2026-10-07 09:00:00']];
    }
    function employee570(int $id,?float $before,?float $after): array {
        return ['participant_key'=>'user:'.$id,
            'before'=>$before===null?null:['criterion_score'=>$before,'criterion_code'=>'next_step'],
            'after'=>$after===null?null:['criterion_score'=>$after,'criterion_code'=>'next_step']];
    }
    function assignment570(int $id,string $kind,string $mode,int $scenario=901,string $status='active',array $extra=[]): array {
        return $extra+['id'=>$id,'tenant_id'=>$GLOBALS['tenant570'],'created_by'=>get_current_user_id(),
            'status'=>$status,'scenario_id'=>$scenario,'assignment_mode'=>$kind,'mode'=>$mode,'title'=>'Fixture',
            'created_at'=>'2026-10-07 12:00:00','assignment_url'=>'/fixture/'.$id,
            'participants'=>[['user_id'=>23,'completed'=>0,'active_session_id'=>0]]];
    }

    check570(PHP_MAJOR_VERSION===8 && PHP_MINOR_VERSION===3,'regression executes in PHP8.3');
    $main=file_get_contents($root.'/ckm-quiz-pro.php');
    require_once $root.'/tests/support/plugin-release.php';
    check570(ckm_test_current_plugin_release($main),'plugin header and constant synchronized for current release');
    check570(count(Adaptive::focusCatalog())===5 && count(Client::evaluationRules())===5,'five criteria preserved');
    $r=Adaptive::recommendationFromHistory(script570(),[history570('base','question_quality',74)]);
    check570(!$r['transfer'] && empty($r['mastered']) && $r['focus_code']==='question_quality','score below75 continues at weakest focus');
    $r=Adaptive::recommendationFromHistory(script570(),[history570('new','next_step',60),history570('old','question_quality',65)]);
    check570($r['focus_code']==='next_step' && $r['level']===2,'new weakest criterion changes focus');
    $r=Adaptive::recommendationFromHistory(script570(),[history570('again','next_step',65),history570('previous','next_step',60)]);
    check570($r['focus_code']==='next_step' && $r['level']===3,'repeated weakness increases level');
    $history=[history570('a','next_step',75,75),history570('b','next_step',80),history570('c','next_step',85)];
    $r=Adaptive::recommendationFromHistory(script570(),$history);
    check570($r['transfer'] && $r['level']===4,'three adaptive completions and75 threshold permit transfer');
    $history[0]=history570('a','next_step',74,90);
    check570(!Adaptive::recommendationFromHistory(script570(),$history)['transfer'],'weakest74 blocks transfer despite high final score');
    $history[0]=history570('a','next_step',85,74);
    check570(!Adaptive::recommendationFromHistory(script570(),$history)['transfer'],'final74 blocks transfer');
    $r=Adaptive::recommendationFromHistory(script570(),[history570('transfer','next_step',75,75,true)]);
    check570(!empty($r['mastered']),'completed transfer75 confirms mastery');
    check570(!Adaptive::isProgressionCase(['adaptive_schema'=>3,'recertification_revision'=>1]),'recertification excluded from adaptive progression');
    check570(Adaptive::isProgressionCase(['adaptive_schema'=>3]),'ordinary adaptive case retained');

    $scenarios=[
        ['single improved',[employee570(23,50,80)],'preliminary_positive',false,false],
        ['two improved passed',[employee570(23,50,80),employee570(24,60,85)],'confirmed',true,false],
        ['mixed below threshold',[employee570(23,50,80),employee570(24,55,65)],'mixed',false,true],
        ['mixed individual regression',[employee570(23,20,90),employee570(24,95,80)],'mixed',false,true],
        ['stagnant employee',[employee570(23,50,80),employee570(24,80,80)],'mixed',false,true],
        ['no growth',[employee570(23,80,80),employee570(24,90,85)],'not_confirmed',false,true],
        ['single no effect',[employee570(23,85,80)],'preliminary_no_effect',false,true],
        ['single improved below75',[employee570(23,40,65)],'preliminary_mixed',false,true],
        ['no completed exam',[employee570(23,50,null)],'awaiting',false,false],
        ['no baseline',[employee570(23,null,90)],'insufficient',false,false],
    ];
    foreach($scenarios as [$name,$employees,$code,$confirm,$rework]){
        $impact=Impact::summarize($employees);$permissions=Decisions::permissions($impact);
        check570($impact['conclusion']['code']===$code,$name.' conclusion');
        check570($permissions['confirm']===$confirm && $permissions['rework']===$rework,$name.' permissions');
    }
    $impact=Impact::summarize([employee570(23,80,80),employee570(24,null,100)]);
    check570($impact['average_before']===80.0 && $impact['average_after']===80.0 && $impact['average_delta']===0.0,'unpaired high result cannot invent growth');
    check570(!Decisions::permissions($impact)['confirm'],'unpaired post result cannot confirm');
    $impact=Impact::summarize([employee570(23,60,80),employee570(23,60,80)]);
    check570($impact['comparable_count']===1 && !Decisions::permissions($impact)['confirm'],'duplicate employee cannot satisfy two-person minimum');
    $mismatch=employee570(23,40,95);$mismatch['after']['criterion_code']='value_proposition';
    $impact=Impact::summarize([$mismatch]);
    check570($impact['comparable_count']===0 && !Decisions::permissions($impact)['confirm'],'different criterion is not comparable');
    check570(!Decisions::permissions(Impact::summarize([employee570(23,50,80),employee570(24,60,85)]),['revision'=>1])['confirm'],'recorded decision blocks another command');

    $base=script570();$snapshot=['focus_title'=>'Понимание клиента'];
    $confirmed=Decisions::applyDecision($base,'confirm',1,'customer_understanding',$snapshot,'2026-10-07 12:00:00',19);
    check570(count(Practice::activeRules($confirmed['script']))===1,'confirm keeps active standard rule');
    $again=Decisions::applyDecision($confirmed['script'],'confirm',1,'customer_understanding',$snapshot,'later',19);
    check570($again['reused'] && $again['script']===$confirmed['script'],'repeat confirm idempotent');
    $rework=Decisions::applyDecision($base,'rework',1,'customer_understanding',$snapshot,'2026-10-07 12:00:00',19);
    check570(Practice::activeRules($rework['script'])===[] && $rework['script']['practice_standard_revision']===2,'rework removes rule and creates next revision');
    $again=Decisions::applyDecision($rework['script'],'rework',1,'customer_understanding',$snapshot,'later',19);
    check570($again['reused'] && $again['script']===$rework['script'],'repeat rework cannot duplicate history or revision');
    rejects570(fn()=>Decisions::applyDecision($base,'confirm',2,'customer_understanding',$snapshot,'later',19),'stale revision mutation rejected');

    $cases=[['id'=>'training','scenario_id'=>901,'focus_code'=>'customer_understanding','level'=>1,'adaptive_schema'=>3],
        ['id'=>'recert','scenario_id'=>902,'focus_code'=>'customer_understanding','level'=>1,'adaptive_schema'=>3,'recertification_revision'=>1]];
    save570(script570(['adaptive_cases'=>$cases]));
    foreach(['assignTraining','assignScenarioTraining'] as $method){
        foreach([['individual','exam'],['team_shared','training'],['team_shared','exam']] as [$kind,$mode]){
            Assignments::$rows=[10=>assignment570(10,$kind,$mode)];
            $result=$method==='assignTraining'?Team::assignTraining('user:23','customer_understanding',40,'methodology'):
                Team::assignScenarioTraining('user:23',901);
            check570(!$result['reused'] && $result['assignment']['assignment_mode']==='individual'
                && $result['assignment']['mode']==='training' && $result['assignment']['scenario_id']===901,
                $method.' ignores '.$kind.'/'.$mode.' and selects non-recert training');
        }
        Assignments::$rows=[11=>assignment570(11,'individual','training')];
        $result=$method==='assignTraining'?Team::assignTraining('user:23','customer_understanding',40,'methodology'):
            Team::assignScenarioTraining('user:23',901);
        check570($result['reused'] && $result['assignment']['id']===11,$method.' reuses valid individual training');
    }
    Assignments::$rows=[];
    save570(script570(['adaptive_cases'=>[$cases[1]]]));
    $result=Team::assignTraining('user:23','customer_understanding',40,'methodology');
    check570($result['assignment']['scenario_id']!==902,'recert-only catalog generates separate training');

    save570(script570(['adaptive_cases'=>[$cases[1]]]));
    Assignments::$rows=[20=>assignment570(20,'team_shared','exam',902)];
    $r=Recert::assign('methodology','user:23');
    check570(!$r['reused'] && $r['assignment']['assignment_mode']==='individual' && $r['assignment']['mode']==='exam','revision exam cannot reuse team competition');
    $r=Recert::assign('methodology','user:23');
    check570($r['reused'],'repeat revision exam reuses active assignment');
    Assignments::$rows=[21=>assignment570(21,'individual','exam',902,'closed')];
    check570(!Recert::assign('methodology','user:23')['reused'],'closed unfinished exam permits fresh assignment');
    Assignments::$rows=[22=>assignment570(22,'individual','exam',902,'closed',
        ['participants'=>[['user_id'=>23,'completed'=>1,'active_session_id'=>0]]])];
    check570(Recert::assign('methodology','user:23')['reused'],'completed closed exam remains idempotent');
    $title='Повторная проверка · стандарт v1 · Понимание клиента — Сотрудник';
    Assignments::$rows=[23=>assignment570(23,'individual','exam',902,'draft',['title'=>$title,'participants'=>[]])];
    $created=Assignments::$created;$r=Recert::assign('methodology','user:23');
    check570($r['reused'] && $r['assignment']['id']===23 && $r['assignment']['status']==='active'
        && Assignments::$created===$created,'interrupted draft creation resumes without duplicate');
    $writes=count($wpdb->writes);
    rejects570(fn()=>Recert::assign('methodology','user:9999'),'missing employee rejected');
    check570(count($wpdb->writes)===$writes,'missing employee creates no scenario');

    save570(script570(['adaptive_cases'=>$cases]));
    $wpdb->queries=[];$wpdb->attempts=[901=>[['id'=>81,'mode'=>'training','status'=>'completed_no_agreement_player','evaluation_status'=>'completed']]];
    Results::$results=[81=>['ready'=>true]+history570('training','customer_understanding',65)['result']];
    $history=Adaptive::history(Scripts::find('methodology'));
    check570(count($history)===1 && $history[0]['case_id']==='training','assigned completed result reaches adaptive history');
    check570(count(array_filter($wpdb->queries,static fn(array $q): bool => ($q['args'][2]??null)===902))===0,'recert scenario never queried for progression');
    $query=end($wpdb->queries);
    check570(str_contains($query['sql'],"a.assignment_mode='individual'") && str_contains($query['sql'],"se.session_kind='player'"),'progression query excludes team and builder attempts');
    $tenant570=22;$wpdb->queries=[];Adaptive::history(script570(['adaptive_cases'=>[$cases[0]]]));
    check570($wpdb->queries[0]['args'][0]===22,'progression follows current tenant');
    $tenant570=11;

    save570(script570());
    $private='Анна Клиентова +7 (999) 123-45-67 anna@example.invalid @anna_contact https://example.invalid/private';
    Workspace::$dialogs[11]['methodology']=[
        ['session_id'=>'private-source-a','goal_status'=>'unsuccessful','channel'=>'web','last_at'=>'2026-10-07 08:00:00',
            'messages'=>[['role'=>'user','content'=>$private.' Дорого, рискованно, уже пробовали.']]],
        ['session_id'=>'private-source-b','goal_status'=>'stalled','channel'=>'web','last_at'=>'2026-10-07 08:30:00',
            'messages'=>[['role'=>'user','content'=>$private.' Дорого, рискованно, уже пробовали.']]],
    ];
    $wpdb->writes=[];Assignments::$rows=[];
    $created=Practice::createAndAssign('methodology','private-source-a','user:23');
    $case=$created['case'];$json=json_encode($wpdb->writes,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    foreach(['Анна','+7 (999)','anna@example.invalid','@anna_contact','https://example.invalid/private','private-source-a',$private] as $value)
        check570(!str_contains($json,$value),'generated scenario omits private value '.$value);
    check570($case['practice_source_session_id']==='private-source-a' && !isset($case['practice_excerpt']),'source link only in manager case metadata');
    check570($created['assignment']['assignment']['mode']==='training'
        && $created['assignment']['assignment']['assignment_mode']==='individual','practice case creates individual training');
    check570(Practice::createCase('methodology','private-source-a')['reused'],'practice case creation idempotent');
    $wpdb->writes=[];
    $guarded=Adaptive::createCaseForFocus('methodology','objection_handling',1,
        ['practice_source_session_id'=>'private-source-b','opening'=>$private,'practice_excerpt'=>$private]);
    check570(!str_contains(json_encode($wpdb->writes,JSON_UNESCAPED_UNICODE),$private)
        && !isset($guarded['practice_excerpt']) && $guarded['opening']!==$private,'practice context cannot inject source opening/excerpt');
    $insights=Practice::insights('methodology');$focus=$insights[0]['focus_code'];
    check570($insights[0]['repeated'] && $insights[0]['count']===2,'two practice signals create methodology candidate');
    $adopt=Practice::adoptInsight('methodology',$focus);
    check570(count($adopt['script']['practice_methodology_notes'])===2,'recurrent signal adopted as fixed methodology rule');
    $activation=Practice::setRuleActive('methodology',$focus,true);
    check570($activation['changed'] && $activation['revision']===2,'rule activation creates next standard revision');
    $again=Practice::setRuleActive('methodology',$focus,true);
    check570(!$again['changed'] && $again['revision']===2,'repeat activation does not duplicate revision');

    $tenant570=22;
    check570(Scripts::find('methodology')===null && Practice::candidates('methodology')===[],'tenant cannot read another tenant scripts/practice');
    rejects570(fn()=>Scripts::mutate('methodology',static fn(array $s): array=>$s),'tenant cannot mutate another tenant script');
    rejects570(fn()=>Practice::createCase('methodology','private-source-a'),'tenant cannot create case from foreign dialogue');
    save570(script570(['title'=>'Другая площадка']));
    $tenant570=11;
    check570(Scripts::find('methodology')['title']==='Методика','tenant write leaves original scope unchanged');
    $user570=24;
    check570(Scripts::find('methodology')===null,'another owner cannot read methodology');
    $user570=19;Assignments::$manager=false;
    rejects570(fn()=>Team::assignTraining('user:23','customer_understanding',40,'methodology'),'non-manager cannot assign training');
    rejects570(fn()=>Practice::createCase('methodology','private-source-a'),'non-manager cannot access practice-case command');
    Assignments::$manager=true;
    echo $checks570." checks passed. No WordPress UI, external services, LLM or live games used.\n";
}
