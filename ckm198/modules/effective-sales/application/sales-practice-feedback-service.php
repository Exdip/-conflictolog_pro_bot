<?php
namespace CKM\EffectiveSales;
if (!defined('ABSPATH')) { exit; }

final class SalesPracticeFeedbackService {
    private const LIMIT=30;

    public static function canManage(): bool { return SalesTeamDevelopmentService::canManage(); }

    private static function lower(string $v): string { return function_exists('mb_strtolower')?mb_strtolower($v,'UTF-8'):strtolower($v); }
    private static function clip(string $v,int $max=360): string {
        $v=trim(wp_strip_all_tags($v));
        $v=preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu','[email]',$v)??$v;
        $v=preg_replace('/(?<!\d)(?:\+?\d[\d\s()\-]{8,}\d)(?!\d)/u','[телефон]',$v)??$v;
        $v=preg_replace('/@[A-Za-z0-9_]{3,}/u','[контакт]',$v)??$v;
        $v=preg_replace('~https?://\S+~iu','[ссылка]',$v)??$v;
        return function_exists('mb_substr')?mb_substr($v,0,$max,'UTF-8'):substr($v,0,$max);
    }

    private static function clientMessages(array $dialog): array {
        $out=[];
        foreach((array)($dialog['messages']??[]) as $m){
            if(!is_array($m)||(string)($m['role']??'')!=='user')continue;
            $text=self::clip((string)($m['content']??''),500);if($text!=='')$out[]=$text;
        }
        return $out;
    }

    private static function focus(array $dialog): array {
        $messages=self::clientMessages($dialog);$text=self::lower(implode(' ',array_slice($messages,-6)));
        $catalog=SalesAdaptivePolygonService::focusCatalog();
        $rules=[
            'objection_handling'=>['дорог','цена','скидк','конкур','риск','сомнен','не довер','уже есть','не нужно','не надо','обожг'],
            'value_proposition'=>['зачем','польз','смысл','выгод','ценност','что даст','результат','эффект'],
            'next_step'=>['потом','позже','подума','пришлите','перезвон','свяж','вернемся','вернёмся','не сейчас','времени'],
            'customer_understanding'=>['не понима','не то','другая проблем','проблема','мне нужно','нам нужно','важно','на самом деле'],
        ];
        foreach($rules as $code=>$needles){
            foreach($needles as $needle)if(str_contains($text,$needle))return ['code'=>$code,'title'=>(string)($catalog[$code]['title']??$code)];
        }
        $code=!empty($dialog['handoff'])?'question_quality':'customer_understanding';
        return ['code'=>$code,'title'=>(string)($catalog[$code]['title']??$code)];
    }

    private static function difficulty(array $dialog): int {
        $goal=(string)($dialog['goal_status']??'active');$n=count(self::clientMessages($dialog));$score=0;
        if($goal==='unsuccessful')$score+=4;elseif($goal==='stalled')$score+=3;
        if(!empty($dialog['handoff']))$score+=2;
        if($n>=4)$score++;if($n>=7)$score++;
        return $score;
    }

    private static function existingCase(array $script,string $sessionId): ?array {
        foreach(array_reverse((array)($script['adaptive_cases']??[])) as $case){
            if(is_array($case)&&(string)($case['practice_source_session_id']??'')===$sessionId)return $case;
        }
        return null;
    }

    private static function candidate(array $script,array $dialog): ?array {
        $goal=(string)($dialog['goal_status']??'active');
        if($goal==='successful')return null;
        if($goal==='active'&&empty($dialog['handoff']))return null;
        $messages=self::clientMessages($dialog);if(!$messages)return null;
        $focus=self::focus($dialog);$sessionId=(string)($dialog['session_id']??'');if($sessionId==='')return null;
        $existing=self::existingCase($script,$sessionId);
        return [
            'script_id'=>(string)($script['id']??''),'script_title'=>(string)($script['title']??'Методика'),
            'session_id'=>$sessionId,'channel'=>(string)($dialog['channel']??'web'),'goal_status'=>$goal,
            'source_kind'=>(string)($dialog['source_kind']??'ai_seller'),
            'pipeline_stage'=>(string)($dialog['pipeline_stage']??'new'),'handoff'=>!empty($dialog['handoff']),
            'last_at'=>(string)($dialog['last_at']??''),'focus_code'=>(string)$focus['code'],'focus_title'=>(string)$focus['title'],
            'excerpt'=>implode(' / ',array_slice($messages,-2)),'priority'=>self::difficulty($dialog),
            'case_created'=>$existing!==null,'case'=>$existing,
        ];
    }

