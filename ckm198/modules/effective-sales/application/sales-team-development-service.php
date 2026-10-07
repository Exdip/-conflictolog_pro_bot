<?php
namespace CKM\EffectiveSales;
if (!defined('ABSPATH')) { exit; }

final class SalesTeamDevelopmentService {
    public const PASS_SCORE=75;
    private const SESSION_LIMIT=500;

    public static function canManage(): bool {
        return SalesCompetitionService::canManage();
    }

    private static function userIdFromKey(string $key): int {
        return preg_match('/^user:(\d+)$/D',$key,$m)?(int)$m[1]:0;
    }

    private static function scenarioMap(): array {
        $out=[];
        foreach(SalesScriptService::all() as $script){
            if(!is_array($script))continue;
            $scriptId=(string)($script['id']??'');
            $scriptTitle=(string)($script['title']??'Методика продаж');
            $base=(int)($script['ai_client_scenario_id']??0);
            if($base>0)$out[$base]=[
                'script_id'=>$scriptId,'script_title'=>$scriptTitle,'focus_code'=>'','focus_title'=>'Базовая ситуация',
                'level'=>0,'transfer'=>false,
            ];
            foreach((array)($script['adaptive_cases']??[]) as $case){
                if(!is_array($case))continue;
                $sid=(int)($case['scenario_id']??0);if($sid<1)continue;
                $out[$sid]=[
                    'script_id'=>$scriptId,'script_title'=>$scriptTitle,
                    'focus_code'=>(string)($case['focus_code']??''),
                    'focus_title'=>(string)($case['focus_title']??'Адаптивный кейс'),
                    'level'=>(int)($case['level']??1),'transfer'=>!empty($case['transfer']),
                    'adaptive_schema'=>(int)($case['adaptive_schema']??1),
                    'recertification_revision'=>(int)($case['recertification_revision']??0),
                ];
            }
        }
        return $out;
    }

    private static function participantLabel(string $key,int $assignmentId=0): string {
        if(preg_match('/^user:(\d+)$/D',$key,$m)){
            $u=get_userdata((int)$m[1]);
            if($u){
                $name=trim((string)$u->display_name);
                if($name==='')$name=trim((string)$u->user_login);
                if($name!=='')return $name;
            }
            return 'Сотрудник #'.(int)$m[1];
        }
        if(str_starts_with($key,'team:')||str_starts_with($key,'team-')){
            return $assignmentId>0?'Команда · задание #'.$assignmentId:'Команда';
        }
        return $key!==''?'Участник':'Участник без имени';
    }

    private static function criteriaCatalog(): array {
        $out=[];
        foreach(SalesDevelopmentCenterService::criteria() as $row){
            if(!is_array($row))continue;$code=(string)($row['code']??'');if($code==='')continue;
            $out[$code]=['code'=>$code,'title'=>(string)($row['title']??$code),'weight'=>(float)($row['weight']??0)];
        }
        return $out;
    }

