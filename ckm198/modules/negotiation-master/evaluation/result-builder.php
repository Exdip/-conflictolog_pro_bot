<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class ResultBuilder {
    private static function decode(?string $json):array{if($json===null||$json==='')return[];try{$v=json_decode($json,true,512,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}catch(\Throwable){return[];}}
    public function build(int $sessionId):array{
        global $wpdb;$session=Access::session($sessionId);$evalT=Schema::table('evaluations');$scoreT=Schema::table('evaluation_scores');$rulesT=Schema::table('evaluation_rules');
        $evaluation=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$evalT` WHERE session_id=%d",$sessionId),ARRAY_A)?:null;
        if(!$evaluation)return ['status'=>(string)$session['evaluation_status'],'ready'=>false];
        $scores=(array)$wpdb->get_results($wpdb->prepare("SELECT s.raw_score,s.weighted_score,s.source,s.confidence,s.explanation,s.evidence_json,r.code,r.title,r.weight,r.sort_order FROM `$scoreT` s INNER JOIN `$rulesT` r ON r.id=s.evaluation_rule_id WHERE s.evaluation_id=%d ORDER BY r.sort_order ASC,r.id ASC",(int)$evaluation['id']),ARRAY_A);
        foreach($scores as &$s){$s['raw_score']=$s['raw_score']===null?null:(float)$s['raw_score'];$s['weighted_score']=$s['weighted_score']===null?null:(float)$s['weighted_score'];$s['weight']=(float)$s['weight'];$s['confidence']=$s['confidence']===null?null:(float)$s['confidence'];$s['evidence']=self::decode($s['evidence_json']??null);unset($s['evidence_json']);}unset($s);
        $summary=self::decode($evaluation['summary_json']??null);
        return ['ready'=>$evaluation['status']==='completed','status'=>(string)$evaluation['status'],'evaluation_version'=>(string)$evaluation['evaluation_version'],'final_score'=>$evaluation['final_score']===null?null:(float)$evaluation['final_score'],'display_score'=>$evaluation['final_score']===null?null:(int)round((float)$evaluation['final_score']),'result_type'=>(string)$evaluation['result_type'],'red_line_breached'=>!empty($evaluation['red_line_breached']),'summary'=>$summary,'criteria'=>$scores,'completed_at'=>$evaluation['completed_at']];
    }
}
