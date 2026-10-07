<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class EventService {
    private static function canonical(mixed $value): mixed {
        if (!is_array($value)) { return $value; }
        if (array_is_list($value)) { return array_map([self::class,'canonical'],$value); }
        ksort($value); foreach($value as $k=>$v){$value[$k]=self::canonical($v);} return $value;
    }
    private static function key(array $event): string {
        $copy=$event; unset($copy['confidence']);
        return 'arb-' . substr(hash('sha256', wp_json_encode(self::canonical($copy), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),0,56);
    }
    public function appendBatch(int $sessionId, array $message, array $events, string $arbiterVersion): array {
        global $wpdb;
        $session=Access::session($sessionId); $table=Schema::table('events');
        $itemsTable=Schema::table('items');$factsTable=Schema::table('hidden_facts');
        $validItems=array_map('intval',(array)$wpdb->get_col($wpdb->prepare("SELECT id FROM `$itemsTable` WHERE scenario_version_id=%d",(int)$session['scenario_version_id'])));
        $validFacts=array_map('intval',(array)$wpdb->get_col($wpdb->prepare("SELECT id FROM `$factsTable` WHERE scenario_version_id=%d",(int)$session['scenario_version_id'])));
        $inserted=[];
        foreach($events as $event){
            if(!is_array($event)||empty($event['event_type']))continue;
            $targetType=(string)($event['target_type']??'none'); $targetId=isset($event['target_id'])?(int)$event['target_id']:null;
            if($targetType==='item' && ($targetId===null || !in_array($targetId,$validItems,true)))continue;
            if($targetType==='hidden_fact' && ($targetId===null || !in_array($targetId,$validFacts,true)))continue;
            if(!in_array($targetType,['session','item','hidden_fact','message','none'],true))$targetType='none';
            if($targetType==='message')$targetId=(int)$message['id'];
            if($targetType==='session')$targetId=$sessionId;
            if($targetType==='none')$targetId=null;
            $normalized=['event_type'=>(string)$event['event_type'],'actor'=>(string)($event['actor']??$message['actor']),'target_type'=>$targetType,'target_id'=>$targetId,'payload'=>is_array($event['payload']??null)?$event['payload']:[]];
            $eventKey=self::key($normalized);
            $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM `$table` WHERE session_id=%d AND message_id=%d AND event_key=%s",$sessionId,(int)$message['id'],$eventKey));
            if($exists){continue;}
            $seq=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(sequence_no),0)+1 FROM `$table` WHERE session_id=%d",$sessionId));
            $ok=$wpdb->insert($table,['session_id'=>$sessionId,'message_id'=>(int)$message['id'],'sequence_no'=>max(1,$seq),'event_key'=>$eventKey,
                'event_type'=>$normalized['event_type'],'actor'=>$normalized['actor'],'target_type'=>$targetType,'target_id'=>$targetId,
                'confidence'=>isset($event['confidence'])?(float)$event['confidence']:null,'payload_json'=>wp_json_encode($normalized['payload'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'arbiter_version'=>$arbiterVersion,'created_at'=>current_time('mysql',true)]);
            if($ok===false)throw new \RuntimeException('Unable to save negotiation event.');
            $inserted[]=(int)$wpdb->insert_id;
        }
        return ['changed'=>!empty($inserted),'inserted_ids'=>$inserted];
    }
}
