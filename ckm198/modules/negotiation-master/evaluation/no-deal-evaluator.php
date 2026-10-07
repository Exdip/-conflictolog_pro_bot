<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class NoDealEvaluator {
    private static function legacyClassify(array $context): array {
        $status=(string)($context['session']['status']??'');
        $events=(array)($context['events']??[]);$playerTurns=0;
        foreach((array)($context['messages']??[]) as $m){if(($m['actor']??'')==='player')$playerTurns++;}
        $playerRed=0;$opponentWarnings=0;$dependencyFlags=0;
        foreach($events as $e){$type=(string)($e['event_type']??'');$actor=(string)($e['actor']??'');if($actor==='player'&&$type==='red_line_candidate')$playerRed++;if($actor==='opponent'&&in_array($type,['walkaway_warning','walkaway_candidate'],true))$opponentWarnings++;if($type==='dependency_candidate')$dependencyFlags++;}
        if($status==='completed_no_agreement_opponent')return ['result_type'=>'opponent_walkaway_caused','economic_raw'=>35,'reason'=>'Переговоры завершил оппонент после подтверждённого сервером сценария выхода.'];
        if($playerTurns<=2&&$playerRed===0&&$dependencyFlags===0)return ['result_type'=>'premature_walkaway','economic_raw'=>35,'reason'=>'Переговоры завершены очень рано, до проверки доступных условий и интересов.'];
        if($playerRed>0||$dependencyFlags>0)return ['result_type'=>'rational_walkaway','economic_raw'=>80,'reason'=>'Выход произошёл после появления формальных признаков неприемлемого или несбалансированного пакета.'];
        if($opponentWarnings>=2)return ['result_type'=>'opponent_walkaway_caused','economic_raw'=>40,'reason'=>'К завершению привело устойчивое давление на границы другой стороны.'];
        return ['result_type'=>'unclear_no_deal','economic_raw'=>55,'reason'=>'Соглашение не достигнуто; доступных формальных данных недостаточно для более уверенной классификации.'];
    }

    private static function boundaryViolation(array $item, mixed $value): bool {
        $boundary=is_array($item['player_boundary']??null)?$item['player_boundary']:[];
        if (!$boundary) { return false; }
        if (isset($boundary['min']) && is_numeric($boundary['min']) && (!is_numeric($value) || (float)$value < (float)$boundary['min'])) { return true; }
        if (isset($boundary['max']) && is_numeric($boundary['max']) && (!is_numeric($value) || (float)$value > (float)$boundary['max'])) { return true; }
        if (isset($boundary['allowed']) && is_array($boundary['allowed']) && !in_array($value,$boundary['allowed'],true)) { return true; }
        if (isset($boundary['forbidden']) && is_array($boundary['forbidden']) && in_array($value,$boundary['forbidden'],true)) { return true; }
        return false;
    }

    private static function exploration(array $context): array {
        $playerTurns=0;foreach((array)($context['messages']??[]) as $m){if(($m['actor']??'')==='player')$playerTurns++;}
        $probes=0;$packageOffers=0;$proposed=[];$harm=0;
        foreach((array)($context['events']??[]) as $e){
            $type=(string)($e['event_type']??'');$actor=(string)($e['actor']??'');
            if($actor==='player'&&in_array($type,['interest_probe','constraint_probe','alternative_probe','question_asked'],true))$probes++;
            if($actor==='player'&&$type==='package_offer_made')$packageOffers++;
            if($actor==='player'&&$type==='item_proposed'){
                $code=(string)($e['payload']['item_code']??'');if($code!=='')$proposed[$code]=true;
            }
            if($actor==='player'){
                $harm += match($type){'personal_attack'=>3,'ultimatum'=>2,'commitment_broken'=>2,'unjustified_reopen_attempt'=>2,'agreement_backtracking'=>2,'red_line_candidate'=>1,'dependency_candidate'=>1,default=>0};
            }
        }
        $required=[];$untested=[];
        foreach((array)($context['items']??[]) as $item){
            if(empty($item['required_for_agreement']))continue;$code=(string)($item['code']??'');$required[$code]=true;
            if(!isset($proposed[$code]))$untested[]='Не обсуждено условие: '.(string)($item['title']??$code);
        }
        $factTotal=0;$factRevealed=0;
        foreach((array)($context['facts']??[]) as $fact){$factTotal++;if((int)($fact['reveal_level']??0)>0)$factRevealed++;else $untested[]='Не проверен значимый интерес: '.(string)($fact['title']??$fact['code']??'');}
        if($packageOffers===0)$untested[]='Не проверено пакетное предложение с несколькими условиями.';
        if($probes===0)$untested[]='Не зафиксированы вопросы об интересах, ограничениях или альтернативах.';

        $turnScore=min(1.0,$playerTurns/6.0);$itemScore=$required?min(1.0,count(array_intersect_key($proposed,$required))/count($required)):1.0;$factScore=$factTotal?($factRevealed/$factTotal):1.0;$probeScore=min(1.0,$probes/3.0);$packageScore=$packageOffers>0?1.0:0.0;
        $score=0.20*$turnScore+0.25*$itemScore+0.20*$factScore+0.20*$probeScore+0.15*$packageScore;
        $contribution=$harm>=5?'high':($harm>=2?'medium':'low');
        return ['player_turns'=>$playerTurns,'probes'=>$probes,'package_offers'=>$packageOffers,'proposed_items'=>array_keys($proposed),'exploration_score'=>round($score,4),'untested_paths'=>array_slice($untested,0,6),'player_contribution'=>$contribution,'harm_score'=>$harm];
    }

    private static function counterpartBoundaryPressure(array $context): int {
        $count=0;
        foreach((array)($context['items']??[]) as $item){
            $current=is_array($item['current_value']??null)?$item['current_value']:[];
            if(isset($current['offers']['opponent']['value'])&&self::boundaryViolation($item,$current['offers']['opponent']['value']))$count++;
        }
        return $count;
    }

    private static function payload(string $type,float $economic,string $reason,array $explore,array $zopa,int $pressure): array {
        return [
            'result_type'=>$type,'economic_raw'=>$economic,'reason'=>$reason,
            'player_contribution'=>$explore['player_contribution'],'exploration_score'=>$explore['exploration_score'],
            'untested_paths'=>$explore['untested_paths'],'counterpart_boundary_pressure'=>$pressure,
            'zopa_feasibility'=>(string)($zopa['feasibility']??'unknown'),
            'alternative_relation'=>(string)($zopa['alternative_comparison']['best_mutual']['relation']??'unknown'),
        ];
    }

    public function classify(array $context): array {
        $status=(string)($context['session']['status']??'');
        if($status==='completed_agreement')return ['result_type'=>'agreement','economic_raw'=>null,'reason'=>'Соглашение достигнуто.'];
        if(!array_key_exists('zopa',$context))return self::legacyClassify($context);

        $explore=self::exploration($context);$zopa=is_array($context['zopa']??null)?$context['zopa']:[];$feasibility=(string)($zopa['feasibility']??'conditional');$attractiveness=(string)($zopa['attractiveness']??'unknown');$alternativeRelation=(string)($zopa['alternative_comparison']['best_mutual']['relation']??'unknown');$pressure=self::counterpartBoundaryPressure($context);

        if($feasibility==='closed'&&$attractiveness!=='outside_player_boundary'){
            return self::payload('unavoidable_no_deal',75,'По формальным границам и обязательным правилам сценария взаимоприемлемый пакет не найден.',$explore,$zopa,$pressure);
        }
        if($status==='completed_no_agreement_opponent'){
            $reason=$explore['player_contribution']==='high'?'Оппонент завершил переговоры после действий игрока, заметно ухудшивших переговорный процесс.':'Переговоры завершил оппонент после подтверждённого сервером сценария выхода.';
            return self::payload('opponent_walkaway_caused',$explore['player_contribution']==='high'?30:45,$reason,$explore,$zopa,$pressure);
        }
        if($explore['player_turns']<=2||$explore['exploration_score']<0.35){
            return self::payload('premature_walkaway',35,'Переговоры завершены до достаточной проверки интересов, условий и вариантов пакетного решения.',$explore,$zopa,$pressure);
        }
        if($feasibility==='closed'&&$attractiveness==='outside_player_boundary'){
            return self::payload('rational_walkaway',82,'Технически допустимые варианты существовали, но выходили за учебные границы игрока; отказ от такого пакета формально обоснован.',$explore,$zopa,$pressure);
        }
        if(in_array($feasibility,['open','conditional'],true)&&$alternativeRelation==='worse_than_alternative'){
            return self::payload('rational_walkaway',84,'После достаточной проверки вариантов даже лучший формально найденный взаимоприемлемый пакет уступал альтернативе при отсутствии соглашения; отказ от сделки экономически обоснован.',$explore,$zopa,$pressure);
        }
        if(in_array($feasibility,['open','conditional'],true)&&$explore['player_contribution']==='high'){
            return self::payload('zopa_destroyed',38,'Рабочее пространство соглашения существовало, но действия игрока существенно ухудшили возможность его реализовать.',$explore,$zopa,$pressure);
        }
        if($pressure>0&&$explore['exploration_score']>=0.50){
            return self::payload('rational_walkaway',80,'После содержательных переговоров активные условия оппонента оставались за учебными границами игрока; завершение без сделки формально оправдано.',$explore,$zopa,$pressure);
        }
        if($feasibility==='conditional'&&$explore['exploration_score']<0.65){
            return self::payload('premature_walkaway',42,'Соглашение требовало увязки нескольких условий, но доступные пути компенсации были проверены не полностью.',$explore,$zopa,$pressure);
        }
        return self::payload('unclear_no_deal',55,'Соглашение не достигнуто; формальные данные не позволяют уверенно отнести исход к рациональному или неизбежному отказу.',$explore,$zopa,$pressure);
    }
}
