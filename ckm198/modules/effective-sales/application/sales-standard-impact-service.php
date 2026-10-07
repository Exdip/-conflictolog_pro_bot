<?php
namespace CKM\EffectiveSales;
if (!defined('ABSPATH')) { exit; }

final class SalesStandardImpactService {
    private const PASS_SCORE=75;

    public static function canManage(): bool { return SalesStandardRecertificationService::canManage(); }

    private static function scenarioIds(array $script): array {
        $ids=[];$base=(int)($script['ai_client_scenario_id']??0);if($base>0)$ids[$base]=true;
        foreach((array)($script['adaptive_cases']??[]) as $case){
            if(!is_array($case)||(int)($case['recertification_revision']??0)>0)continue;
            $sid=(int)($case['scenario_id']??0);if($sid>0)$ids[$sid]=true;
        }
        return array_map('intval',array_keys($ids));
    }

    private static function criterionResult(
        string $participantKey,string $focus,array $scenarioIds=[],
        int $assignmentId=0,int $scenarioId=0,string $cutoff=''
    ): ?array {
        global $wpdb;
        if($participantKey===''||$focus==='')return null;
        $ctx=\CKM\NegotiationMaster\Access::context();
        $sessions=\CKM\NegotiationMaster\Schema::table('sessions');
        $evaluations=\CKM\NegotiationMaster\Schema::table('evaluations');
        $scores=\CKM\NegotiationMaster\Schema::table('evaluation_scores');
        $rules=\CKM\NegotiationMaster\Schema::table('evaluation_rules');

        $where=["se.tenant_id=%d","se.participant_key=%s","se.session_kind IN ('player','assignment')","se.status LIKE 'completed_%%'","ev.status='completed'","er.code=%s"];
        $args=[(int)$ctx['tenant_id'],$participantKey,$focus];

        if($assignmentId>0){$where[]='se.assignment_id=%d';$args[]=$assignmentId;$where[]="se.mode='exam'";$where[]="se.session_kind='assignment'";}
        if($scenarioId>0){$where[]='se.scenario_id=%d';$args[]=$scenarioId;}
        if($scenarioIds){
            $ph=implode(',',array_fill(0,count($scenarioIds),'%d'));$where[]="se.scenario_id IN ($ph)";
            foreach($scenarioIds as $sid)$args[]=(int)$sid;
        }
        if($cutoff!==''){
            $where[]="COALESCE(ev.completed_at,se.completed_at,se.last_activity_at,se.created_at)<%s";$args[]=$cutoff;
        }

        $sql="SELECT se.id AS session_id,se.scenario_id,se.assignment_id,se.completed_at,
                    ev.final_score,ev.completed_at AS evaluation_completed_at,es.raw_score,er.title
              FROM `$sessions` se
              INNER JOIN `$evaluations` ev ON ev.session_id=se.id
              INNER JOIN `$scores` es ON es.evaluation_id=ev.id
              INNER JOIN `$rules` er ON er.id=es.evaluation_rule_id
              WHERE ".implode(' AND ',$where)."
              ORDER BY COALESCE(ev.completed_at,se.completed_at,se.last_activity_at,se.created_at) DESC,se.id DESC
              LIMIT 1";
        $row=$wpdb->get_row($wpdb->prepare($sql,$args),ARRAY_A);
        if(!$row)return null;
        return [
            'session_id'=>(int)$row['session_id'],'scenario_id'=>(int)$row['scenario_id'],'assignment_id'=>(int)$row['assignment_id'],
            'criterion_score'=>$row['raw_score']===null?null:(float)$row['raw_score'],
            'criterion_code'=>$focus,
            'final_score'=>$row['final_score']===null?null:(float)$row['final_score'],
            'criterion_title'=>(string)($row['title']??$focus),
            'completed_at'=>(string)($row['evaluation_completed_at']??$row['completed_at']??''),
        ];
    }

    private static function conclusion(int $completed,int $comparable,?float $before,?float $after,int $passed,int $improved): array {
        if($completed<1)return ['code'=>'awaiting','title'=>'Ждём контрольных результатов','text'=>'Новая редакция включена, но повторные проверки ещё не завершены.'];
        if($comparable<1)return ['code'=>'insufficient','title'=>'Недостаточно сопоставимых данных','text'=>'Контрольные результаты уже есть, но для них не найден результат по этому же навыку до изменения стандарта.'];
        $delta=(float)$after-(float)$before;
        if($comparable<2){
            if($delta>0&&$passed===$completed)return ['code'=>'preliminary_positive','title'=>'Предварительно есть положительный эффект','text'=>'У единственного сопоставимого сотрудника результат вырос и достиг порога 75. Для командного вывода нужны данные ещё хотя бы одного сотрудника.'];
            if($delta>0)return ['code'=>'preliminary_mixed','title'=>'Предварительно есть улучшение','text'=>'Результат вырос, но устойчивый порог 75 пока достигнут не во всех завершённых проверках.'];
            return ['code'=>'preliminary_no_effect','title'=>'Предварительно эффект не подтверждён','text'=>'По имеющемуся сопоставимому результату улучшения нет. Нужны дополнительные данные или доработка правила.'];
        }
        if($delta>0&&$improved===$comparable&&$passed===$completed)return ['code'=>'confirmed','title'=>'Эффект редакции подтверждён','text'=>'У каждого сопоставимого сотрудника результат по изменённому навыку вырос, и все завершившие повторную проверку достигли порога 75.'];
        if($delta>0)return ['code'=>'mixed','title'=>'Есть улучшение, но навык закреплён не у всех','text'=>'Средний результат вырос, однако у части сотрудников нет роста или результат остаётся ниже порога 75.'];
        return ['code'=>'not_confirmed','title'=>'Эффект редакции не подтверждён','text'=>'Средний результат по изменённому навыку не вырос. Правило стоит доработать и проверить повторно.'];
    }

