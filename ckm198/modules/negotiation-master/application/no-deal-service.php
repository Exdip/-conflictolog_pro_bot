<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class NoDealService {
    private CompletionService $completion;
    public function __construct(?CompletionService $completion=null){$this->completion=$completion?:new CompletionService();}
    private static function b64url(string $value):string{return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
    private static function b64decode(string $value):string{$pad=strlen($value)%4;if($pad)$value.=str_repeat('=',4-$pad);return (string)base64_decode(strtr($value,'-_','+/'),true);}
    private static function signPayload(array $payload):string{$json=wp_json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$body=self::b64url($json);$sig=hash_hmac('sha256',$body,wp_salt('auth'));return $body.'.'.$sig;}
    private static function verifyToken(string $token,int $sessionId):array{
        $parts=explode('.',$token,2);if(count($parts)!==2)throw new \InvalidArgumentException('Invalid confirmation token.');[$body,$sig]=$parts;
        if(!hash_equals(hash_hmac('sha256',$body,wp_salt('auth')),$sig))throw new \InvalidArgumentException('Invalid confirmation token.');
        $data=json_decode(self::b64decode($body),true);if(!is_array($data))throw new \InvalidArgumentException('Invalid confirmation token.');$context=Access::context();
        if((int)($data['sid']??0)!==$sessionId||(int)($data['uid']??0)!==(int)$context['user_id']||(int)($data['tenant']??-999)!==(int)$context['tenant_id']||(int)($data['exp']??0)<time())throw new \InvalidArgumentException('Confirmation token expired or invalid.');
        return $data;
    }
    private static function decode(?string $json):array{if($json===null||$json==='')return[];try{$v=json_decode($json,true,512,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}catch(\Throwable){return[];}}
    private function snapshot(int $sessionId):array{
        global $wpdb;$session=Access::session($sessionId);$items=Schema::table('items');$state=Schema::table('item_state');
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT i.id,i.code,i.title,i.unit,i.required_for_agreement,s.status,s.current_value_json,s.proposed_by FROM `$items` i INNER JOIN `$state` s ON s.item_id=i.id WHERE s.session_id=%d AND i.scenario_version_id=%d ORDER BY i.sort_order ASC,i.id ASC",$sessionId,(int)$session['scenario_version_id']),ARRAY_A);
        $agreed=[];$unresolved=[];$active=[];
        foreach($rows as $row){$current=self::decode($row['current_value_json']??null);$entry=['item_id'=>(int)$row['id'],'code'=>(string)$row['code'],'title'=>(string)$row['title'],'unit'=>(string)$row['unit']];
            if(in_array((string)$row['status'],['agreed','reopen_requested'],true)&&isset($current['agreed']['value'])){$entry['value']=$current['agreed']['value'];$agreed[]=$entry;continue;}
            if(!empty($row['required_for_agreement']))$unresolved[]=$entry;
            $offers=[];foreach(['player','opponent'] as $side){if(isset($current['offers'][$side]['value']))$offers[$side]=$current['offers'][$side]['value'];}if($offers){$entry['offers']=$offers;$active[]=$entry;}
        }
        return ['agreed_items'=>$agreed,'unresolved_items'=>$unresolved,'active_offers'=>$active];
    }
    public function preview(int $sessionId):array{
        $session=Access::session($sessionId);if($session['status']!=='in_progress')throw new SessionCompletedException('Session is already completed.');if($session['processing_status']!=='idle')throw new StateConflictException('Wait until the current turn is complete.');
        $snapshot=$this->snapshot($sessionId);$ctx=Access::context();global $wpdb;$messages=Schema::table('messages');$seq=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(id),0) FROM `$messages` WHERE session_id=%d AND channel IN ('dialogue','negotiation')",$sessionId));$payload=['sid'=>$sessionId,'rev'=>(int)$session['state_revision'],'seq'=>$seq,'uid'=>(int)$ctx['user_id'],'tenant'=>(int)$ctx['tenant_id'],'exp'=>time()+600];
        return $snapshot+['state_revision'=>(int)$session['state_revision'],'confirmation_token'=>self::signPayload($payload)];
    }
    public function confirm(int $sessionId,string $token,string $comment=''):array{
        $data=self::verifyToken($token,$sessionId);$session=Access::session($sessionId);if($session['status']==='completed_no_agreement_player')return ['status'=>'completed_no_agreement_player','idempotent'=>true];if($session['status']!=='in_progress')throw new SessionCompletedException('Session is already completed.');global $wpdb;$messages=Schema::table('messages');$seq=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(id),0) FROM `$messages` WHERE session_id=%d AND channel IN ('dialogue','negotiation')",$sessionId));if($seq!==(int)($data['seq']??-1))throw new StateConflictException('Negotiation dialogue changed. Open the no-deal preview again.');$comment=trim(str_replace("\0",'',strip_tags($comment)));if(function_exists('mb_substr'))$comment=mb_substr($comment,0,1000,'UTF-8');else$comment=substr($comment,0,1000);
        $snapshot=$this->snapshot($sessionId);return $this->completion->completeNoDeal($sessionId,(int)$data['rev'],$snapshot,$comment);
    }


    public function isPlayerWalkawayCandidate(int $sessionId, int $playerMessageId): bool {
        global $wpdb;
        Access::session($sessionId); $events=Schema::table('events');
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$events` WHERE session_id=%d AND message_id=%d AND actor='player' AND event_type='walkaway_candidate'",$sessionId,$playerMessageId)) > 0;
    }

    public function maybeCompleteOpponentWalkaway(int $sessionId, int $opponentMessageId): array {
        global $wpdb;
        $session=Access::session($sessionId);
        if($session['status']!=='in_progress')return ['completed'=>false,'status'=>$session['status']];
        $events=Schema::table('events');
        $candidate=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$events` WHERE session_id=%d AND message_id=%d AND actor='opponent' AND event_type='walkaway_candidate'",$sessionId,$opponentMessageId));
        if($candidate<1)return ['completed'=>false,'reason'=>'no_candidate'];
        $rules=Schema::table('rules');
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT code,condition_json,action_json FROM `$rules` WHERE scenario_version_id=%d AND rule_type='walkaway' AND is_active=1 ORDER BY priority DESC,id ASC",(int)$session['scenario_version_id']),ARRAY_A);
        foreach($rows as $row){
            $condition=self::decode($row['condition_json']??null);$action=self::decode($row['action_json']??null);
            if(($action['type']??'')!=='opponent_walkaway')continue;
            $threshold=(int)($condition['repeat_threshold']??0);
            if($threshold<1)continue; // A scenario must define a concrete server-checkable threshold.
            $pressure=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$events` WHERE session_id=%d AND ((actor='opponent' AND event_type IN ('walkaway_warning','walkaway_candidate')) OR (actor='player' AND event_type='red_line_candidate'))",$sessionId));
            if($pressure<$threshold)continue;
            $result=$this->completion->completeOpponentNoDeal($sessionId,$opponentMessageId,'rule:'.(string)$row['code']);
            return ['completed'=>true]+$result;
        }
        return ['completed'=>false,'reason'=>'walkaway_rule_not_satisfied'];
    }

}