    public static function candidates(string $onlyScriptId='',int $limit=self::LIMIT): array {
        if(!self::canManage())return [];
        $out=[];
        foreach(SalesScriptService::all() as $script){
            if(!is_array($script))continue;$scriptId=(string)($script['id']??'');if($scriptId==='')continue;
            if($onlyScriptId!==''&&$scriptId!==$onlyScriptId)continue;
            foreach(SalesAiSellerWorkspaceService::recentDialogs($scriptId,20) as $dialog){
                if(!is_array($dialog))continue;$row=self::candidate($script,$dialog);if($row)$out[]=$row;
            }
        }
        usort($out,static function(array $a,array $b): int{
            $p=((int)$b['priority'])<=>((int)$a['priority']);if($p!==0)return $p;
            return strcmp((string)$b['last_at'],(string)$a['last_at']);
        });
        return array_slice($out,0,max(1,min(self::LIMIT,$limit)));
    }

    public static function dashboard(string $scriptId=''): array {
        $rows=self::candidates($scriptId);$created=count(array_filter($rows,static fn(array $r): bool=>!empty($r['case_created'])));
        return ['candidates'=>$rows,'count'=>count($rows),'created'=>$created,'pending'=>count($rows)-$created];
    }

    public static function realDialogHistory(string $scriptId,int $limit=8): array {
        if(!self::canManage()||$scriptId==='')return [];
        $script=SalesScriptService::find($scriptId);if(!$script)return [];
        $out=[];
        foreach(SalesAiSellerWorkspaceService::recentDialogs($scriptId,20) as $dialog){
            if(!is_array($dialog)||(string)($dialog['source_kind']??'')!=='human_import')continue;
            $sessionId=(string)($dialog['session_id']??'');if($sessionId==='')continue;
            $goal=(string)($dialog['goal_status']??'active');
            $case=self::existingCase($script,$sessionId);
            $focus=in_array($goal,['unsuccessful','stalled'],true)?self::focus($dialog):null;
            $out[]=[
                'session_id'=>$sessionId,
                'channel'=>(string)($dialog['channel']??'web'),
                'employee_name'=>(string)($dialog['employee_name']??''),
                'participant_key'=>(string)($dialog['participant_key']??''),
                'goal_status'=>$goal,
                'last_at'=>(string)($dialog['last_at']??''),
                'messages'=>count((array)($dialog['messages']??[])),
                'focus_code'=>is_array($focus)?(string)($focus['code']??''):'',
                'focus_title'=>is_array($focus)?(string)($focus['title']??''):'',
                'case_created'=>$case!==null,
                'case'=>$case,
            ];
            if(count($out)>=max(1,min(20,$limit)))break;
        }
        return $out;
    }

    public static function createCase(string $scriptId,string $sessionId): array {
        if(!self::canManage())throw new \RuntimeException('Кейсы из практики доступны организатору или партнёру.');
        $script=SalesScriptService::find($scriptId);if(!$script)throw new \InvalidArgumentException('Методика не найдена.');
        $existing=self::existingCase($script,$sessionId);if($existing)return ['case'=>$existing,'reused'=>true];
        $dialog=SalesAiSellerWorkspaceService::findDialog($scriptId,$sessionId);if(!$dialog)throw new \InvalidArgumentException('Диалог практики не найден.');
        $row=self::candidate($script,$dialog);if(!$row)throw new \RuntimeException('Этот диалог не требует отдельного тренировочного кейса.');
        $case=SalesAdaptivePolygonService::createCaseForFocus($scriptId,(string)$row['focus_code'],1,[
            'why'=>'Кейс создан из обезличенного паттерна трудной ситуации реального диалога. Исходные реплики клиента в тренировочный сценарий не копируются.',
            'practice_source_session_id'=>$sessionId,
            'practice_source_kind'=>(string)($row['source_kind']??'ai_seller'),
            'practice_source_channel'=>(string)$row['channel'],
            'practice_source_outcome'=>(string)$row['goal_status'],
        ]);
        return ['case'=>$case,'reused'=>false];
    }
    public static function createAndAssign(string $scriptId,string $sessionId,string $participantKey=''): array {
        $made=self::createCase($scriptId,$sessionId);$case=(array)($made['case']??[]);
        $assignment=null;
        if($participantKey!==''){
            $scenarioId=(int)($case['scenario_id']??0);if($scenarioId<1)throw new \RuntimeException('Тренировочный кейс создан без сценария.');
            $assignment=SalesTeamDevelopmentService::assignScenarioTraining(
                $participantKey,$scenarioId,'Практический кейс: '.(string)($case['focus_title']??'навык продаж')
            );
        }
        return ['case'=>$case,'case_reused'=>!empty($made['reused']),'assignment'=>$assignment];
    }


