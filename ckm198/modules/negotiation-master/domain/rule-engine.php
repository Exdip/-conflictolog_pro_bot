<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class RuleEngine {
    private static function decode(?string $json): array {
        if($json===null||$json==='')return[];try{$v=json_decode($json,true,512,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}catch(\Throwable){return[];}
    }
    private static function worse(mixed $old,mixed $new,string $direction): bool {
        if(!is_numeric($old)||!is_numeric($new))return false;$old=(float)$old;$new=(float)$new;
        if(in_array($direction,['higher','higher_better'],true))return $new<$old;
        if(in_array($direction,['lower','lower_better'],true))return $new>$old;
        return false;
    }
    private static function compare(string $op,mixed $left,mixed $right): bool {
        if(is_numeric($left)&&is_numeric($right)){$left=(float)$left;$right=(float)$right;}
        return match($op){'lt'=>$left<$right,'lte'=>$left<=$right,'gt'=>$left>$right,'gte'=>$left>=$right,'eq'=>$left==$right,default=>false};
    }
    private static function evalCondition(mixed $condition,array $values): bool {
        if(!is_array($condition))return false;
        if(isset($condition['all'])&&is_array($condition['all'])){foreach($condition['all'] as $c){if(!self::evalCondition($c,$values))return false;}return true;}
        if(isset($condition['any'])&&is_array($condition['any'])){foreach($condition['any'] as $c){if(self::evalCondition($c,$values))return true;}return false;}
        foreach(['lt','lte','gt','gte','eq'] as $op){
            if(isset($condition[$op])&&is_array($condition[$op])&&count($condition[$op])===2){
                [$left,$right]=$condition[$op];
                if(is_array($left)&&isset($left['item'])){$code=(string)$left['item'];if(!array_key_exists($code,$values))return false;$left=$values[$code];}
                return self::compare($op,$left,$right);
            }
        }
        return false;
    }
    private static function requirementSatisfied(array $action,array $values): bool {
        $type=(string)($action['type']??'');
        if($type==='require'){
            foreach(['lt','lte','gt','gte','eq'] as $op){
                if(isset($action[$op]))return self::evalCondition([$op=>$action[$op]],$values);
            }
            return false;
        }
        if($type==='require_any'&&is_array($action['conditions']??null)){
            foreach($action['conditions'] as $c){if(self::evalCondition($c,$values))return true;}
            return false;
        }
        if($type==='require_all'&&is_array($action['conditions']??null)){
            foreach($action['conditions'] as $c){if(!self::evalCondition($c,$values))return false;}
            return true;
        }
        if($type==='require_items'&&is_array($action['items']??null)){
            foreach($action['items'] as $code){
                if(!is_string($code)||$code===''||!array_key_exists($code,$values)||$values[$code]===null||$values[$code]==='')return false;
            }
            return true;
        }
        return true;
    }

    public function derive(int $sessionId,array $message,array $analysis,array $itemResult,array $factResult): array {
        global $wpdb;
        $session=Access::session($sessionId);$actor=(string)$message['actor'];$events=[];$values=[];$formal=[];
        $conditionalSuggested=false;foreach((array)($analysis['events']??[]) as $e){if(($e['event_type']??'')==='conditional_concession')$conditionalSuggested=true;}

        foreach((array)($analysis['events']??[]) as $event){
            if(in_array($event['event_type']??'', ['offer_made','counteroffer_made','package_offer_made','concession_made','conditional_concession','red_line_candidate','item_discussed','item_proposed','item_acceptance_candidate','item_rejected','interest_discovered','constraint_discovered','alternative_discovered','reopen_requested','item_reopened','reopen_rejected','unjustified_reopen_attempt','justified_reopen','strategic_repackaging','agreement_backtracking','item_agreed'],true))continue;
            $targetId=null;
            if(($event['target_type']??'')==='item')foreach((array)($itemResult['applied']??[]) as $a){if($a['item_code']===($event['target_code']??'')){$targetId=$a['item_id'];break;}}
            if(($event['target_type']??'')==='hidden_fact')foreach((array)($factResult['applied']??[]) as $a){if($a['fact_code']===($event['target_code']??'')){$targetId=$a['hidden_fact_id'];break;}}
            $targetType=(string)($event['target_type']??'none');
            if(in_array($targetType,['item','hidden_fact'],true)&&$targetId===null)$targetType='none';
            $events[]=['event_type'=>$event['event_type'],'actor'=>$actor,'target_type'=>$targetType,'target_id'=>$targetId,'confidence'=>$event['confidence']??null,'payload'=>$event['payload']??[]];
        }

        $stateTable=Schema::table('item_state');$itemsTable=Schema::table('items');
        $existing=(array)$wpdb->get_results($wpdb->prepare("SELECT i.code,s.current_value_json FROM `$stateTable` s INNER JOIN `$itemsTable` i ON i.id=s.item_id WHERE s.session_id=%d AND i.scenario_version_id=%d",$sessionId,(int)$session['scenario_version_id']),ARRAY_A);
        foreach($existing as $row){
            $decoded=self::decode($row['current_value_json']??null);
            if(isset($decoded['offers'][$actor]['value']))$values[(string)$row['code']]=$decoded['offers'][$actor]['value'];
        }
        $bundles=[];$proposals=[];
        foreach((array)($itemResult['applied']??[]) as $a){
            $reopenPayload=['item_code'=>$a['item_code'],'policy'=>$a['reopen_policy']??'','reason_type'=>$a['reopen_reason_type']??'none','reason'=>$a['reopen_reason']??'','previous_value'=>$a['previous_agreed_value']??null,'requested_value'=>$a['value']??null];
            if(($a['reopen_result']??'')==='denied'){
                $events[]=['event_type'=>'unjustified_reopen_attempt','actor'=>$actor,'target_type'=>'item','target_id'=>$a['item_id'],'confidence'=>1.0,'payload'=>$reopenPayload];
            } elseif(($a['reopen_result']??'')==='requested'){
                $events[]=['event_type'=>'reopen_requested','actor'=>$actor,'target_type'=>'item','target_id'=>$a['item_id'],'confidence'=>0.98,'payload'=>$reopenPayload];
            } elseif(($a['reopen_result']??'')==='reopened'){
                if(($a['action']??'')==='reopen_request')$events[]=['event_type'=>'reopen_requested','actor'=>$actor,'target_type'=>'item','target_id'=>$a['item_id'],'confidence'=>0.98,'payload'=>$reopenPayload];
                $events[]=['event_type'=>'item_reopened','actor'=>$actor,'target_type'=>'item','target_id'=>$a['item_id'],'confidence'=>1.0,'payload'=>$reopenPayload];
                if(($a['reopen_reason_type']??'none')!=='none')$events[]=['event_type'=>'justified_reopen','actor'=>$actor,'target_type'=>'item','target_id'=>$a['item_id'],'confidence'=>0.98,'payload'=>$reopenPayload];
            } elseif(($a['reopen_result']??'')==='rejected'){
                $events[]=['event_type'=>'reopen_rejected','actor'=>$actor,'target_type'=>'item','target_id'=>$a['item_id'],'confidence'=>1.0,'payload'=>$reopenPayload];
            }

            if($a['action']==='discussed')$events[]=['event_type'=>'item_discussed','actor'=>$actor,'target_type'=>'item','target_id'=>$a['item_id'],'confidence'=>0.95,'payload'=>['item_code'=>$a['item_code']]];
            elseif($a['action']==='proposed' && ($a['reopen_result']??'')!=='denied' && ($a['reopen_result']??'')!=='requested'){
                $values[$a['item_code']]=$a['value'];$proposals[]=$a;
                if($a['bundle_key']!=='')$bundles[$a['bundle_key']][]=$a;
                $events[]=['event_type'=>'item_proposed','actor'=>$actor,'target_type'=>'item','target_id'=>$a['item_id'],'confidence'=>0.98,'payload'=>['item_code'=>$a['item_code'],'value'=>$a['value'],'bundle_key'=>$a['bundle_key']]];
                $events[]=['event_type'=>$a['previous_other_value']!==null?'counteroffer_made':'offer_made','actor'=>$actor,'target_type'=>'item','target_id'=>$a['item_id'],'confidence'=>0.95,'payload'=>['item_code'=>$a['item_code'],'value'=>$a['value'],'bundle_key'=>$a['bundle_key']]];
                $direction=$actor==='player'?$a['player_preference_direction']:$a['opponent_preference_direction'];
                if($a['previous_actor_value']!==null && self::worse($a['previous_actor_value'],$a['value'],$direction)){
                    $events[]=['event_type'=>$conditionalSuggested?'conditional_concession':'concession_made','actor'=>$actor,'target_type'=>'item','target_id'=>$a['item_id'],'confidence'=>0.98,
                        'payload'=>['item_code'=>$a['item_code'],'from'=>$a['previous_actor_value'],'to'=>$a['value'],'bundle_key'=>$a['bundle_key']]];
                }
            } elseif($a['action']==='acceptance_candidate'){
                $events[]=['event_type'=>'item_acceptance_candidate','actor'=>$actor,'target_type'=>'item','target_id'=>$a['item_id'],'confidence'=>0.98,'payload'=>['item_code'=>$a['item_code'],'value'=>$a['value']]];
                if(!empty($a['agreement_reached']))$events[]=['event_type'=>'item_agreed','actor'=>$actor,'target_type'=>'item','target_id'=>$a['item_id'],'confidence'=>1.0,'payload'=>['item_code'=>$a['item_code'],'value'=>$a['value']]];
            } elseif($a['action']==='rejected')$events[]=['event_type'=>'item_rejected','actor'=>$actor,'target_type'=>'item','target_id'=>$a['item_id'],'confidence'=>0.95,'payload'=>['item_code'=>$a['item_code']]];
        }
        foreach($bundles as $bundle=>$rows){if(count($rows)>=2)$events[]=['event_type'=>'package_offer_made','actor'=>$actor,'target_type'=>'session','target_id'=>$sessionId,'confidence'=>0.98,'payload'=>['bundle_key'=>$bundle,'items'=>array_column($rows,'item_code')]];}

        foreach((array)($factResult['applied']??[]) as $fact){
            $events[]=['event_type'=>$fact['discovery_event'],'actor'=>$actor,'target_type'=>'hidden_fact','target_id'=>$fact['hidden_fact_id'],'confidence'=>$fact['confidence'],
                'payload'=>['fact_code'=>$fact['fact_code'],'from_level'=>$fact['previous_level'],'to_level'=>$fact['reveal_level'],'public_summary'=>$fact['public_summary'],'reason'=>$fact['reason']]];
            if($actor==='opponent' && $fact['previous_level']===0 && $fact['reveal_level']===2 && !$fact['automatic_reveal']){
                $events[]=['event_type'=>'unexpected_fact_reveal','actor'=>'opponent','target_type'=>'hidden_fact','target_id'=>$fact['hidden_fact_id'],'confidence'=>$fact['confidence'],
                    'payload'=>['fact_code'=>$fact['fact_code'],'public_summary'=>$fact['public_summary']]];
            }
        }

        if($values){
            $rulesTable=Schema::table('rules');
            $rules=(array)$wpdb->get_results($wpdb->prepare("SELECT code,rule_type,condition_json,action_json FROM `$rulesTable` WHERE scenario_version_id=%d AND is_active=1 ORDER BY priority DESC,id ASC",(int)$session['scenario_version_id']),ARRAY_A);
            foreach($rules as $rule){
                $condition=self::decode($rule['condition_json']??null);$action=self::decode($rule['action_json']??null);
                if(!self::evalCondition($condition,$values))continue;
                $type=(string)($action['type']??'');
                if($type==='flag_breach' && (string)($action['side']??'')===$actor){
                    $code=(string)($action['item']??'');$match=null;foreach($proposals as $p){if($p['item_code']===$code){$match=$p;break;}}
                    if($match){$events[]=['event_type'=>'red_line_candidate','actor'=>$actor,'target_type'=>'item','target_id'=>$match['item_id'],'confidence'=>1.0,'payload'=>['rule_code'=>$rule['code'],'item_code'=>$code,'value'=>$match['value']]];$formal[]=['rule_code'=>$rule['code'],'type'=>'red_line_candidate'];}
                } elseif($type==='block'){
                    $events[]=['event_type'=>'dependency_candidate','actor'=>$actor,'target_type'=>'session','target_id'=>$sessionId,'confidence'=>1.0,'payload'=>['rule_code'=>$rule['code'],'constraint'=>'hard']];
                    $formal[]=['rule_code'=>$rule['code'],'type'=>'hard_constraint_candidate'];
                } elseif(in_array($type,['require','require_any','require_all','require_items'],true) && !self::requirementSatisfied($action,$values)){
                    $events[]=['event_type'=>'dependency_candidate','actor'=>$actor,'target_type'=>'session','target_id'=>$sessionId,'confidence'=>1.0,'payload'=>['rule_code'=>$rule['code']]];$formal[]=['rule_code'=>$rule['code'],'type'=>'dependency_candidate'];
                }
            }
        }
        return ['events'=>$events,'state_patch'=>['dialogue_state'=>$analysis['dialogue_state']??['tension'=>'normal','walkaway_risk'=>'low'],'rule_flags'=>$formal]];
    }
}
