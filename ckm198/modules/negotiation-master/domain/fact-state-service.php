<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class FactStateService {
    private static function decode(?string $json): array {
        if ($json === null || $json === '') { return []; }
        try { $value=json_decode($json,true,512,JSON_THROW_ON_ERROR); return is_array($value)?$value:[]; }
        catch (\Throwable) { return []; }
    }
    public function apply(int $sessionId, array $message, array $updates): array {
        global $wpdb;
        $session = Access::session($sessionId);
        $factsTable=Schema::table('hidden_facts'); $discovered=Schema::table('discovered_facts');
        $facts=(array)$wpdb->get_results($wpdb->prepare("SELECT * FROM `$factsTable` WHERE scenario_version_id=%d",(int)$session['scenario_version_id']),ARRAY_A);
        $map=[]; foreach($facts as $fact){$map[(string)$fact['code']]=$fact;}
        $changed=false;$applied=[];
        foreach($updates as $update){
            $code=(string)($update['fact_code']??''); if(!isset($map[$code]))continue;
            $fact=$map[$code]; $desired=(string)($update['suggested_level']??'')==='revealed'?2:1;
            $confidence=(float)($update['confidence']??0);
            if(($desired===2&&$confidence<0.85)||($desired===1&&$confidence<0.70))continue;
            $rules=self::decode($fact['reveal_rules_json']??null);
            if($desired===1 && empty($rules['partial']))continue;
            if($desired===2 && empty($rules['revealed']))continue;
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$discovered` WHERE session_id=%d AND hidden_fact_id=%d",$sessionId,(int)$fact['id']),ARRAY_A);
            $old=$row?(int)$row['reveal_level']:0;
            if($desired<=$old)continue;
            $now=current_time('mysql',true);
            if($row){
                $ok=$wpdb->query($wpdb->prepare("UPDATE `$discovered` SET reveal_level=%d,confidence=%s,updated_at=%s WHERE id=%d",$desired,$confidence,$now,(int)$row['id']));
                if($ok===false)throw new \RuntimeException('Unable to update discovered fact.');
            }else{
                $ok=$wpdb->insert($discovered,['session_id'=>$sessionId,'hidden_fact_id'=>(int)$fact['id'],'reveal_level'=>$desired,'confidence'=>$confidence,'first_discovered_message_id'=>(int)$message['id'],'updated_at'=>$now]);
                if($ok===false)throw new \RuntimeException('Unable to save discovered fact.');
            }
            $changed=true;
            $discovery=(string)($update['discovery_event']??'interest_discovered');
            $publicSummary = $desired === 2 ? (string)($fact['content'] ?? '') : match($discovery) {
                'constraint_discovered' => 'Вы получили частичную информацию об ограничениях другой стороны.',
                'alternative_discovered' => 'Вы получили частичную информацию об альтернативах другой стороны.',
                default => 'Вы получили частичную информацию об интересах другой стороны.',
            };
            $applied[]=['hidden_fact_id'=>(int)$fact['id'],'fact_code'=>$code,'previous_level'=>$old,'reveal_level'=>$desired,'confidence'=>$confidence,
                'public_summary'=>$publicSummary,'reason'=>(string)($update['reason']??''),'discovery_event'=>$discovery,
                'automatic_reveal'=>!empty($rules['automatic_reveal'])];
        }
        return ['changed'=>$changed,'applied'=>$applied];
    }
}