    private static function rawRows(array $scenarioMap): array {
        if(!$scenarioMap)return [];
        global $wpdb;
        $ctx=\CKM\NegotiationMaster\Access::context();$tenant=(int)$ctx['tenant_id'];
        $sessionsT=\CKM\NegotiationMaster\Schema::table('sessions');
        $evaluationsT=\CKM\NegotiationMaster\Schema::table('evaluations');
        $scoresT=\CKM\NegotiationMaster\Schema::table('evaluation_scores');
        $rulesT=\CKM\NegotiationMaster\Schema::table('evaluation_rules');

        $scenarioIds=array_values(array_map('intval',array_keys($scenarioMap)));
        $scenarioPh=implode(',',array_fill(0,count($scenarioIds),'%d'));
        $sessionArgs=array_merge([$tenant],$scenarioIds,[self::SESSION_LIMIT]);
        $sessions=(array)$wpdb->get_results($wpdb->prepare(
            "SELECT se.id,se.participant_key,se.assignment_id,se.session_kind,se.mode,se.scenario_id,se.started_at,se.completed_at
             FROM `$sessionsT` se
             WHERE se.tenant_id=%d AND se.scenario_id IN ($scenarioPh)
               AND se.session_kind IN ('player','assignment') AND se.status LIKE 'completed_%%'
             ORDER BY se.id DESC LIMIT %d",
            $sessionArgs
        ),ARRAY_A);
        if(!$sessions)return [];

        $sessionIds=array_values(array_map(static fn(array $r): int=>(int)$r['id'],$sessions));
        $sessionPh=implode(',',array_fill(0,count($sessionIds),'%d'));
        $evaluations=(array)$wpdb->get_results($wpdb->prepare(
            "SELECT ev.session_id,ev.final_score,ev.result_type,ev.completed_at AS evaluation_completed_at,
                    es.raw_score,er.code,er.title,er.sort_order
             FROM `$evaluationsT` ev
             INNER JOIN `$scoresT` es ON es.evaluation_id=ev.id
             INNER JOIN `$rulesT` er ON er.id=es.evaluation_rule_id
             WHERE ev.session_id IN ($sessionPh) AND ev.status='completed'
             ORDER BY ev.session_id DESC,er.sort_order ASC,er.id ASC",
            $sessionIds
        ),ARRAY_A);
        $bySession=[];
        foreach($evaluations as $row){
            $sid=(int)($row['session_id']??0);if($sid<1)continue;
            if(!isset($bySession[$sid]))$bySession[$sid]=[
                'final_score'=>(float)($row['final_score']??0),'result_type'=>(string)($row['result_type']??''),
                'evaluation_completed_at'=>(string)($row['evaluation_completed_at']??''),'criteria'=>[]
            ];
            $code=(string)($row['code']??'');if($code==='')continue;
            $bySession[$sid]['criteria'][$code]=[
                'code'=>$code,'title'=>(string)($row['title']??$code),'raw_score'=>(float)($row['raw_score']??0),
                'sort_order'=>(int)($row['sort_order']??0),
            ];
        }

        $out=[];
        foreach($sessions as $se){
            $sid=(int)$se['id'];if(!isset($bySession[$sid]))continue;
            $meta=$scenarioMap[(int)$se['scenario_id']]??[];
            $out[]=[
                'session_id'=>$sid,'participant_key'=>(string)($se['participant_key']??''),
                'participant_label'=>self::participantLabel((string)($se['participant_key']??''),(int)($se['assignment_id']??0)),
                'assignment_id'=>(int)($se['assignment_id']??0),'session_kind'=>(string)($se['session_kind']??'player'),
                'mode'=>(string)($se['mode']??'training'),'scenario_id'=>(int)$se['scenario_id'],
                'completed_at'=>(string)($se['completed_at']??$bySession[$sid]['evaluation_completed_at']??''),
                'final_score'=>(float)$bySession[$sid]['final_score'],'result_type'=>(string)$bySession[$sid]['result_type'],
                'criteria'=>(array)$bySession[$sid]['criteria'],
            ]+$meta;
        }
        usort($out,static fn(array $a,array $b): int=>((int)$b['session_id'])<=>((int)$a['session_id']));
        return $out;
    }

    private static function weakest(array $criteria,array $catalog): ?array {
        $weak=null;
        foreach($catalog as $code=>$def){
            if(!isset($criteria[$code]))continue;
            $score=(float)($criteria[$code]['raw_score']??0);
            if($weak===null||$score<(float)$weak['score'])$weak=['code'=>$code,'title'=>(string)$def['title'],'score'=>$score];
        }
        return $weak;
    }

    private static function recommendation(?array $weak): string {
        if(!$weak)return 'Нужна ещё одна завершённая оценка.';
        $score=(float)$weak['score'];$title=(string)$weak['title'];
        if($score<50)return 'Приоритетная тренировка: '.$title.'. Начать с адаптивного кейса этого навыка.';
        if($score<75)return 'Закрепить: '.$title.'. Дать вариативный кейс и повторить до устойчивых 75+.';
        return 'Уровень устойчивый. Следующий шаг — контроль переноса без подсказок.';
    }

