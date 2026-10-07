<?php
namespace CKM\EffectiveSales;
if (!defined('ABSPATH')) { exit; }

final class SalesStandardRecertificationService {
    public static function canManage(): bool { return SalesTeamDevelopmentService::canManage(); }

    private static function userIdFromKey(string $key): int {
        return preg_match('/^user:(\d+)$/D',$key,$m)?(int)$m[1]:0;
    }

    private static function currentTarget(array $script): ?array {
        $revision=max(0,(int)($script['practice_standard_revision']??0));
        if($revision<1)return null;
        $active=SalesPracticeFeedbackService::activeRules($script);
        if(!$active)return null;
        $activeMap=[];foreach($active as $r)$activeMap[(string)$r['focus_code']]=$r;
        $events=is_array($script['practice_standard_events']??null)?array_values($script['practice_standard_events']):[];
        for($i=count($events)-1;$i>=0;$i--){
            $e=$events[$i]??null;if(!is_array($e))continue;
            $focus=(string)($e['focus_code']??'');
            if(isset($activeMap[$focus]))return ['revision'=>$revision,'focus_code'=>$focus,'focus_title'=>(string)$activeMap[$focus]['focus_title'],'rule'=>(string)$activeMap[$focus]['rule']];
        }
        $r=reset($active);
        return ['revision'=>$revision,'focus_code'=>(string)$r['focus_code'],'focus_title'=>(string)$r['focus_title'],'rule'=>(string)$r['rule']];
    }

    private static function existingCase(array $script,int $revision): ?array {
        foreach(array_reverse((array)($script['adaptive_cases']??[])) as $case){
            if(!is_array($case))continue;
            if((int)($case['recertification_revision']??0)===$revision&&(int)($case['scenario_id']??0)>0)return $case;
        }
        return null;
    }

    private static function ensureCase(string $scriptId,array $target): array {
        $script=SalesScriptService::find($scriptId);if(!$script)throw new \InvalidArgumentException('Методика не найдена.');
        $revision=(int)$target['revision'];$existing=self::existingCase($script,$revision);if($existing)return $existing;
        return SalesAdaptivePolygonService::createCaseForFocus($scriptId,(string)$target['focus_code'],3,[
            'why'=>'Контроль применения новой редакции стандарта v'.$revision.': '.(string)$target['rule'],
            'recertification_revision'=>$revision,
            'recertification_focus'=>(string)$target['focus_code'],
        ]);
    }

    private static function assignmentStatusForScenario(int $scenarioId): array {
        $out=[];$svc=new \CKM\NegotiationMaster\AssignmentService();
        foreach($svc->listOwn() as $row){
            if(!is_array($row)||(int)($row['scenario_id']??0)!==$scenarioId||(string)($row['mode']??'')!=='exam'||(string)($row['assignment_mode']??'')!=='individual')continue;
            try{$detail=$svc->detail((int)$row['id']);}catch(\Throwable){continue;}
            foreach((array)($detail['participants']??[]) as $p){
                if(!is_array($p)||(int)($p['user_id']??0)<1)continue;
                // A draft or an unfinished closed exam cannot satisfy recertification.
                if((int)($p['completed']??0)<1&&(string)($detail['status']??'')!=='active')continue;
                $key='user:'.(int)$p['user_id'];
                $status='Назначено';
                if((int)($p['active_session_id']??0)>0)$status='В процессе';
                elseif((int)($p['completed']??0)>0)$status='Выполнено';
                elseif((string)($detail['status']??'')==='closed')$status='Закрыто';
                $candidate=[
                    'assignment_id'=>(int)$detail['id'],'assignment_url'=>(string)($detail['assignment_url']??''),
                    'created_at'=>(string)($detail['created_at']??''),
                    'status'=>$status,'assignment_status'=>(string)($detail['status']??''),
                    'completed'=>(int)($p['completed']??0),'active_session_id'=>(int)($p['active_session_id']??0),
                ];
                if(!isset($out[$key])||(int)$candidate['assignment_id']>(int)$out[$key]['assignment_id'])$out[$key]=$candidate;
            }
        }
        return $out;
    }

