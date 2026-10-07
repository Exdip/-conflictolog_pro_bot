<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class AgreementValidator {
    private static function decode(?string $json): mixed {
        if ($json === null || $json === '') { return null; }
        try { return json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return null; }
    }
    private static function same(mixed $a, mixed $b): bool { return wp_json_encode($a) === wp_json_encode($b); }
    private static function compare(string $op, mixed $left, mixed $right): bool {
        if (is_numeric($left) && is_numeric($right)) { $left=(float)$left; $right=(float)$right; }
        return match($op) { 'lt'=>$left<$right, 'lte'=>$left<=$right, 'gt'=>$left>$right, 'gte'=>$left>=$right, 'eq'=>$left==$right, default=>false };
    }
    private static function evalCondition(mixed $condition, array $values): bool {
        if (!is_array($condition)) { return false; }
        if (isset($condition['all']) && is_array($condition['all'])) {
            foreach ($condition['all'] as $part) { if (!self::evalCondition($part, $values)) { return false; } }
            return true;
        }
        if (isset($condition['any']) && is_array($condition['any'])) {
            foreach ($condition['any'] as $part) { if (self::evalCondition($part, $values)) { return true; } }
            return false;
        }
        foreach (['lt','lte','gt','gte','eq'] as $op) {
            if (!isset($condition[$op]) || !is_array($condition[$op]) || count($condition[$op]) !== 2) { continue; }
            [$left,$right] = $condition[$op];
            if (is_array($left) && isset($left['item'])) {
                $code=(string)$left['item']; if (!array_key_exists($code,$values)) { return false; } $left=$values[$code];
            }
            return self::compare($op,$left,$right);
        }
        return false;
    }
    private static function requirementSatisfied(array $action, array $values): bool {
        $type=(string)($action['type']??'');
        if ($type === 'require') {
            foreach (['lt','lte','gt','gte','eq'] as $op) {
                if (isset($action[$op])) { return self::evalCondition([$op=>$action[$op]],$values); }
            }
            return false;
        }
        if ($type === 'require_any' && is_array($action['conditions']??null)) {
            foreach ($action['conditions'] as $part) { if (self::evalCondition($part,$values)) { return true; } }
            return false;
        }
        if ($type === 'require_all' && is_array($action['conditions']??null)) {
            foreach ($action['conditions'] as $part) { if (!self::evalCondition($part,$values)) { return false; } }
            return true;
        }
        if ($type === 'require_items' && is_array($action['items']??null)) {
            foreach ($action['items'] as $code) {
                if (!is_string($code) || $code==='' || !array_key_exists($code,$values) || $values[$code]===null || $values[$code]==='') { return false; }
            }
            return true;
        }
        return true;
    }

    private static function normalize(mixed $value, array $item): mixed {
        if ($value === null) { return null; }
        $type=(string)($item['value_type']??''); $unit=(string)($item['unit']??'');
        if (in_array($type,['integer','int','money','percent','number','decimal'],true) || in_array($unit,['RUB','percent','months','days'],true)) {
            if (!is_numeric($value)) { throw new \UnexpectedValueException('Agreement item requires a numeric value.'); }
            $number=(float)$value;
            if ($number < 0 || ($unit==='percent' && $number>100)) { throw new \UnexpectedValueException('Agreement item value is invalid.'); }
            return abs($number-round($number))<0.000001 ? (int)round($number) : $number;
        }
        if (in_array($type,['bool','boolean'],true)) { return filter_var($value,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE) ?? false; }
        if (is_scalar($value)) { return trim((string)$value); }
        throw new \UnexpectedValueException('Agreement item value shape is invalid.');
    }
    private static function selectedValue(array $state): mixed {
        $current=self::decode($state['current_value_json']??null);
        if (!is_array($current)) { return null; }
        if (isset($current['agreed']['value'])) { return $current['agreed']['value']; }
        if (isset($current['acceptance_candidate']['value'])) { return $current['acceptance_candidate']['value']; }
        $offers=is_array($current['offers']??null)?$current['offers']:[];
        $by=(string)($state['proposed_by']??'');
        if ($by!=='' && isset($offers[$by]['value'])) { return $offers[$by]['value']; }
        $values=[]; foreach (['player','opponent'] as $side) { if (isset($offers[$side]['value'])) { $values[]=$offers[$side]['value']; } }
        if (count($values)===1) { return $values[0]; }
        if (count($values)===2 && self::same($values[0],$values[1])) { return $values[0]; }
        return null;
    }

    public function buildDraft(int $sessionId): array {
        global $wpdb;
        $session=Access::session($sessionId);
        $itemsTable=Schema::table('items'); $stateTable=Schema::table('item_state');
        $rows=(array)$wpdb->get_results($wpdb->prepare(
            "SELECT i.*,s.status AS state_status,s.current_value_json,s.proposed_by,s.bundle_key FROM `$itemsTable` i INNER JOIN `$stateTable` s ON s.item_id=i.id WHERE s.session_id=%d AND i.scenario_version_id=%d ORDER BY i.sort_order ASC,i.id ASC",
            $sessionId,(int)$session['scenario_version_id']
        ),ARRAY_A);
        $package=[]; $missing=[];
        foreach($rows as $row){
            $value=self::selectedValue($row);
            if ($value!==null) { $value=self::normalize($value,$row); }
            $entry=['item_id'=>(int)$row['id'],'code'=>(string)$row['code'],'title'=>(string)$row['title'],'value'=>$value,'unit'=>(string)$row['unit'],
                'required'=>(bool)$row['required_for_agreement'],'status'=>(string)$row['state_status'],'bundle_key'=>(string)$row['bundle_key']];
            $package[]=$entry;
            if ($entry['required'] && $value===null) { $missing[]=['item_id'=>$entry['item_id'],'code'=>$entry['code'],'title'=>$entry['title']]; }
        }
        $formal=$this->validatePackage($sessionId,$package,false,false);
        return ['package'=>$package,'missing'=>$missing,'can_propose'=>empty($missing)&&empty($formal['blocking']),'blocking'=>$formal['blocking'],
            'warnings'=>$formal['warnings'],'red_line_breached'=>$formal['red_line_breached'],'state_revision'=>(int)$session['state_revision']];
    }

    public function validatePackage(int $sessionId, array $package, bool $requireComplete=true, bool $enforceOpponentHardConstraints=true): array {
        global $wpdb;
        $session=Access::session($sessionId); $itemsTable=Schema::table('items');
        $items=(array)$wpdb->get_results($wpdb->prepare("SELECT * FROM `$itemsTable` WHERE scenario_version_id=%d ORDER BY sort_order ASC,id ASC",(int)$session['scenario_version_id']),ARRAY_A);
        $map=[]; foreach($items as $item){$map[(string)$item['code']]=$item;}
        $values=[]; $seen=[]; $missing=[]; $blocking=[]; $warnings=[]; $redLine=false;
        foreach($package as $entry){
            if(!is_array($entry))continue; $code=(string)($entry['code']??'');
            if($code===''||!isset($map[$code])||isset($seen[$code])){continue;} $seen[$code]=true; $item=$map[$code];
            $value=self::normalize($entry['value']??null,$item); if($value===null){continue;} $values[$code]=$value;
            $playerBoundary=self::decode($item['player_boundary_json']??null); if(is_array($playerBoundary)){
                if(isset($playerBoundary['min'])&&is_numeric($playerBoundary['min'])&&is_numeric($value)&&(float)$value<(float)$playerBoundary['min'])$redLine=true;
                if(isset($playerBoundary['max'])&&is_numeric($playerBoundary['max'])&&is_numeric($value)&&(float)$value>(float)$playerBoundary['max'])$redLine=true;
            }
            if($enforceOpponentHardConstraints){
                $opponentBoundary=self::decode($item['opponent_boundary_json']??null); if(is_array($opponentBoundary)){
                    if(isset($opponentBoundary['min'])&&is_numeric($opponentBoundary['min'])&&is_numeric($value)&&(float)$value<(float)$opponentBoundary['min'])$blocking[]=['type'=>'hard_constraint','item_code'=>$code,'message'=>'Package violates an opponent hard constraint.'];
                    if(isset($opponentBoundary['max'])&&is_numeric($opponentBoundary['max'])&&is_numeric($value)&&(float)$value>(float)$opponentBoundary['max'])$blocking[]=['type'=>'hard_constraint','item_code'=>$code,'message'=>'Package violates an opponent hard constraint.'];
                }
            }
        }
        foreach($items as $item){if((int)$item['required_for_agreement']===1&&!array_key_exists((string)$item['code'],$values))$missing[]=['item_id'=>(int)$item['id'],'code'=>(string)$item['code'],'title'=>(string)$item['title']];}
        if($requireComplete&&$missing)$blocking[]=['type'=>'missing_required','items'=>$missing,'message'=>'Не все обязательные условия определены.'];

        $rulesTable=Schema::table('rules');
        $rules=(array)$wpdb->get_results($wpdb->prepare("SELECT code,rule_type,condition_json,action_json FROM `$rulesTable` WHERE scenario_version_id=%d AND is_active=1 ORDER BY priority DESC,id ASC",(int)$session['scenario_version_id']),ARRAY_A);
        foreach($rules as $rule){
            $condition=self::decode($rule['condition_json']??null); $action=self::decode($rule['action_json']??null); if(!is_array($action))continue;
            $type=(string)($action['type']??'');
            if($type==='require_items'){
                if(!self::requirementSatisfied($action,$values)){
                    $blocking[]=['type'=>'missing_required','rule_code'=>(string)$rule['code'],'items'=>(array)($action['items']??[]),'message'=>'Не все обязательные условия сценария определены.'];
                }
                continue;
            }
            if($type==='flag_breach'&&(string)($action['side']??'')==='player'&&self::evalCondition($condition,$values)){$redLine=true;continue;}
            if($type==='block'&&self::evalCondition($condition,$values)){
                $blocking[]=['type'=>'hard_constraint','rule_code'=>(string)$rule['code'],'message'=>'Пакет нарушает обязательное ограничение сценария.'];
                continue;
            }
            if(in_array($type,['require','require_any','require_all'],true)&&self::evalCondition($condition,$values)&&!self::requirementSatisfied($action,$values)){
                $blocking[]=['type'=>'dependency','rule_code'=>(string)$rule['code'],'message'=>'Пакет не выполняет обязательную зависимость условий.'];
            }
        }
        if($redLine)$warnings[]=['type'=>'player_red_line','message'=>'Пакет пересекает учебную красную линию игрока.'];
        return ['valid'=>empty($blocking),'values'=>$values,'missing'=>$missing,'blocking'=>$blocking,'warnings'=>$warnings,'red_line_breached'=>$redLine];
    }
}