    private static function trainingTarget(string $scriptId,string $focus,float $score): array {
        $desired=$score<50?1:2;$map=self::scenarioMap();$best=null;
        foreach($map as $scenarioId=>$meta){
            if((string)($meta['script_id']??'')!==$scriptId)continue;
            if((int)($meta['adaptive_schema']??1)<3)continue;
            if((int)($meta['recertification_revision']??0)>0)continue;
            if((string)($meta['focus_code']??'')!==$focus||!empty($meta['transfer']))continue;
            $level=max(1,(int)($meta['level']??1));
            if($level!==$desired)continue;
            $best=['scenario_id'=>(int)$scenarioId]+$meta;
        }
        if($best)return $best;
        if($scriptId===''){
            foreach(SalesScriptService::all() as $script){
                if(!is_array($script)||(string)($script['status']??'draft')!=='approved'||(int)($script['ai_client_scenario_id']??0)<1)continue;
                $scriptId=(string)$script['id'];break;
            }
        }
        if($scriptId==='')throw new \RuntimeException('Нет утверждённой методики, из которой можно назначить тренировку.');
        $case=SalesAdaptivePolygonService::createCaseForFocus($scriptId,$focus,$desired);
        return [
            'scenario_id'=>(int)($case['scenario_id']??0),'script_id'=>$scriptId,
            'focus_code'=>$focus,'focus_title'=>(string)($case['focus_title']??$focus),'level'=>(int)($case['level']??$desired),'transfer'=>false,
        ];
    }

    private static function assignmentStates(array $scenarioMap): array {
        $out=[];$svc=new \CKM\NegotiationMaster\AssignmentService();
        try{$rows=$svc->listOwn();}catch(\Throwable){return [];}
        foreach($rows as $row){
            if(!is_array($row)||(int)($row['scenario_id']??0)<1)continue;
            $sid=(int)$row['scenario_id'];if(!isset($scenarioMap[$sid]))continue;
            try{$detail=$svc->detail((int)$row['id']);}catch(\Throwable){continue;}
            foreach((array)($detail['participants']??[]) as $p){
                if(!is_array($p)||(int)($p['user_id']??0)<1)continue;
                $key='user:'.(int)$p['user_id'];
                $status='Назначено';
                if((int)($p['active_session_id']??0)>0)$status='В процессе';
                elseif((int)($p['completed']??0)>0)$status='Выполнено';
                elseif((string)($detail['status']??'')==='closed')$status='Закрыто';
                elseif((string)($detail['status']??'')==='draft')$status='Черновик';
                $candidate=[
                    'assignment_id'=>(int)$detail['id'],'assignment_url'=>(string)($detail['assignment_url']??''),
                    'status'=>$status,'scenario_id'=>$sid,'focus_code'=>(string)($scenarioMap[$sid]['focus_code']??''),
                    'focus_title'=>(string)($scenarioMap[$sid]['focus_title']??'Тренировка'),
                    'level'=>(int)($scenarioMap[$sid]['level']??0),'completed'=>(int)($p['completed']??0),
                    'active_session_id'=>(int)($p['active_session_id']??0),
                ];
                if(!isset($out[$key])||(int)$candidate['assignment_id']>(int)$out[$key]['assignment_id'])$out[$key]=$candidate;
            }
        }
        return $out;
    }

    public static function assignTraining(string $participantKey,string $focus,float $score,string $scriptId=''): array {
        if(!self::canManage())throw new \RuntimeException('Назначение тренировки доступно организатору или партнёру.');
        $userId=self::userIdFromKey($participantKey);if($userId<1)throw new \InvalidArgumentException('Автоматическое назначение доступно только зарегистрированному сотруднику.');
        $catalog=self::criteriaCatalog();if(!isset($catalog[$focus]))throw new \InvalidArgumentException('Неизвестный навык для назначения.');
        $target=self::trainingTarget($scriptId,$focus,$score);
        if((int)($target['scenario_id']??0)<1)throw new \RuntimeException('Не удалось подобрать тренировочный кейс.');

        $svc=new \CKM\NegotiationMaster\AssignmentService();
        foreach($svc->listOwn() as $row){
            if(!is_array($row)||(string)($row['status']??'')!=='active'||(int)($row['scenario_id']??0)!==(int)$target['scenario_id'])continue;
            if((string)($row['assignment_mode']??'')!=='individual'||(string)($row['mode']??'')!=='training')continue;
            try{$detail=$svc->detail((int)$row['id']);}catch(\Throwable){continue;}
            foreach((array)($detail['participants']??[]) as $p){
                if((int)($p['user_id']??0)===$userId)return ['assignment'=>$detail,'target'=>$target,'reused'=>true];
            }
        }

        $user=get_userdata($userId);$name=$user?trim((string)$user->display_name):'';if($name==='')$name='Сотрудник #'.$userId;
        $assignment=$svc->create([
            'scenario_id'=>(int)$target['scenario_id'],
            'title'=>'Развитие: '.(string)$catalog[$focus]['title'].' — '.$name,
            'assignment_mode'=>'individual','mode'=>'training','difficulty'=>'medium','max_attempts'=>2,
            'voice_enabled'=>true,'result_visibility'=>'immediate',
        ]);
        $assignment=$svc->addParticipants((int)$assignment['id'],[['identifier'=>(string)$userId]]);
        $assignment=$svc->setStatus((int)$assignment['id'],'active');
        return ['assignment'=>$assignment,'target'=>$target,'reused'=>false];
    }