    private static function methodologyRecommendation(string $focus): string {
        return match($focus){
            'customer_understanding'=>'Зафиксировать обязательную диагностику текущей ситуации, последствий и критерия улучшения до презентации решения.',
            'question_quality'=>'Закрепить принцип: каждый следующий вопрос выбирается из предыдущего ответа клиента; не задавать пакет вопросов по шаблону.',
            'value_proposition'=>'Требовать связывать ценность только с подтверждённой задачей клиента и измеримым критерием результата.',
            'objection_handling'=>'Разделять внешнее возражение и реальную причину сомнения: сначала уточнение источника риска, затем аргумент.',
            'next_step'=>'Каждый содержательный разговор завершать конкретным следующим шагом: формат, участники и срок следующего контакта.',
            default=>'Добавить в методику правило по повторяющемуся практическому затруднению.',
        };
    }

    private static function methodologyNotes(array $script): array {
        $rows=is_array($script['practice_methodology_notes']??null)?array_values($script['practice_methodology_notes']):[];
        return array_values(array_filter($rows,static fn($x): bool=>is_array($x)&&!empty($x['focus_code'])));
    }

    public static function insights(string $scriptId): array {
        if(!self::canManage())return [];
        $script=SalesScriptService::find($scriptId);if(!$script)return [];
        $groups=[];$notes=self::methodologyNotes($script);$adopted=[];
        foreach($notes as $note)$adopted[(string)$note['focus_code']]=$note;
        foreach(self::candidates($scriptId,self::LIMIT) as $row){
            $focus=(string)($row['focus_code']??'');if($focus==='')continue;
            if(!isset($groups[$focus]))$groups[$focus]=[
                'focus_code'=>$focus,'focus_title'=>(string)($row['focus_title']??$focus),'count'=>0,
                'unsuccessful'=>0,'stalled'=>0,'handoff'=>0,'priority_sum'=>0,'channels'=>[],'latest_at'=>'',
                'recommendation'=>self::methodologyRecommendation($focus),'adopted'=>false,'note'=>null,
            ];
            $g=&$groups[$focus];$g['count']++;$g['priority_sum']+=(int)($row['priority']??0);
            $goal=(string)($row['goal_status']??'');if($goal==='unsuccessful')$g['unsuccessful']++;if($goal==='stalled')$g['stalled']++;
            if(!empty($row['handoff']))$g['handoff']++;
            $channel=(string)($row['channel']??'web');if($channel!=='')$g['channels'][$channel]=true;
            $at=(string)($row['last_at']??'');if($at>$g['latest_at'])$g['latest_at']=$at;
            unset($g);
        }
        $out=[];
        foreach($groups as $focus=>$g){
            $g['channels']=array_keys((array)$g['channels']);
            $g['repeated']=(int)$g['count']>=2;
            if(isset($adopted[$focus])){$g['adopted']=true;$g['note']=$adopted[$focus];}
            $out[]=$g;
        }
        usort($out,static function(array $a,array $b): int{
            $r=((int)!empty($b['repeated']))<=>((int)!empty($a['repeated']));if($r!==0)return $r;
            $c=((int)$b['count'])<=>((int)$a['count']);if($c!==0)return $c;
            return ((int)$b['priority_sum'])<=>((int)$a['priority_sum']);
        });
        return $out;
    }

