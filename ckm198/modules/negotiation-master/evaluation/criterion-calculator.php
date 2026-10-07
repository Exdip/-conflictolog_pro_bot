<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class CriterionCalculator {
    public function sourceType(array $rule): string {
        $declared = (string)($rule['evaluation_type'] ?? '');
        if (in_array($declared, ['php','ai','hybrid'], true)) { return $declared; }
        return match ((string)($rule['code'] ?? '')) {
            'economic_result' => 'php',
            'result_quality','interest_discovery','concession_exchange','boundary_protection','package_solution' => 'hybrid',
            'argumentation' => 'ai',
            default => 'ai',
        };
    }

    public function phpShare(array $rule): float {
        if (isset($rule['config']['php_share']) && is_numeric($rule['config']['php_share'])) {
            return max(0.0, min(1.0, (float)$rule['config']['php_share']));
        }
        return match ((string)($rule['code'] ?? '')) {
            'economic_result' => 1.0,
            'result_quality' => 0.75,
            'interest_discovery' => 0.60,
            'concession_exchange' => 0.70,
            'boundary_protection' => 0.80,
            'package_solution' => 0.60,
            'argumentation' => 0.0,
            default => 0.0,
        };
    }

    private static function packageMap(array $context): array {
        $map=[]; foreach ((array)($context['final_agreement']['package'] ?? []) as $e) { if (is_array($e) && isset($e['code'])) { $map[(string)$e['code']]=$e['value'] ?? null; } }
        return $map;
    }
    private static function clamp(float $v): float { return max(0.0,min(100.0,$v)); }
    private static function number(mixed $v): ?float { return is_numeric($v)?(float)$v:null; }

    private static function itemAchievement(array $item, mixed $value): float {
        $v=self::number($value); if ($v===null) return 0.0;
        $target=$item['player_target']??[]; $boundary=$item['player_boundary']??[]; $config=$item['config']??[];
        $dir=(string)($item['player_preference_direction']??'');
        $opening=self::number($config['opening']??null);
        if (in_array($dir,['higher','higher_better'],true)) {
            $t=self::number($target['min']??$target['target']??null); $b=self::number($boundary['min']??null);
            if ($t!==null && $v >= $t) return 100.0;
            if ($b!==null && $t!==null && $t>$b && $v >= $b) return 55.0 + 45.0*($v-$b)/($t-$b);
            if ($t!==null && $t>0) return self::clamp(55.0*$v/$t);
            if ($opening!==null && $opening>0) return self::clamp(100.0*$v/$opening);
        }
        if (in_array($dir,['lower','lower_better'],true)) {
            $t=self::number($target['max']??$target['target']??null); $b=self::number($boundary['max']??null);
            if ($t!==null && $v <= $t) return 100.0;
            if ($t!==null && $b!==null && $b>$t && $v <= $b) return 55.0 + 45.0*($b-$v)/($b-$t);
            $opp=self::number($item['opponent_target']['target']??null);
            if ($t!==null && $opp!==null && $opp>$t && $v<=$opp) return 55.0 + 45.0*($opp-$v)/($opp-$t);
            if ($t!==null && $v>$t) return self::clamp(100.0 - max(0.0,$v-$t)*4.0);
        }
        return 50.0;
    }

    public function phpScore(array $rule, array $context, array $noDeal): array {
        $code=(string)($rule['code']??'');
        return match($code) {
            'economic_result' => $this->economic($context,$noDeal),
            'result_quality' => $this->resultQuality($context),
            'interest_discovery' => $this->interests($context),
            'concession_exchange' => $this->concessions($context),
            'boundary_protection' => $this->boundary($context),
            'package_solution' => $this->package($context),
            default => ['raw_score'=>0.0,'explanation'=>'Формальная часть для этого критерия не задана.','evidence'=>[]],
        };
    }


    private function resultQuality(array $context): array {
        if (($context['session']['status']??'')!=='completed_agreement') return ['raw_score'=>0.0,'explanation'=>'Соглашение не достигнуто.','evidence'=>[]];
        $package=self::packageMap($context);$sum=0.0;$weight=0.0;$evidence=[];
        foreach((array)($context['items']??[]) as $item){
            $code=(string)($item['code']??'');if($code===''||!array_key_exists($code,$package))continue;
            $w=max(0.1,(float)($item['importance_weight']??1));$ach=self::itemAchievement($item,$package[$code]);
            $sum+=$ach*$w;$weight+=$w;$evidence[]=['item'=>$code,'achievement'=>round($ach,2),'value'=>$package[$code]];
        }
        $score=$weight>0?self::clamp($sum/$weight):0.0;
        if(!empty($context['completion']['red_line_breached']))$score=min($score,20.0);
        return ['raw_score'=>$score,'explanation'=>'Формальная оценка итогового пакета относительно целей и границ участника.','evidence'=>$evidence];
    }

    private function economic(array $context,array $noDeal): array {
        if (($context['session']['status']??'')!=='completed_agreement') {
            return ['raw_score'=>(float)($noDeal['economic_raw']??55),'explanation'=>(string)($noDeal['reason']??'Соглашение не достигнуто.'),'evidence'=>[]];
        }
        $package=self::packageMap($context); $weights=['price'=>12,'prepayment'=>5,'delivery_days'=>3,'service_months'=>3];
        $points=0.0;$max=23.0;$evidence=[];
        foreach((array)($context['items']??[]) as $item){$code=(string)($item['code']??'');if(!isset($weights[$code]))continue;$ach=self::itemAchievement($item,$package[$code]??null);$points += $weights[$code]*$ach/100.0;$evidence[]=['item'=>$code,'achievement'=>round($ach,2),'value'=>$package[$code]??null];}
        $validation=$context['final_agreement']['php_validation']['final_validation']??[];$dependency=empty($validation['blocking'])?2.0:0.0;$points+=$dependency;$max+=2.0;
        return ['raw_score'=>self::clamp($points/$max*100.0),'explanation'=>'Формальная оценка финального пакета по цене, предоплате, сроку, сервису и зависимостям.','evidence'=>$evidence];
    }

    private function interests(array $context): array {
        $facts=(array)($context['facts']??[]); if(!$facts)return ['raw_score'=>0.0,'explanation'=>'Нет данных о раскрытии интересов.','evidence'=>[]];
        $sum=0.0;$w=0.0;$ev=[]; foreach($facts as $f){$imp=max(0.1,(float)($f['importance']??1));$level=(int)($f['reveal_level']??0);$ratio=$level>=2?1.0:($level===1?0.5:0.0);$sum+=$ratio*$imp;$w+=$imp;$ev[]=['fact_code'=>$f['code']??'','reveal_level'=>$level,'message_id'=>$f['first_discovered_message_id']??null];}
        return ['raw_score'=>$w>0?self::clamp($sum/$w*100.0):0.0,'explanation'=>'Формальная доля частично и полностью раскрытых значимых фактов.','evidence'=>$ev];
    }

    private function concessions(array $context): array {
        $player=[];$opponent=[]; foreach((array)($context['events']??[]) as $e){$type=$e['event_type']??'';if(!in_array($type,['concession_made','conditional_concession'],true))continue;if(($e['actor']??'')==='player'){$player[]=$e;}else{$opponent[]=$e;}}
        if(!$player)return ['raw_score'=>65.0,'explanation'=>'У игрока не зафиксировано ценовых или иных ухудшающих его позицию уступок; бесплатные уступки не обнаружены.','evidence'=>[]];
        $conditional=count(array_filter($player,fn($e)=>($e['event_type']??'')==='conditional_concession'));$free=count($player)-$conditional;
        $reciprocal=0; foreach($player as $p){foreach($opponent as $o){if(abs((int)$o['sequence_no']-(int)$p['sequence_no'])<=5){$reciprocal++;break;}}}
        $score=45.0 + 20.0*($conditional/max(1,count($player))) + 25.0*min(1,$reciprocal/max(1,count($player))) - 25.0*min(1,$free/max(1,count($player)));
        return ['raw_score'=>self::clamp($score),'explanation'=>'Формальная оценка условности уступок и наличия встречного движения в близком переговорном эпизоде.','evidence'=>array_values(array_map(fn($e)=>(int)$e['message_id'],$player))];
    }

    private function boundary(array $context): array {
        $completion=$context['completion']??[];$breached=!empty($completion['red_line_breached']);$candidates=[];$statements=[];
        foreach((array)($context['events']??[]) as $e){if(($e['actor']??'')!=='player')continue;if(($e['event_type']??'')==='red_line_candidate')$candidates[]=(int)$e['message_id'];if(($e['event_type']??'')==='boundary_stated')$statements[]=(int)$e['message_id'];}
        if($breached)return ['raw_score'=>20.0,'explanation'=>'Финальное соглашение пересекло учебную красную линию игрока.','evidence'=>$candidates];
        $base=$candidates?65.0:90.0;if($statements)$base=min(100.0,$base+10.0);
        return ['raw_score'=>$base,'explanation'=>$candidates?'Риск пересечения границы возникал, но не был закреплён в финальном результате.':'Финального пересечения красной линии не зафиксировано.','evidence'=>array_values(array_unique(array_merge($candidates,$statements)))];
    }

    private function package(array $context): array {
        $packages=[];$items=[]; foreach((array)($context['events']??[]) as $e){if(($e['actor']??'')!=='player')continue;if(($e['event_type']??'')==='package_offer_made'){$packages[]=(int)$e['message_id'];foreach((array)($e['payload']['items']??[]) as $x)$items[(string)$x]=true;}if(($e['event_type']??'')==='item_proposed' && !empty($e['payload']['item_code']))$items[(string)$e['payload']['item_code']]=true;}
        $distinct=count($items);$score=min(100.0,20.0*$distinct + ($packages?30.0:0.0));
        return ['raw_score'=>$score,'explanation'=>'Формальная оценка числа задействованных параметров и наличия связанных пакетных предложений.','evidence'=>array_values(array_unique($packages))];
    }
}