    public static function assignScenarioTraining(string $participantKey,int $scenarioId,string $title='Практический кейс'): array {
        if(!self::canManage())throw new \RuntimeException('Назначение тренировки доступно организатору или партнёру.');
        $userId=self::userIdFromKey($participantKey);if($userId<1)throw new \InvalidArgumentException('Автоматическое назначение доступно только зарегистрированному сотруднику.');
        $map=self::scenarioMap();$target=$map[$scenarioId]??null;
        if(!$target||$scenarioId<1)throw new \InvalidArgumentException('Тренировочный кейс не найден в текущей площадке.');

        $svc=new \CKM\NegotiationMaster\AssignmentService();
        foreach($svc->listOwn() as $row){
            if(!is_array($row)||(string)($row['status']??'')!=='active'||(int)($row['scenario_id']??0)!==$scenarioId)continue;
            if((string)($row['assignment_mode']??'')!=='individual'||(string)($row['mode']??'')!=='training')continue;
            try{$detail=$svc->detail((int)$row['id']);}catch(\Throwable){continue;}
            foreach((array)($detail['participants']??[]) as $p){
                if((int)($p['user_id']??0)===$userId)return ['assignment'=>$detail,'target'=>$target,'reused'=>true];
            }
        }
        $user=get_userdata($userId);$name=$user?trim((string)$user->display_name):'';if($name==='')$name='Сотрудник #'.$userId;
        $focusTitle=trim((string)($target['focus_title']??''));if($focusTitle==='')$focusTitle=$title;
        $assignment=$svc->create([
            'scenario_id'=>$scenarioId,'title'=>$title.' — '.$name,
            'assignment_mode'=>'individual','mode'=>'training','difficulty'=>'medium','max_attempts'=>2,
            'voice_enabled'=>true,'result_visibility'=>'immediate',
        ]);
        $assignment=$svc->addParticipants((int)$assignment['id'],[['identifier'=>(string)$userId]]);
        $assignment=$svc->setStatus((int)$assignment['id'],'active');
        return ['assignment'=>$assignment,'target'=>$target,'reused'=>false];
    }