    public static function adoptInsight(string $scriptId,string $focus): array {
        if(!self::canManage())throw new \RuntimeException('Изменение методики доступно организатору или партнёру.');
        $insight=null;foreach(self::insights($scriptId) as $row)if((string)($row['focus_code']??'')===$focus){$insight=$row;break;}
        if(!$insight)throw new \InvalidArgumentException('Практический сигнал не найден.');
        if(empty($insight['repeated']))throw new \RuntimeException('Для изменения методики нужен повторяющийся сигнал минимум из двух трудных диалогов.');
        $now=current_time('mysql');$reused=false;
        $script=SalesScriptService::mutate($scriptId,static function(array $row) use($insight,$focus,$now,&$reused): array {
            $notes=is_array($row['practice_methodology_notes']??null)?array_values($row['practice_methodology_notes']):[];
            foreach($notes as &$note){
                if(!is_array($note)||(string)($note['focus_code']??'')!==$focus)continue;
                $note['evidence_count']=(int)$insight['count'];$note['evidence_last_at']=(string)$insight['latest_at'];
                $note['recommendation']=(string)$insight['recommendation'];$note['updated_at']=$now;$reused=true;
                $row['practice_methodology_notes']=$notes;return $row;
            }unset($note);
            $notes[]=[
                'id'=>'practice_note_'.substr(hash('sha256',$focus.'|'.$now.'|'.wp_generate_uuid4()),0,16),
                'focus_code'=>$focus,'focus_title'=>(string)$insight['focus_title'],'recommendation'=>(string)$insight['recommendation'],
                'evidence_count'=>(int)$insight['count'],'evidence_last_at'=>(string)$insight['latest_at'],
                'status'=>'adopted','created_at'=>$now,'updated_at'=>$now,
            ];
            $row['practice_methodology_notes']=array_slice($notes,-20);return $row;
        });
        return ['script'=>$script,'reused'=>$reused,'insight'=>$insight];
    }



    public static function activeRules(array $script): array {
        if((string)($script['status']??'draft')!=='approved')return [];
        $out=[];$allowed=SalesAdaptivePolygonService::focusCatalog();
        foreach(self::methodologyNotes($script) as $note){
            $focus=(string)($note['focus_code']??'');
            if(($note['status']??'adopted')!=='active'||!isset($allowed[$focus]))continue;
            $out[]=['focus_code'=>$focus,'focus_title'=>(string)$allowed[$focus]['title'],'rule'=>self::methodologyRecommendation($focus)];
        }
        return $out;
    }

    public static function activeRulesText(array $script): string {
        $rules=array_map(static fn(array $r): string=>'- '.(string)$r['rule'],self::activeRules($script));
        return implode("\n",$rules);
    }

    public static function setRuleActive(string $scriptId,string $focus,bool $activate): array {
        if(!self::canManage())throw new \RuntimeException('Включать правила может только организатор или партнёр.');
        if(!isset(SalesAdaptivePolygonService::focusCatalog()[$focus]))throw new \InvalidArgumentException('Неизвестный навык.');
        $changed=false;$now=current_time('mysql');$nowUtc=current_time('mysql',true);$userId=get_current_user_id();
        $script=SalesScriptService::mutate($scriptId,static function(array $row) use($focus,$activate,$now,$nowUtc,$userId,&$changed): array {
            if((string)($row['status']??'draft')!=='approved')throw new \RuntimeException('Включение возможно только для утверждённой методики.');
            $notes=is_array($row['practice_methodology_notes']??null)?array_values($row['practice_methodology_notes']):[];
            $found=false;
            foreach($notes as &$note){
                if(!is_array($note)||(string)($note['focus_code']??'')!==$focus)continue;
                $found=true;$previous=(string)($note['status']??'adopted');$target=$activate?'active':'adopted';
                if($previous!==$target){
                    $note['status']=$target;$note['updated_at']=$now;$note['changed_by']=$userId;
                    $note['activated_at']=$activate?$now:'';
                    $changed=true;
                }
                break;
            }unset($note);
            if(!$found)throw new \InvalidArgumentException('Сначала добавьте сигнал в методику.');
            $row['practice_methodology_notes']=$notes;
            if($changed){
                $row['practice_standard_revision']=max(0,(int)($row['practice_standard_revision']??0))+1;
                $events=is_array($row['practice_standard_events']??null)?array_values($row['practice_standard_events']):[];
                $events[]=['focus_code'=>$focus,'action'=>$activate?'activated':'disabled','at'=>$now,'at_utc'=>$nowUtc,'user_id'=>$userId,'revision'=>(int)$row['practice_standard_revision']];
                $row['practice_standard_events']=array_slice($events,-40);
            }
            return $row;
        });
        return ['script'=>$script,'changed'=>$changed,'active'=>$activate,'revision'=>(int)($script['practice_standard_revision']??0)];
    }


}