    public static function dashboard(string $scriptId): array {
        if(!self::canManage())return ['available'=>false];
        $script=SalesScriptService::find($scriptId);if(!$script)return ['available'=>false];
        $target=self::currentTarget($script);if(!$target)return ['available'=>false,'revision'=>(int)($script['practice_standard_revision']??0)];
        $case=self::existingCase($script,(int)$target['revision']);
        $scenarioId=(int)($case['scenario_id']??0);
        $statuses=$scenarioId>0?self::assignmentStatusForScenario($scenarioId):[];
        $team=SalesTeamDevelopmentService::dashboard();$employees=[];
        foreach((array)($team['employees']??[]) as $e){
            if(!is_array($e)||(int)($e['user_id']??0)<1)continue;
            $eligible=false;foreach((array)($e['history']??[]) as $h)if(is_array($h)&&(string)($h['script_id']??'')===$scriptId){$eligible=true;break;}
            if(!$eligible&&(string)($e['latest_script_id']??'')!==$scriptId)continue;
            $key=(string)($e['participant_key']??'');$employees[]=[
                'participant_key'=>$key,'user_id'=>(int)$e['user_id'],'label'=>(string)$e['label'],
                'latest_score'=>(float)($e['latest_score']??0),'assignment'=>$statuses[$key]??null,
            ];
        }
        $done=count(array_filter($employees,static fn(array $e): bool=>is_array($e['assignment'])&&(string)($e['assignment']['status']??'')==='Выполнено'));
        return [
            'available'=>true,'revision'=>(int)$target['revision'],'focus_code'=>(string)$target['focus_code'],
            'focus_title'=>(string)$target['focus_title'],'rule'=>(string)$target['rule'],
            'scenario_id'=>$scenarioId,'case'=>$case,'employees'=>$employees,'employee_count'=>count($employees),'completed_count'=>$done,
        ];
    }

    public static function assign(string $scriptId,string $participantKey): array {
        if(!self::canManage())throw new \RuntimeException('Повторную проверку может назначить только организатор или партнёр.');
        $script=SalesScriptService::find($scriptId);if(!$script)throw new \InvalidArgumentException('Методика не найдена.');
        $target=self::currentTarget($script);if(!$target)throw new \RuntimeException('Нет действующей новой редакции стандарта для повторной проверки.');
        $userId=self::userIdFromKey($participantKey);if($userId<1)throw new \InvalidArgumentException('Выберите зарегистрированного сотрудника.');
        $user=get_userdata($userId);if(!$user)throw new \InvalidArgumentException('Выберите зарегистрированного сотрудника.');
        $case=self::ensureCase($scriptId,$target);$scenarioId=(int)($case['scenario_id']??0);if($scenarioId<1)throw new \RuntimeException('Не удалось создать контрольный кейс.');
        $statuses=self::assignmentStatusForScenario($scenarioId);
        if(isset($statuses[$participantKey]))return ['assignment'=>$statuses[$participantKey],'case'=>$case,'reused'=>true,'revision'=>(int)$target['revision']];
        $name=trim((string)$user->display_name);if($name==='')$name='Сотрудник #'.$userId;
        $title='Повторная проверка · стандарт v'.(int)$target['revision'].' · '.(string)$target['focus_title'].' — '.$name;
        $svc=new \CKM\NegotiationMaster\AssignmentService();
        // Resume an interrupted create/add/activate sequence instead of leaving a draft
        // that blocks this employee or creating a second exam on the retry.
        foreach($svc->listOwn() as $row){
            if(!is_array($row)||(int)($row['scenario_id']??0)!==$scenarioId
                ||(string)($row['mode']??'')!=='exam'||(string)($row['assignment_mode']??'')!=='individual'
                ||(string)($row['status']??'')!=='draft'||(string)($row['title']??'')!==$title)continue;
            try{$draft=$svc->detail((int)$row['id']);}catch(\Throwable){continue;}
            $participants=(array)($draft['participants']??[]);
            if(count($participants)>1)continue;
            if($participants&&(int)($participants[0]['user_id']??0)!==$userId)continue;
            $assignment=$svc->addParticipants((int)$row['id'],[['identifier'=>(string)$userId]]);
            $assignment=$svc->setStatus((int)$assignment['id'],'active');
            return ['assignment'=>$assignment,'case'=>$case,'reused'=>true,'revision'=>(int)$target['revision']];
        }
        $assignment=$svc->create([
            'scenario_id'=>$scenarioId,
            'title'=>$title,
            'assignment_mode'=>'individual','mode'=>'exam','difficulty'=>'medium','max_attempts'=>1,
            'voice_enabled'=>true,'result_visibility'=>'immediate',
        ]);
        $assignment=$svc->addParticipants((int)$assignment['id'],[['identifier'=>(string)$userId]]);
        $assignment=$svc->setStatus((int)$assignment['id'],'active');
        return ['assignment'=>$assignment,'case'=>$case,'reused'=>false,'revision'=>(int)$target['revision']];
    }

    public static function assignAll(string $scriptId): array {
        $d=self::dashboard($scriptId);if(empty($d['available']))throw new \RuntimeException('Нет новой редакции стандарта для повторной проверки.');
        $created=0;$reused=0;
        foreach((array)$d['employees'] as $e){
            $key=(string)($e['participant_key']??'');if($key==='')continue;
            $r=self::assign($scriptId,$key);if(!empty($r['reused']))$reused++;else$created++;
        }
        return ['created'=>$created,'reused'=>$reused,'revision'=>(int)$d['revision']];
    }
}