    public static function dashboard(): array {
        if(!self::canManage())throw new \RuntimeException('Командная аналитика доступна организатору или партнёру.');
        $catalog=self::criteriaCatalog();$scenarioMap=self::scenarioMap();$rows=self::rawRows($scenarioMap);
        $people=[];
        foreach(array_reverse($rows) as $row){
            $key=(string)$row['participant_key'];if($key==='')continue;
            if(!isset($people[$key]))$people[$key]=[
                'participant_key'=>$key,'label'=>(string)$row['participant_label'],'attempts'=>[],
                'criteria_stats'=>[],'first_score'=>null,'last_score'=>null,
            ];
            $p=&$people[$key];$p['attempts'][]=$row;
            if($p['first_score']===null)$p['first_score']=(float)$row['final_score'];
            $p['last_score']=(float)$row['final_score'];
            foreach($row['criteria'] as $code=>$criterion){
                if(!isset($catalog[$code]))continue;
                $score=(float)$criterion['raw_score'];
                if(!isset($p['criteria_stats'][$code]))$p['criteria_stats'][$code]=['sum'=>0.0,'count'=>0,'first'=>$score,'last'=>$score];
                $p['criteria_stats'][$code]['sum']+=$score;$p['criteria_stats'][$code]['count']++;
                $p['criteria_stats'][$code]['last']=$score;
            }
            unset($p);
        }

        $employees=[];$teamCriterionBuckets=[];$latestScores=[];
        foreach($people as $key=>$p){
            $attempts=(array)$p['attempts'];$latest=end($attempts);if(!$latest)continue;
            $criteria=[];$averages=[];
            foreach($catalog as $code=>$def){
                $st=$p['criteria_stats'][$code]??null;
                if(!$st)continue;
                $avg=(float)$st['sum']/max(1,(int)$st['count']);
                $criteria[$code]=[
                    'code'=>$code,'title'=>(string)$def['title'],'latest'=>(float)$st['last'],'average'=>$avg,
                    'delta'=>(float)$st['last']-(float)$st['first'],
                ];
                $averages[]=$avg;
                $teamCriterionBuckets[$code][]=(float)$st['last'];
            }
            $weak=self::weakest((array)$latest['criteria'],$catalog);
            $employees[]=[
                'participant_key'=>$key,'user_id'=>self::userIdFromKey($key),'label'=>(string)$p['label'],'attempt_count'=>count($attempts),
                'first_score'=>(float)($p['first_score']??0),'latest_score'=>(float)($p['last_score']??0),
                'delta'=>(float)($p['last_score']??0)-(float)($p['first_score']??0),
                'average_score'=>$averages?array_sum($averages)/count($averages):0.0,
                'latest_at'=>(string)($latest['completed_at']??''),'latest_context'=>(string)($latest['focus_title']??'Базовая ситуация'),
                'latest_script_id'=>(string)($latest['script_id']??''),
                'criteria'=>$criteria,'weakest'=>$weak,'recommendation'=>self::recommendation($weak),
                'history'=>array_slice(array_reverse($attempts),0,8),'assignment'=>null,
            ];
            $latestScores[]=(float)($p['last_score']??0);
        }
        usort($employees,static function(array $a,array $b): int{
            $as=(float)($a['latest_score']??0);$bs=(float)($b['latest_score']??0);
            if($as===$bs)return strcmp((string)$a['label'],(string)$b['label']);
            return $bs<=>$as;
        });
        $assignmentStates=self::assignmentStates($scenarioMap);
        foreach($employees as &$employee){
            $key=(string)($employee['participant_key']??'');
            $employee['assignment']=$assignmentStates[$key]??null;
        }
        unset($employee);

        $teamCriteria=[];
        foreach($catalog as $code=>$def){
            $vals=$teamCriterionBuckets[$code]??[];
            $avg=$vals?array_sum($vals)/count($vals):null;
            $teamCriteria[]=['code'=>$code,'title'=>(string)$def['title'],'score'=>$avg,'people'=>count($vals)];
        }
        usort($teamCriteria,static function(array $a,array $b): int{
            if($a['score']===null)return 1;if($b['score']===null)return -1;
            return ((float)$a['score'])<=>((float)$b['score']);
        });

        $recommendations=[];
        foreach($employees as $employee){
            $weak=$employee['weakest']??null;
            if(!$weak||(float)$weak['score']>=self::PASS_SCORE)continue;
            $recommendations[]=[
                'participant_key'=>(string)$employee['participant_key'],'user_id'=>(int)($employee['user_id']??0),
                'label'=>(string)$employee['label'],'score'=>(float)$weak['score'],'focus_code'=>(string)$weak['code'],
                'focus_title'=>(string)$weak['title'],'action'=>(string)$employee['recommendation'],
                'script_id'=>(string)($employee['latest_script_id']??''),'assignment'=>$employee['assignment']??null,
            ];
        }
        usort($recommendations,static fn(array $a,array $b): int=>((float)$a['score'])<=>((float)$b['score']));

        $needs=count(array_filter($employees,static fn(array $e): bool=>($e['weakest']??null)!==null&&(float)$e['weakest']['score']<self::PASS_SCORE));
        return [
            'criteria_catalog'=>$catalog,'scenario_count'=>count($scenarioMap),'attempt_count'=>count($rows),
            'employees'=>$employees,'employee_count'=>count($employees),'needs_training'=>$needs,
            'team_latest_average'=>$latestScores?array_sum($latestScores)/count($latestScores):null,
            'team_criteria'=>$teamCriteria,'team_weakest'=>$teamCriteria[0]??null,'recommendations'=>$recommendations,
            'pass_score'=>self::PASS_SCORE,
        ];
    }
}
