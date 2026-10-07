<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

/**
 * Internal post-game feasibility analysis for the negotiation space.
 *
 * This service is intentionally data-driven and is never exposed to the live
 * player snapshot. It does not change scenario rules, boundaries or state.
 */
final class ZopaService {
    private const MAX_VALUES_PER_ITEM = 5;
    private const MAX_PACKAGES = 12000;

    private static function scalar(mixed $value): bool {
        return is_string($value) || is_int($value) || is_float($value) || is_bool($value);
    }

    private static function number(mixed $value): ?float {
        return is_numeric($value) ? (float)$value : null;
    }

    private static function compare(string $op, mixed $left, mixed $right): bool {
        if (is_numeric($left) && is_numeric($right)) { $left=(float)$left; $right=(float)$right; }
        return match ($op) {
            'lt' => $left < $right,
            'lte' => $left <= $right,
            'gt' => $left > $right,
            'gte' => $left >= $right,
            'eq' => $left == $right,
            default => false,
        };
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
                $code=(string)$left['item'];
                if (!array_key_exists($code,$values)) { return false; }
                $left=$values[$code];
            }
            if (is_array($right) && isset($right['item'])) {
                $code=(string)$right['item'];
                if (!array_key_exists($code,$values)) { return false; }
                $right=$values[$code];
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

    private static function packageAllowed(array $values, array $rules): bool {
        foreach ($rules as $rule) {
            $condition=is_array($rule['condition']??null)?$rule['condition']:[];
            $action=is_array($rule['action']??null)?$rule['action']:[];
            $type=(string)($action['type']??'');
            if ($type === 'require_items') {
                if (!self::requirementSatisfied($action,$values)) { return false; }
                continue;
            }
            if (!self::evalCondition($condition,$values)) { continue; }
            if ($type === 'block') { return false; }
            if (in_array($type,['require','require_any','require_all'],true) && !self::requirementSatisfied($action,$values)) { return false; }
        }
        return true;
    }

    private static function allowedByBoundary(array $item, mixed $value, string $side): bool {
        $boundary=is_array($item[$side.'_boundary']??null)?$item[$side.'_boundary']:[];
        if (!$boundary) { return true; }
        $n=self::number($value);
        if (isset($boundary['min']) && is_numeric($boundary['min']) && ($n===null || $n < (float)$boundary['min'])) { return false; }
        if (isset($boundary['max']) && is_numeric($boundary['max']) && ($n===null || $n > (float)$boundary['max'])) { return false; }
        if (isset($boundary['allowed']) && is_array($boundary['allowed']) && !in_array($value,$boundary['allowed'],true)) { return false; }
        if (isset($boundary['forbidden']) && is_array($boundary['forbidden']) && in_array($value,$boundary['forbidden'],true)) { return false; }
        if (isset($boundary['hard_boundary']) && $boundary['hard_boundary'] !== null && self::scalar($boundary['hard_boundary']) && $value != $boundary['hard_boundary']) { return false; }
        return true;
    }

    private static function itemCodesFromNode(mixed $node, array &$codes): void {
        if (!is_array($node)) { return; }
        if (isset($node['item']) && is_string($node['item'])) { $codes[$node['item']]=true; }
        if (isset($node['items']) && is_array($node['items'])) {
            foreach ($node['items'] as $code) { if (is_string($code) && $code!=='') { $codes[$code]=true; } }
        }
        foreach ($node as $child) { self::itemCodesFromNode($child,$codes); }
    }

    private static function constantsForItem(mixed $node, string $itemCode, array &$values): void {
        if (!is_array($node)) { return; }
        foreach (['lt','lte','gt','gte','eq'] as $op) {
            if (!isset($node[$op]) || !is_array($node[$op]) || count($node[$op])!==2) { continue; }
            [$left,$right]=$node[$op];
            if (is_array($left) && (string)($left['item']??'')===$itemCode && self::scalar($right)) { $values[]=$right; }
            if (is_array($right) && (string)($right['item']??'')===$itemCode && self::scalar($left)) { $values[]=$left; }
        }
        foreach ($node as $child) { self::constantsForItem($child,$itemCode,$values); }
    }

    private static function candidateDomain(array $item, array $rules): array {
        $type=(string)($item['value_type']??'');
        $config=is_array($item['config']??null)?$item['config']:[];
        $playerTarget=is_array($item['player_target']??null)?$item['player_target']:[];
        $opponentTarget=is_array($item['opponent_target']??null)?$item['opponent_target']:[];
        $playerBoundary=is_array($item['player_boundary']??null)?$item['player_boundary']:[];
        $opponentBoundary=is_array($item['opponent_boundary']??null)?$item['opponent_boundary']:[];

        if (in_array($type,['bool','boolean'],true)) { return ['values'=>[false,true],'complete'=>true]; }
        if ($type === 'select') {
            $options=is_array($config['options']??null)?array_values($config['options']):[];
            $complete=(bool)$options;
            if (count($options)>self::MAX_VALUES_PER_ITEM) { $options=array_slice($options,0,self::MAX_VALUES_PER_ITEM);$complete=false; }
            return ['values'=>$options,'complete'=>$complete];
        }

        $numeric=in_array($type,['integer','int','money','percent','number','decimal'],true) || in_array((string)($item['unit']??''),['RUB','percent','months','days','weeks','people','tasks'],true);
        $values=[];
        foreach ([$playerTarget,$opponentTarget,$playerBoundary,$opponentBoundary,$config] as $source) {
            foreach (['target','min','max','opening'] as $key) { if (isset($source[$key]) && self::scalar($source[$key])) { $values[]=$source[$key]; } }
            if (isset($source['allowed']) && is_array($source['allowed'])) { foreach ($source['allowed'] as $v) { if (self::scalar($v)) { $values[]=$v; } } }
        }
        foreach ($rules as $rule) {
            self::constantsForItem($rule['condition']??[],(string)$item['code'],$values);
            self::constantsForItem($rule['action']??[],(string)$item['code'],$values);
        }

        if ($numeric) {
            $step=(float)($config['step']??1); if ($step<=0) { $step=1; }
            $expanded=[];
            foreach ($values as $v) {
                if (!is_numeric($v)) { continue; }
                $n=(float)$v;$expanded[]=$n;$expanded[]=$n-$step;$expanded[]=$n+$step;
            }
            $values=$expanded;
            if ((string)($item['unit']??'')==='percent') { $values[] = 0; $values[] = 100; }
            $values=array_values(array_unique(array_map(fn($v)=>(string)(float)$v,$values)));
            $values=array_map('floatval',$values);
            sort($values,SORT_NUMERIC);
            $values=array_values(array_filter($values,function($v)use($item){
                if ($v<0) { return false; }
                if ((string)($item['unit']??'')==='percent' && $v>100) { return false; }
                return true;
            }));
            if (!$values) { $values=[0.0]; }
            $priority=[];
            foreach ([$playerTarget['target']??null,$playerTarget['min']??null,$playerTarget['max']??null,$opponentTarget['target']??null,$opponentTarget['min']??null,$opponentTarget['max']??null,$playerBoundary['min']??null,$playerBoundary['max']??null,$opponentBoundary['min']??null,$opponentBoundary['max']??null] as $v) {
                if (is_numeric($v)) { $priority[]=(float)$v; }
            }
            $ordered=[];
            foreach (array_merge($priority,$values) as $v) { $k=(string)(float)$v; if (!isset($ordered[$k])) { $ordered[$k]=(float)$v; } }
            $complete=count($ordered)<=self::MAX_VALUES_PER_ITEM;
            $ordered=array_values($ordered);
            if (count($ordered)>self::MAX_VALUES_PER_ITEM) { $ordered=array_slice($ordered,0,self::MAX_VALUES_PER_ITEM); }
            return ['values'=>$ordered,'complete'=>$complete];
        }

        $values=array_values(array_unique(array_filter($values,fn($v)=>self::scalar($v)),SORT_REGULAR));
        if (!$values) { $values=['']; }
        $complete=false;
        if (count($values)>self::MAX_VALUES_PER_ITEM) { $values=array_slice($values,0,self::MAX_VALUES_PER_ITEM); }
        return ['values'=>$values,'complete'=>$complete];
    }

    private static function directBlockingItems(array $items): array {
        $out=[];
        foreach ($items as $item) {
            if (empty($item['required_for_agreement'])) { continue; }
            $p=is_array($item['player_boundary']??null)?$item['player_boundary']:[];
            $o=is_array($item['opponent_boundary']??null)?$item['opponent_boundary']:[];
            $pMin=self::number($p['min']??null);$pMax=self::number($p['max']??null);$oMin=self::number($o['min']??null);$oMax=self::number($o['max']??null);
            $disjoint=($pMin!==null&&$oMax!==null&&$pMin>$oMax)||($oMin!==null&&$pMax!==null&&$oMin>$pMax);
            if (!$disjoint && isset($p['allowed'],$o['allowed']) && is_array($p['allowed']) && is_array($o['allowed'])) { $disjoint=!array_intersect($p['allowed'],$o['allowed']); }
            if ($disjoint) { $out[]=['item_code'=>(string)$item['code'],'title'=>(string)($item['title']??$item['code']),'reason'=>'Границы сторон по этому условию не пересекаются.']; }
        }
        return $out;
    }

    private static function findFeasible(array $items, array $rules, bool $respectPlayerBoundary): array {
        $relevant=[];$domains=[];$complete=true;
        $referenced=[]; foreach ($rules as $rule) { self::itemCodesFromNode($rule['condition']??[],$referenced);self::itemCodesFromNode($rule['action']??[],$referenced); }
        foreach ($items as $item) {
            $code=(string)($item['code']??'');
            if ($code==='' || (empty($item['required_for_agreement']) && !isset($referenced[$code]))) { continue; }
            $domain=self::candidateDomain($item,$rules); if (!$domain['values']) { $complete=false;continue; }
            $relevant[]=$item;$domains[$code]=$domain['values'];$complete=$complete&&$domain['complete'];
        }
        usort($relevant,fn($a,$b)=>count($domains[(string)$a['code']]??[])<=>count($domains[(string)$b['code']]??[]));
        $checked=0;$hitLimit=false;$found=null;$bestPackage=null;$bestScore=null;$feasibleCount=0;
        $walk=function(int $index,array $values)use(&$walk,&$checked,&$hitLimit,&$found,&$bestPackage,&$bestScore,&$feasibleCount,$relevant,$domains,$rules,$respectPlayerBoundary,$items){
            if ($hitLimit) { return; }
            if ($checked>=self::MAX_PACKAGES) { $hitLimit=true;return; }
            if ($index>=count($relevant)) {
                $checked++;
                if (self::packageAllowed($values,$rules)) {
                    $feasibleCount++;
                    if($found===null)$found=$values;
                    $score=self::playerAchievement($items,$values);
                    if($bestScore===null||$score>$bestScore){$bestScore=$score;$bestPackage=$values;}
                }
                return;
            }
            $item=$relevant[$index];$code=(string)$item['code'];
            foreach ($domains[$code] as $value) {
                if ($respectPlayerBoundary && !self::allowedByBoundary($item,$value,'player')) { continue; }
                if (!self::allowedByBoundary($item,$value,'opponent')) { continue; }
                $next=$values;$next[$code]=$value;$walk($index+1,$next);if($hitLimit)return;
            }
        };
        $walk(0,[]);
        return [
            'found'=>$found!==null,'package'=>$found,'best_package'=>$bestPackage,'best_score'=>$bestScore,'feasible_count'=>$feasibleCount,
            'complete'=>$complete&&!$hitLimit,'checked'=>$checked,'hit_limit'=>$hitLimit,
        ];
    }

    private static function playerAchievement(array $items, array $package): float {
        $sum=0.0;$weight=0.0;
        foreach ($items as $item) {
            $code=(string)($item['code']??''); if (!array_key_exists($code,$package)) { continue; }
            $w=max(0.1,(float)($item['importance_weight']??1));$score=60.0;$value=$package[$code];
            $target=is_array($item['player_target']??null)?$item['player_target']:[];$dir=(string)($item['player_preference_direction']??'');
            if (isset($target['allowed']) && is_array($target['allowed'])) { $score=in_array($value,$target['allowed'],true)?100.0:50.0; }
            elseif (isset($target['target'])) {
                if ($value==$target['target']) { $score=100.0; }
                elseif (is_numeric($value)&&is_numeric($target['target'])) {
                    $v=(float)$value;$t=(float)$target['target'];
                    if (in_array($dir,['higher','higher_better'],true)) { $score=$v>=$t?100.0:max(0.0,60.0+40.0*($t==0?0:$v/$t)); }
                    elseif (in_array($dir,['lower','lower_better'],true)) { $score=$v<=$t?100.0:max(0.0,100.0-min(50.0,abs($v-$t)*4.0)); }
                    else { $score=max(40.0,100.0-min(60.0,abs($v-$t)*4.0)); }
                } else { $score=50.0; }
            } elseif (isset($target['min'])&&is_numeric($target['min'])&&is_numeric($value)) { $score=(float)$value>=(float)$target['min']?100.0:60.0; }
            elseif (isset($target['max'])&&is_numeric($target['max'])&&is_numeric($value)) { $score=(float)$value<=(float)$target['max']?100.0:60.0; }
            $sum+=$score*$w;$weight+=$w;
        }
        return $weight>0?$sum/$weight:50.0;
    }

    private static function packageValues(mixed $package): array {
        if(!is_array($package))return [];
        $values=[];
        foreach($package as $key=>$entry){
            if(is_array($entry)&&array_key_exists('value',$entry)){
                $code=(string)($entry['code']??$key);if($code!=='')$values[$code]=$entry['value'];
            }elseif(is_string($key)&&self::scalar($entry))$values[$key]=$entry;
        }
        return $values;
    }

    private static function compensationPaths(array $rules, array $itemTitles): array {
        $out=[];
        foreach ($rules as $rule) {
            $action=is_array($rule['action']??null)?$rule['action']:[];$type=(string)($action['type']??'');
            if (!in_array($type,['require','require_any','require_all'],true)) { continue; }
            $codes=[];self::itemCodesFromNode($action,$codes);if(!$codes)continue;
            $titles=[];foreach(array_keys($codes) as $code)$titles[]=$itemTitles[$code]??$code;
            $out[]=['rule_code'=>(string)($rule['code']??''),'items'=>array_keys($codes),'titles'=>$titles,'description'=>'Компенсация возможна через связанный пакет условий: '.implode(', ',$titles).'.'];
        }
        return $out;
    }

    public function analyze(array $context): array {
        $items=(array)($context['items']??[]);$rules=(array)($context['scenario_rules']??[]);
        $titles=[];foreach($items as $item)$titles[(string)($item['code']??'')]=(string)($item['title']??$item['code']??'');
        $blocking=self::directBlockingItems($items);
        $mutual=self::findFeasible($items,$rules,true);
        $structural=self::findFeasible($items,$rules,false);
        $paths=self::compensationPaths($rules,$titles);
        $alternative=is_array($context['player_card']['alternative']??null)?$context['player_card']['alternative']:(is_array($context['player_alternative']??null)?$context['player_alternative']:[]);
        $bestAlternative=AlternativeValueService::compare(isset($mutual['best_score'])?(float)$mutual['best_score']:null,$alternative,!empty($mutual['complete']));
        $finalAlternative=['available'=>false,'relation'=>'unknown'];
        $finalPackage=self::packageValues($context['final_agreement']['package']??[]);
        if($finalPackage)$finalAlternative=AlternativeValueService::compare(self::playerAchievement($items,$finalPackage),$alternative,true);

        $feasibility='conditional';$reason='Формальные данные не позволяют доказать ни полное отсутствие, ни безусловное наличие взаимоприемлемого пакета.';
        if ($mutual['found']) {
            $activatedDependency=false;
            foreach($rules as $rule){$action=is_array($rule['action']??null)?$rule['action']:[];if(in_array((string)($action['type']??''),['require','require_any','require_all'],true)&&self::evalCondition($rule['condition']??[],$mutual['package'])){$activatedDependency=true;break;}}
            $feasibility=$activatedDependency?'conditional':'open';
            $reason=$activatedDependency?'Взаимоприемлемый пакет существует, но требует увязки нескольких условий.':'По формальным границам и ограничениям существует взаимоприемлемый пакет.';
        } elseif ($blocking || ($mutual['complete'] && $structural['complete'] && !$structural['found'])) {
            $feasibility='closed';
            $reason=$blocking?'По обязательным границам сторон есть прямое несовпадение.':'При проверке формальных границ и правил взаимоприемлемый пакет не найден.';
        } elseif ($structural['found'] && !$mutual['found']) {
            $feasibility='closed';
            $reason='Технически допустимый пакет существует, но он выходит за учебные границы игрока.';
        }

        $attractiveness='unknown';$basis='insufficient_data';
        if ($mutual['found']) {
            $achievement=(float)($mutual['best_score']??self::playerAchievement($items,$mutual['package']));
            if(($bestAlternative['relation']??'')==='worse_than_alternative'){$attractiveness='worse_than_alternative';$basis='player_alternative';}
            else{$attractiveness=$achievement>=80?'good':'marginal';$basis='player_targets';}
        } elseif ($structural['found']) { $attractiveness='outside_player_boundary';$basis='player_boundary'; }

        $publicSummary=match($feasibility){
            'open'=>'У сторон были условия, при которых можно было договориться.',
            'conditional'=>'Договориться было возможно, если связать несколько условий в единый пакет.',
            'closed'=>'По заданным ограничениям взаимоприемлемый вариант не найден.',
            default=>'Не удалось однозначно определить, можно ли было договориться.',
        };
        if($attractiveness==='outside_player_boundary')$publicSummary.=' Формально возможные варианты выходили за ваши допустимые границы.';
        $bestPublic=AlternativeValueService::publicProjection($bestAlternative,'best_mutual');
        $finalPublic=AlternativeValueService::publicProjection($finalAlternative,'final_agreement');
        if($finalPublic&&($finalAlternative['relation']??'')!=='unknown')$publicSummary.=' '.$finalPublic['summary'];
        elseif($bestPublic&&($bestAlternative['relation']??'')!=='unknown')$publicSummary.=' '.$bestPublic['summary'];

        return [
            'feasibility'=>$feasibility,
            'attractiveness'=>$attractiveness,
            'attractiveness_basis'=>$basis,
            'blocking_items'=>$blocking,
            'compensation_paths'=>$paths,
            'sample_package'=>$mutual['package']??null,
            'best_mutual_package'=>$mutual['best_package']??null,
            'structural_sample_package'=>$structural['package']??null,
            'alternative_comparison'=>['best_mutual'=>$bestAlternative,'final_agreement'=>$finalAlternative],
            'search'=>['mutual'=>$mutual,'structural'=>$structural],
            'public'=>[
                'feasibility'=>$feasibility,
                'attractiveness'=>$attractiveness,
                'summary'=>$publicSummary,
                'alternative_comparison'=>['best_mutual'=>$bestPublic,'final_agreement'=>$finalPublic],
                'blocking_items'=>array_map(fn($x)=>['item_code'=>$x['item_code'],'title'=>$x['title'],'reason'=>$x['reason']],$blocking),
                'compensation_paths'=>array_map(fn($x)=>['rule_code'=>$x['rule_code'],'titles'=>$x['titles'],'description'=>$x['description']],$paths),
            ],
        ];
    }

}
