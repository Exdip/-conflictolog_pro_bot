<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

/**
 * Formal comparison between a negotiated package and the player's outside
 * alternative. The alternative remains scenario data; this service contains
 * no scenario-specific rules.
 *
 * Supported data contract inside player_alternative_json:
 * {
 *   "description": "...",
 *   "valuation": {
 *     "model": "player_achievement_score",
 *     "score": 70
 *   }
 * }
 *
 * score is on the same 0..100 scale as ZopaService::playerAchievement().
 */
final class AlternativeValueService {
    public const MODEL = 'player_achievement_score';
    private const EPSILON = 0.5;

    public static function model(array $alternative): ?array {
        $valuation=is_array($alternative['valuation']??null)?$alternative['valuation']:[];
        if((string)($valuation['model']??'')!==self::MODEL)return null;
        if(!isset($valuation['score'])||!is_numeric($valuation['score']))return null;
        $score=(float)$valuation['score'];
        if($score<0||$score>100)return null;
        return ['model'=>self::MODEL,'alternative_score'=>round($score,4)];
    }

    public static function compare(?float $dealScore,array $alternative,bool $searchComplete=true): array {
        $model=self::model($alternative);
        if(!$model||$dealScore===null)return ['available'=>false,'relation'=>'unknown'];
        $deal=max(0.0,min(100.0,$dealScore));$alt=(float)$model['alternative_score'];$delta=$deal-$alt;
        if($delta>self::EPSILON)$relation='better_than_alternative';
        elseif($delta<-self::EPSILON)$relation=$searchComplete?'worse_than_alternative':'unknown';
        else $relation=$searchComplete?'equivalent_to_alternative':'at_least_equivalent';
        return [
            'available'=>true,'model'=>self::MODEL,'deal_score'=>round($deal,4),'alternative_score'=>round($alt,4),
            'margin'=>round($delta,4),'relation'=>$relation,'search_complete'=>$searchComplete,
        ];
    }

    public static function publicProjection(array $comparison,string $subject='best_mutual'): array {
        if(empty($comparison['available']))return [];
        $relation=(string)($comparison['relation']??'unknown');
        $summary='Сравнение с вариантом отказа от сделки не завершено.';
        if($subject==='final_agreement'){
            $summary=match($relation){
                'better_than_alternative'=>'Итоговое соглашение выгоднее отказа от сделки.',
                'equivalent_to_alternative','at_least_equivalent'=>'Итоговое соглашение не хуже отказа от сделки.',
                'worse_than_alternative'=>'Итоговое соглашение хуже отказа от сделки.',
                default=>$summary,
            };
        }else{
            $summary=match($relation){
                'better_than_alternative'=>'Среди возможных вариантов был пакет выгоднее отказа от сделки.',
                'equivalent_to_alternative','at_least_equivalent'=>'Среди возможных вариантов был пакет не хуже отказа от сделки.',
                'worse_than_alternative'=>'Даже лучший найденный вариант был хуже отказа от сделки.',
                default=>$summary,
            };
        }
        return [
            'relation'=>$relation,'deal_score'=>$comparison['deal_score']??null,'alternative_score'=>$comparison['alternative_score']??null,
            'margin'=>$comparison['margin']??null,'search_complete'=>!empty($comparison['search_complete']),'summary'=>$summary,
        ];
    }
}
