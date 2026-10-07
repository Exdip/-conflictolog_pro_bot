<?php
namespace CKM\EffectiveSales;
if (!defined('ABSPATH')) { exit; }

final class SalesStandardRevisionDecisionService {
    public static function canManage(): bool { return SalesStandardImpactService::canManage(); }

    private static function historyRows(array $script): array {
        $rows=is_array($script['practice_standard_decisions']??null)?array_values($script['practice_standard_decisions']):[];
        $rows=array_values(array_filter($rows,static fn($r): bool=>is_array($r)&&((int)($r['revision']??0)>0)));
        usort($rows,static function(array $a,array $b): int{
            $r=((int)($b['revision']??0))<=>((int)($a['revision']??0));if($r!==0)return $r;
            return strcmp((string)($b['at']??''),(string)($a['at']??''));
        });
        return $rows;
    }

    private static function findDecision(array $script,int $revision): ?array {
        foreach(self::historyRows($script) as $row)if((int)($row['revision']??0)===$revision)return $row;
        return null;
    }

    public static function permissions(array $impact,?array $decision=null): array {
        if($decision)return ['confirm'=>false,'rework'=>false,'reason'=>'По этой редакции решение уже зафиксировано.'];
        $code=(string)($impact['conclusion']['code']??'awaiting');
        $completed=(int)($impact['completed_count']??0);$comparable=(int)($impact['comparable_count']??0);
        $confirm=$code==='confirmed'&&$comparable>=2;
        $rework=$comparable>=1&&in_array($code,['mixed','not_confirmed','preliminary_mixed','preliminary_no_effect'],true);
        if($confirm)return ['confirm'=>true,'rework'=>false,'reason'=>'Эффект подтверждён минимум на двух сопоставимых сотрудниках.'];
        if($rework)return ['confirm'=>false,'rework'=>true,'reason'=>'Есть сопоставимые контрольные данные, указывающие на необходимость доработки.'];
        if($completed<1)return ['confirm'=>false,'rework'=>false,'reason'=>'Сначала дождитесь хотя бы одной завершённой контрольной проверки.'];
        if($comparable<1)return ['confirm'=>false,'rework'=>false,'reason'=>'Нет сопоставимого результата по этому же навыку до изменения стандарта.'];
        if($comparable<2)return ['confirm'=>false,'rework'=>false,'reason'=>'Для закрепления редакции как командного стандарта нужны минимум два сопоставимых сотрудника.'];
        return ['confirm'=>false,'rework'=>false,'reason'=>'Данные пока не дают однозначного управленческого решения.'];
    }

    public static function dashboard(string $scriptId): array {
        if(!self::canManage())return ['available'=>false,'history'=>[]];
        $script=SalesScriptService::find($scriptId);if(!$script)return ['available'=>false,'history'=>[]];
        $impact=SalesStandardImpactService::dashboard($scriptId);
        $history=self::historyRows($script);
        if(empty($impact['available']))return ['available'=>false,'history'=>$history,'impact'=>$impact];
        $revision=(int)($impact['revision']??0);$decision=self::findDecision($script,$revision);
        return [
            'available'=>true,'revision'=>$revision,'impact'=>$impact,'decision'=>$decision,
            'permissions'=>self::permissions($impact,$decision),'history'=>$history,
        ];
    }

    private static function snapshot(array $impact): array {
        $c=is_array($impact['conclusion']??null)?$impact['conclusion']:[];
        return [
            'focus_code'=>(string)($impact['focus_code']??''),'focus_title'=>(string)($impact['focus_title']??''),
            'conclusion_code'=>(string)($c['code']??''),'conclusion_title'=>(string)($c['title']??''),
            'employee_count'=>(int)($impact['employee_count']??0),'completed_count'=>(int)($impact['completed_count']??0),
            'comparable_count'=>(int)($impact['comparable_count']??0),'passed_count'=>(int)($impact['passed_count']??0),
            'average_before'=>$impact['average_before']??null,'average_after'=>$impact['average_after']??null,
            'average_delta'=>$impact['average_delta']??null,'pass_rate'=>$impact['pass_rate']??null,
        ];
    }

