<?php
namespace CKM\EffectiveSales;
if (!defined('ABSPATH')) { exit; }

final class SalesDevelopmentCenterService {
    public const PASS_SCORE=70;

    public static function criteria(): array {
        $out=[];
        foreach(SalesScriptClientService::evaluationRules() as $row){
            if(!is_array($row))continue;
            $rubric=is_array($row['rubric_json']??null)?$row['rubric_json']:[];
            $out[]=[
                'code'=>(string)($row['code']??''),
                'title'=>(string)($row['title']??''),
                'weight'=>(float)($row['weight']??0),
                'description'=>(string)($rubric['description']??''),
            ];
        }
        return $out;
    }

    public static function selectScript(string $id=''): ?array {
        $items=SalesScriptService::all();
        if($id!==''){
            foreach($items as $item)if(is_array($item)&&(string)($item['id']??'')===$id)return $item;
        }
        foreach($items as $item){
            if(!is_array($item))continue;
            if((string)($item['status']??'draft')==='approved'&&(int)($item['ai_client_scenario_id']??0)>0)return $item;
        }
        foreach($items as $item){
            if(is_array($item)&&(string)($item['status']??'draft')==='approved')return $item;
        }
        return $items&&is_array($items[0]??null)?$items[0]:null;
    }

    private static function attempts(array $script,string $mode,int $limit=12): array {
        $scenarioId=(int)($script['ai_client_scenario_id']??0);
        if($scenarioId<1)return [];
        try{$rows=\CKM\NegotiationMaster\ProductCatalog::attempts($scenarioId,30);}catch(\Throwable){return [];}
        $rows=array_values(array_filter($rows,static fn(array $r): bool=>(string)($r['mode']??'training')===$mode));
        return array_slice($rows,0,max(1,min(30,$limit)));
    }

    private static function completed(array $row): bool {
        return str_starts_with((string)($row['status']??''),'completed_')||(string)($row['evaluation_status']??'')==='completed';
    }

    private static function anyCompleted(array $rows): bool {
        foreach($rows as $row)if(is_array($row)&&self::completed($row))return true;
        return false;
    }

    private static function latestReadyResult(array $attempts): ?array {
        $builder=new \CKM\NegotiationMaster\ResultBuilder();
        foreach($attempts as $attempt){
            if(!is_array($attempt)||!self::completed($attempt))continue;
            try{
                $result=$builder->build((int)$attempt['id']);
                if(!empty($result['ready']))return ['attempt'=>$attempt,'result'=>$result];
            }catch(\Throwable){}
        }
        return null;
    }

    public static function workspace(array $script): array {
        $training=self::attempts($script,'training');
        $checks=self::attempts($script,'exam');
        $latestCheck=self::latestReadyResult($checks);
        $score=$latestCheck?(float)($latestCheck['result']['final_score']??0):null;
        $criteria=$latestCheck?(array)($latestCheck['result']['criteria']??[]):[];
        $lowest=null;
        foreach($criteria as $row){
            if(!is_array($row)||$row['raw_score']===null)continue;
            if($lowest===null||(float)$row['raw_score']<(float)$lowest['raw_score'])$lowest=$row;
        }
        return [
            'script'=>$script,
            'playbook'=>SalesScriptService::playbook((string)($script['situation_class']??'Диагностика')),
            'criteria'=>self::criteria(),
            'scenario_id'=>(int)($script['ai_client_scenario_id']??0),
            'methodology_ready'=>(string)($script['status']??'draft')==='approved',
            'polygon_ready'=>(int)($script['ai_client_scenario_id']??0)>0,
            'training_attempts'=>$training,
            'check_attempts'=>$checks,
            'training_done'=>self::anyCompleted($training),
            'latest_check'=>$latestCheck,
            'pass_score'=>self::PASS_SCORE,
            'passed'=>$score!==null&&$score>=self::PASS_SCORE,
            'lowest_criterion'=>$lowest,
        ];
    }

    public static function progress(array $workspace): array {
        return [
            ['key'=>'methodology','title'=>'Методика','done'=>!empty($workspace['methodology_ready'])],
            ['key'=>'polygon','title'=>'Полигон продаж','done'=>!empty($workspace['training_done'])],
            ['key'=>'check','title'=>'Проверка','done'=>!empty($workspace['latest_check'])],
            ['key'=>'result','title'=>'Результат руководителю','done'=>!empty($workspace['latest_check'])],
        ];
    }
}
