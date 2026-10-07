<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class StateConflictException extends \RuntimeException {}
final class SessionCompletedException extends \RuntimeException {}
final class AgreementValidationException extends \RuntimeException {
    public array $details;
    public function __construct(string $message,array $details=[]){parent::__construct($message);$this->details=$details;}
}

final class CompletionService {
    private AgreementValidator $validator;
    public function __construct(?AgreementValidator $validator=null){$this->validator=$validator?:new AgreementValidator();}
    private static function decode(?string $json):array{if($json===null||$json==='')return[];try{$v=json_decode($json,true,512,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}catch(\Throwable){return[];}}

    public function completeAgreement(int $sessionId,int $agreementId):array{
        global $wpdb;
        $session=Access::session($sessionId); if($session['status']!=='in_progress')throw new SessionCompletedException('Session is already completed.');
        $agreements=Schema::table('agreements');$sessions=Schema::table('sessions');$items=Schema::table('items');$state=Schema::table('item_state');
        if($wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Unable to start completion transaction.');
        try{
            $locked=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$sessions` WHERE id=%d FOR UPDATE",$sessionId),ARRAY_A);
            if(!$locked||$locked['status']!=='in_progress')throw new SessionCompletedException('Session is already completed.');
            $agreement=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$agreements` WHERE id=%d AND session_id=%d FOR UPDATE",$agreementId,$sessionId),ARRAY_A);
            if(!$agreement)throw new \InvalidArgumentException('Agreement unavailable.');
            if((int)$agreement['state_revision']!==(int)$locked['state_revision'])throw new StateConflictException('Negotiation state changed. Create a new agreement draft.');
            $package=self::decode($agreement['package_json']??null);$formal=$this->validator->validatePackage($sessionId,$package,true);
            if(!$formal['valid'])throw new AgreementValidationException('Agreement violates formal constraints.',$formal);
            $now=current_time('mysql',true);
            foreach($package as $entry){
                if(!is_array($entry)||!isset($entry['item_id'])||$entry['value']===null)continue;
                $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$state` WHERE session_id=%d AND item_id=%d FOR UPDATE",$sessionId,(int)$entry['item_id']),ARRAY_A);if(!$row)continue;
                $current=self::decode($row['current_value_json']??null);$current['agreed']=['value'=>$entry['value'],'agreement_id'=>$agreementId,'confirmed_by'=>['player','opponent']];unset($current['acceptance_candidate'],$current['rejected_by']);
                $ok=$wpdb->update($state,['status'=>'agreed','current_value_json'=>wp_json_encode($current,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'proposed_by'=>'','bundle_key'=>'','updated_at'=>$now],['id'=>(int)$row['id']]);
                if($ok===false)throw new \RuntimeException('Unable to finalize agreement item.');
            }
            $validation=self::decode($agreement['php_validation_json']??null);$validation['final_validation']=$formal;
            if($wpdb->update($agreements,['status'=>'accepted','php_validation_json'=>wp_json_encode($validation,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'responded_at'=>$now],['id'=>$agreementId])===false)throw new \RuntimeException('Unable to accept agreement.');
            $stateJson=self::decode($locked['state_json']??null);$stateJson['completion']=['type'=>'agreement','agreement_id'=>$agreementId,'red_line_breached'=>(bool)$formal['red_line_breached'],'completed_at'=>$now];
            $ok=$wpdb->query($wpdb->prepare("UPDATE `$sessions` SET status='completed_agreement',processing_status='idle',processing_lock_token=NULL,processing_lock_expires_at=NULL,active_client_id=NULL,writer_lock_expires_at=NULL,completed_at=%s,final_agreement_id=%d,evaluation_status='pending',state_json=%s,state_revision=state_revision+1,last_activity_at=%s,updated_at=%s WHERE id=%d AND status='in_progress'",
                $now,$agreementId,wp_json_encode($stateJson,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$now,$now,$sessionId));
            if($ok!==1)throw new \RuntimeException('Unable to complete agreement session.');
            if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Unable to commit agreement completion.');
            return ['status'=>'completed_agreement','red_line_breached'=>(bool)$formal['red_line_breached'],'agreement_id'=>$agreementId];
        }catch(\Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
    }

    public function completeNoDeal(int $sessionId,int $expectedRevision,array $snapshot,string $comment=''):array{
        global $wpdb;
        $sessions=Schema::table('sessions'); if($wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Unable to start no-deal transaction.');
        try{
            $locked=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$sessions` WHERE id=%d FOR UPDATE",$sessionId),ARRAY_A);
            if(!$locked)throw new \RuntimeException('Session unavailable.'); if($locked['status']!=='in_progress')throw new SessionCompletedException('Session is already completed.');
            if((int)$locked['state_revision']!==$expectedRevision)throw new StateConflictException('Negotiation state changed. Open the no-deal preview again.');
            $now=current_time('mysql',true);$stateJson=self::decode($locked['state_json']??null);$stateJson['completion']=['type'=>'no_agreement_player','snapshot'=>$snapshot,'player_comment'=>$comment,'completed_at'=>$now];
            $ok=$wpdb->query($wpdb->prepare("UPDATE `$sessions` SET status='completed_no_agreement_player',processing_status='idle',processing_lock_token=NULL,processing_lock_expires_at=NULL,active_client_id=NULL,writer_lock_expires_at=NULL,completed_at=%s,final_agreement_id=NULL,evaluation_status='pending',state_json=%s,state_revision=state_revision+1,last_activity_at=%s,updated_at=%s WHERE id=%d AND status='in_progress' AND state_revision=%d",
                $now,wp_json_encode($stateJson,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$now,$now,$sessionId,$expectedRevision));
            if($ok!==1)throw new StateConflictException('Negotiation state changed. Open the no-deal preview again.');
            if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Unable to commit no-deal completion.');
            return ['status'=>'completed_no_agreement_player'];
        }catch(\Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
    }

    public function completeOpponentNoDeal(int $sessionId, int $messageId, string $reason = 'opponent_walkaway'): array {
        global $wpdb;
        $sessions=Schema::table('sessions');
        if($wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Unable to start opponent no-deal transaction.');
        try{
            $locked=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$sessions` WHERE id=%d FOR UPDATE",$sessionId),ARRAY_A);
            if(!$locked)throw new \RuntimeException('Session unavailable.');
            if($locked['status']!=='in_progress')return ['status'=>(string)$locked['status'],'idempotent'=>true];
            $now=current_time('mysql',true);$stateJson=self::decode($locked['state_json']??null);
            $stateJson['completion']=['type'=>'no_agreement_opponent','message_id'=>$messageId,'reason'=>$reason,'completed_at'=>$now];
            $ok=$wpdb->query($wpdb->prepare("UPDATE `$sessions` SET status='completed_no_agreement_opponent',processing_status='idle',processing_lock_token=NULL,processing_lock_expires_at=NULL,active_client_id=NULL,writer_lock_expires_at=NULL,completed_at=%s,final_agreement_id=NULL,evaluation_status='pending',state_json=%s,state_revision=state_revision+1,last_activity_at=%s,updated_at=%s WHERE id=%d AND status='in_progress'",
                $now,wp_json_encode($stateJson,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$now,$now,$sessionId));
            if($ok!==1)throw new \RuntimeException('Unable to complete opponent no-deal session.');
            if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Unable to commit opponent no-deal completion.');
            return ['status'=>'completed_no_agreement_opponent','idempotent'=>false];
        }catch(\Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
    }

}