    /** Pure state transition; persistence stays in SalesScriptService. */
    public static function applyDecision(array $row,string $decision,int $revision,string $focus,array $snapshot,string $now,int $userId,string $nowUtc=''): array {
        if(!in_array($decision,['confirm','rework'],true))throw new \InvalidArgumentException('Неизвестное решение по редакции.');
        if($revision<1)throw new \InvalidArgumentException('Неизвестная редакция стандарта.');
        $decisions=is_array($row['practice_standard_decisions']??null)?array_values($row['practice_standard_decisions']):[];
        foreach($decisions as $old){
            if(is_array($old)&&(int)($old['revision']??0)===$revision)return ['script'=>$row,'reused'=>true,'decision'=>$old];
        }
        if((int)($row['practice_standard_revision']??0)!==$revision)throw new \RuntimeException('Редакция стандарта изменилась. Обновите страницу перед решением.');

        $notes=is_array($row['practice_methodology_notes']??null)?array_values($row['practice_methodology_notes']):[];
        $found=false;
        foreach($notes as &$note){
            if(!is_array($note)||(string)($note['focus_code']??'')!==$focus)continue;
            if((string)($note['status']??'adopted')!=='active')throw new \RuntimeException('Правило уже исключено из действующего стандарта.');
            $found=true;$note['validation_status']=$decision==='confirm'?'confirmed':'rework';
            $note['validated_revision']=$revision;$note['validated_at']=$now;$note['validated_by']=$userId;
            if($decision==='rework'){$note['status']='adopted';$note['updated_at']=$now;}
            break;
        }unset($note);
        if(!$found)throw new \RuntimeException('Правило этой редакции не найдено.');
        $row['practice_methodology_notes']=$notes;

        $entry=[
            'revision'=>$revision,'focus_code'=>$focus,'focus_title'=>(string)($snapshot['focus_title']??''),
            'decision'=>$decision,'decision_label'=>$decision==='confirm'?'Закреплена':'На доработку',
            'at'=>$now,'user_id'=>$userId,'impact'=>$snapshot,
        ];
        $decisions[]=$entry;$row['practice_standard_decisions']=array_slice($decisions,-40);
        if($decision==='rework'){
            $row['practice_standard_revision']=$revision+1;
            $events=is_array($row['practice_standard_events']??null)?array_values($row['practice_standard_events']):[];
            $event=[
                'focus_code'=>$focus,'action'=>'rolled_back_after_review','at'=>$now,'user_id'=>$userId,
                'revision'=>(int)$row['practice_standard_revision'],'reviewed_revision'=>$revision,
            ];
            if($nowUtc!=='')$event['at_utc']=$nowUtc;
            $events[]=$event;
            $row['practice_standard_events']=array_slice($events,-40);
        }
        return ['script'=>$row,'reused'=>false,'decision'=>$entry];
    }

    public static function decide(string $scriptId,string $decision,int $expectedRevision=0): array {
        if(!self::canManage())throw new \RuntimeException('Решение по редакции доступно организатору или партнёру.');
        if(!in_array($decision,['confirm','rework'],true))throw new \InvalidArgumentException('Неизвестное решение по редакции.');
        $current=SalesScriptService::find($scriptId);if(!$current)throw new \InvalidArgumentException('Методика не найдена.');
        if($expectedRevision>0){
            $existing=self::findDecision($current,$expectedRevision);
            if($existing)return ['script'=>$current,'reused'=>true,'decision'=>$existing];
            if((int)($current['practice_standard_revision']??0)!==$expectedRevision)throw new \RuntimeException('Редакция стандарта изменилась. Обновите страницу перед решением.');
        }
        $state=self::dashboard($scriptId);if(empty($state['available']))throw new \RuntimeException('Нет редакции с контрольными данными для решения.');
        if($expectedRevision>0&&(int)($state['revision']??0)!==$expectedRevision)throw new \RuntimeException('Редакция стандарта изменилась. Обновите страницу перед решением.');
        if(!empty($state['decision']))return ['script'=>SalesScriptService::find($scriptId),'reused'=>true,'decision'=>$state['decision']];
        $permissions=(array)($state['permissions']??[]);
        if(empty($permissions[$decision]))throw new \RuntimeException((string)($permissions['reason']??'Для этого решения пока недостаточно данных.'));

        $impact=(array)$state['impact'];$revision=(int)$state['revision'];$focus=(string)($impact['focus_code']??'');
        $snapshot=self::snapshot($impact);$now=current_time('mysql');$nowUtc=current_time('mysql',true);$userId=get_current_user_id();$entry=null;$reused=false;
        $script=SalesScriptService::mutate($scriptId,static function(array $row) use($decision,$revision,$focus,$snapshot,$now,$nowUtc,$userId,&$entry,&$reused): array {
            $result=self::applyDecision($row,$decision,$revision,$focus,$snapshot,$now,$userId,$nowUtc);
            $entry=$result['decision'];$reused=$result['reused'];
            return $result['script'];
        });
        return ['script'=>$script,'reused'=>$reused,'decision'=>$entry];
    }
}