    /** Pure aggregation of the same employee rows used by the dashboard. */
    public static function summarize(array $employees): array {
        $beforeVals=[];$afterVals=[];$passed=0;$completed=0;$comparable=0;$improved=0;$seen=[];
        foreach($employees as $employee){
            if(!is_array($employee))continue;
            $key=(string)($employee['participant_key']??'');
            if($key===''||isset($seen[$key]))continue;
            $seen[$key]=true;
            $after=is_array($employee['after']??null)?$employee['after']:null;
            $before=is_array($employee['before']??null)?$employee['before']:null;
            if(!$after||!isset($after['criterion_score'])||!is_numeric($after['criterion_score']))continue;
            $completed++;$afterScore=(float)$after['criterion_score'];
            if($afterScore>=self::PASS_SCORE)$passed++;
            if(!$before||!isset($before['criterion_score'])||!is_numeric($before['criterion_score']))continue;
            $beforeCode=(string)($before['criterion_code']??'');$afterCode=(string)($after['criterion_code']??'');
            if($beforeCode!==''&&$afterCode!==''&&$beforeCode!==$afterCode)continue;
            $beforeScore=(float)$before['criterion_score'];
            $comparable++;$beforeVals[]=$beforeScore;$afterVals[]=$afterScore;
            if($afterScore>$beforeScore)$improved++;
        }
        $avgBefore=$beforeVals?array_sum($beforeVals)/count($beforeVals):null;
        $avgAfter=$afterVals?array_sum($afterVals)/count($afterVals):null;
        return [
            'completed_count'=>$completed,'comparable_count'=>$comparable,'passed_count'=>$passed,
            'improved_count'=>$improved,'average_before'=>$avgBefore,'average_after'=>$avgAfter,
            'average_delta'=>($avgBefore!==null&&$avgAfter!==null)?$avgAfter-$avgBefore:null,
            'pass_rate'=>$completed>0?100*$passed/$completed:null,
            'conclusion'=>self::conclusion($completed,$comparable,$avgBefore,$avgAfter,$passed,$improved),
        ];
    }

    private static function revisionCutoff(array $script,int $revision): string {
        foreach(array_reverse((array)($script['practice_standard_events']??[])) as $event){
            if(!is_array($event)||(int)($event['revision']??0)!==$revision)continue;
            $utc=(string)($event['at_utc']??'');if($utc!=='')return $utc;
            // Legacy standard events are in the site's local time; session and
            // evaluation timestamps are UTC. Never compare these clocks directly.
            $local=(string)($event['at']??'');
            return $local!==''&&function_exists('get_gmt_from_date')?(string)get_gmt_from_date($local):'';
        }
        return '';
    }

    public static function dashboard(string $scriptId): array {
        if(!self::canManage())return ['available'=>false];
        $script=SalesScriptService::find($scriptId);if(!$script)return ['available'=>false];
        $recert=SalesStandardRecertificationService::dashboard($scriptId);
        if(empty($recert['available']))return ['available'=>false];

        $focus=(string)$recert['focus_code'];$scenarioId=(int)$recert['scenario_id'];
        $baselineScenarios=self::scenarioIds($script);$rows=[];
        $revisionCutoff=self::revisionCutoff($script,(int)$recert['revision']);

        foreach((array)$recert['employees'] as $employee){
            if(!is_array($employee))continue;
            $key=(string)($employee['participant_key']??'');$assignment=is_array($employee['assignment']??null)?$employee['assignment']:null;
            $before=null;$after=null;$delta=null;$status='Не назначено';
            if($assignment){
                $cutoff=$revisionCutoff;
                $before=$baselineScenarios&&$cutoff!==''?self::criterionResult($key,$focus,$baselineScenarios,0,0,$cutoff):null;
                $assignmentId=(int)($assignment['assignment_id']??0);
                $after=$assignmentId>0&&$scenarioId>0?self::criterionResult($key,$focus,[],$assignmentId,$scenarioId,''):null;
                $status=(string)($assignment['status']??'Назначено');
                if($after&&$after['criterion_score']!==null){
                    if($before&&$before['criterion_score']!==null){
                        $delta=(float)$after['criterion_score']-(float)$before['criterion_score'];
                    }
                }
            }
            $rows[]=[
                'participant_key'=>$key,'label'=>(string)($employee['label']??$key),'assignment'=>$assignment,
                'before'=>$before,'after'=>$after,'delta'=>$delta,'status'=>$status,
                'passed'=>$after&&$after['criterion_score']!==null&&(float)$after['criterion_score']>=self::PASS_SCORE,
            ];
        }
        return [
            'available'=>true,'revision'=>(int)$recert['revision'],'focus_code'=>$focus,'focus_title'=>(string)$recert['focus_title'],
            'rule'=>(string)$recert['rule'],'employee_count'=>count($rows),'assigned_count'=>count(array_filter($rows,static fn(array $r): bool=>is_array($r['assignment']))),
            'employees'=>$rows,'pass_score'=>self::PASS_SCORE,
        ]+self::summarize($rows);
    }
}
