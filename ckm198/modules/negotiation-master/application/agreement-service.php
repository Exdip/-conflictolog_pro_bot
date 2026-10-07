<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class AgreementService {
    private AgreementRepository $agreements;
    private MessageRepository $messages;
    private SessionRepository $sessions;
    private AgreementValidator $validator;
    private AgreementResponseService $responses;
    private ArbiterService $arbiter;
    private EventService $events;
    private CompletionService $completion;
    private PlayerSessionSnapshotBuilder $snapshots;

    public function __construct(
        ?AgreementRepository $agreements=null, ?MessageRepository $messages=null, ?SessionRepository $sessions=null,
        ?AgreementValidator $validator=null, ?AgreementResponseService $responses=null, ?ArbiterService $arbiter=null,
        ?EventService $events=null, ?CompletionService $completion=null, ?PlayerSessionSnapshotBuilder $snapshots=null
    ){
        $this->agreements=$agreements?:new AgreementRepository(); $this->messages=$messages?:new MessageRepository(); $this->sessions=$sessions?:new SessionRepository();
        $this->validator=$validator?:new AgreementValidator(); $this->responses=$responses?:new AgreementResponseService(); $this->arbiter=$arbiter?:new ArbiterService($this->messages);
        $this->events=$events?:new EventService(); $this->completion=$completion?:new CompletionService($this->validator); $this->snapshots=$snapshots?:new PlayerSessionSnapshotBuilder();
    }
    private static function decode(?string $json):array{if($json===null||$json==='')return[];try{$v=json_decode($json,true,512,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}catch(\Throwable){return[];}}
    private static function valueText(mixed $value,string $unit):string{
        if($value===null)return '—'; if(is_bool($value))return $value?'Да':'Нет';
        if(is_numeric($value)){
            $n=(float)$value; $decimals=abs($n-round($n))<0.00001?0:2;
            // Final agreement text must preserve the exact monetary amount. Rounding
            // 960000 to "1,0 млн" changes the deal seen by the AI opponent.
            if(in_array($unit,['RUB','руб','руб.','₽'],true))return number_format($n,$decimals,',',' ').' руб.';
            if(in_array($unit,['percent','%'],true))return number_format($n,abs($n-round($n))<0.00001?0:1,',',' ').'%';
            if(in_array($unit,['months','month','месяц','месяца','месяцев','мес','мес.'],true))return number_format($n,0,',',' ').' мес.';
            if(in_array($unit,['days','day','день','дня','дней','дн','дн.'],true))return number_format($n,0,',',' ').' дн.';
            return number_format($n,$decimals,',',' ');
        }
        return (string)$value;
    }
    private static function publicAgreement(array $row):array{
        $package=self::decode($row['package_json']??null);return ['id'=>(int)$row['id'],'proposal_no'=>(int)$row['proposal_no'],'status'=>(string)$row['status'],
            'package'=>$package,'state_revision'=>(int)$row['state_revision'],'created_at'=>$row['created_at']??null,'responded_at'=>$row['responded_at']??null];
    }
    private static function lastNegotiationMessageId(int $sessionId): int {
        global $wpdb; $table=Schema::table('messages');
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(id),0) FROM `$table` WHERE session_id=%d AND channel IN ('dialogue','negotiation')",$sessionId));
    }
    private static function proposalText(array $package):string{
        $lines=['Предлагаю зафиксировать итоговое соглашение на следующих условиях:'];
        foreach($package as $entry){if(!is_array($entry)||($entry['value']??null)===null)continue;$lines[]='— '.(string)$entry['title'].': '.self::valueText($entry['value'],(string)($entry['unit']??''));}
        $lines[]='Подтвердите весь пакет целиком или укажите, какое условие вы не принимаете и что предлагаете взамен.';return implode("\n",$lines);
    }

    public function draft(int $sessionId,?int $expectedRevision=null):array{
        $session=Access::session($sessionId);if($session['status']!=='in_progress')throw new SessionCompletedException('Session is already completed.');if($session['processing_status']!=='idle')throw new StateConflictException('Wait until the current turn is complete.');
        if($expectedRevision!==null&&$expectedRevision>0&&$expectedRevision!==(int)$session['state_revision'])throw new StateConflictException('Negotiation state changed.');
        $lastMessageId=self::lastNegotiationMessageId($sessionId);
        $existing=$this->agreements->findDraftAtRevision($sessionId,(int)$session['state_revision']);
        if($existing){$validation=self::decode($existing['php_validation_json']??null);if((int)($validation['context_message_id']??-1)===$lastMessageId)return ['agreement'=>self::publicAgreement($existing),'validation'=>$validation['draft']??$validation,'idempotent'=>true];}
        $draft=$this->validator->buildDraft($sessionId);$id=$this->agreements->create($sessionId,['proposal_no'=>$this->agreements->nextProposalNo($sessionId),'status'=>'draft','package_json'=>$draft['package'],'created_by'=>'player','created_at_message_id'=>null,'php_validation_json'=>['draft'=>$draft,'context_message_id'=>$lastMessageId],'state_revision'=>(int)$session['state_revision']]);
        $row=$this->agreements->get($sessionId,$id,true);if(!$row)throw new \RuntimeException('Agreement draft unavailable.');return ['agreement'=>self::publicAgreement($row),'validation'=>$draft,'idempotent'=>false];
    }

    public function propose(int $sessionId,int $agreementId,?int $expectedRevision=null):array{
        $session=Access::session($sessionId);
        $agreement=$this->agreements->get($sessionId,$agreementId,true);if(!$agreement)throw new \InvalidArgumentException('Agreement unavailable.');
        if(in_array($agreement['status'],['accepted','partial','rejected'],true))return ['agreement'=>self::publicAgreement($agreement),'idempotent'=>true,'snapshot'=>$this->snapshots->build($sessionId)];
        if($session['status']!=='in_progress')throw new SessionCompletedException('Session is already completed.');
        if(!in_array($agreement['status'],['draft','proposed'],true))throw new \InvalidArgumentException('Agreement cannot be proposed.');
        $revision=(int)$agreement['state_revision'];if($expectedRevision!==null&&$expectedRevision>0&&$expectedRevision!==$revision)throw new StateConflictException('Agreement draft is stale.');
        if((int)$session['state_revision']!==$revision)throw new StateConflictException('Negotiation state changed. Create a new agreement draft.');
        $package=self::decode($agreement['package_json']??null);$formal=$this->validator->validatePackage($sessionId,$package,true,false);if(!$formal['valid'])throw new AgreementValidationException('Итоговый пакет пока нельзя предложить.',$formal);
        $validation=self::decode($agreement['php_validation_json']??null);
        if($agreement['status']==='draft' && (int)($validation['context_message_id']??-1)!==self::lastNegotiationMessageId($sessionId))throw new StateConflictException('Negotiation dialogue changed. Create a new agreement draft.');

        $lockToken='agreement-'.(function_exists('wp_generate_uuid4')?wp_generate_uuid4():bin2hex(random_bytes(16)));
        if(!$this->sessions->acquireProcessingLock($sessionId,$lockToken,180))throw new RecoveryBusyException('Agreement processing is already running.');
        try{
            $fresh=Access::session($sessionId);
            if($fresh['status']!=='in_progress')throw new SessionCompletedException('Session is already completed.');
            if(!in_array((string)$fresh['processing_status'],['idle','agreement_processing'],true))throw new StateConflictException('Another turn is being processed.');
            if($fresh['processing_status']==='idle'){
                if(!$this->sessions->setProcessingStatus($sessionId,'agreement_processing',['idle']))throw new StateConflictException('Another turn is being processed.');
            }else{
                $this->sessions->forceProcessingStatus($sessionId,'agreement_processing');
            }

            try{
                $proposalMessageId=(int)($agreement['created_at_message_id']??0);
                if($proposalMessageId<=0){
                    $proposalMessageId=$this->messages->appendAgreementProposal($sessionId,$agreementId,self::proposalText($package));
                    $validation['proposal_message_id']=$proposalMessageId;
                    $this->agreements->update($sessionId,$agreementId,['status'=>'proposed','created_at_message_id'=>$proposalMessageId,'php_validation_json'=>$validation]);
                    $agreement=$this->agreements->get($sessionId,$agreementId,true)?:$agreement;
                }
                $candidate=is_array($validation['opponent_candidate']??null)?$validation['opponent_candidate']:null;
                $reply=$this->messages->findReplyTo($sessionId,$proposalMessageId);
                if(!$candidate&&$reply){$candidate=$this->responses->classifyExisting((string)($reply['content']??''),$package);}
                if(!$candidate){$candidate=$this->responses->decide($sessionId,$proposalMessageId,$package);}
                if($candidate){$validation['opponent_candidate']=$candidate;$validation['formal_before_proposal']=$formal;$this->agreements->update($sessionId,$agreementId,['php_validation_json'=>$validation]);}
                if(!$reply){$replyId=$this->messages->appendOpponent($sessionId,$proposalMessageId,(string)$candidate['reply']);$reply=$this->messages->findById($sessionId,$replyId);}
                if(!$reply)throw new \RuntimeException('Opponent agreement response unavailable.');

                if($candidate['decision']==='accept'){
                    $this->events->appendBatch($sessionId,$reply,[['event_type'=>'agreement_acceptance_candidate','actor'=>'opponent','target_type'=>'session','target_id'=>$sessionId,'confidence'=>1.0,'payload'=>['agreement_id'=>$agreementId]]],'neg-agreement-1.0');
                    $this->messages->setAnalysisStatus($sessionId,(int)$reply['id'],'complete');
                    try{$completion=$this->completion->completeAgreement($sessionId,$agreementId);}
                    catch(AgreementValidationException $blocked){$validation['php_blocked_after_accept']=$blocked->details;$this->agreements->update($sessionId,$agreementId,['status'=>'rejected','php_validation_json'=>$validation,'responded_at'=>current_time('mysql',true)]);$this->sessions->setProcessingStatus($sessionId,'idle',['agreement_processing']);return ['agreement'=>self::publicAgreement($this->agreements->get($sessionId,$agreementId,true)),'decision'=>'blocked','validation'=>['message'=>'Пакет не может быть зафиксирован из-за обязательного ограничения сценария.'],'snapshot'=>$this->snapshots->build($sessionId)];}
                    return ['agreement'=>self::publicAgreement($this->agreements->get($sessionId,$agreementId,true)),'decision'=>'accept','completion'=>$completion,'snapshot'=>$this->snapshots->build($sessionId)];
                }

                $analysis=$this->arbiter->analyze($sessionId,(int)$reply['id']);$status=$candidate['decision']==='partial'?'partial':'rejected';
                $this->agreements->update($sessionId,$agreementId,['status'=>$status,'responded_at'=>current_time('mysql',true)]);$this->sessions->setProcessingStatus($sessionId,'idle',['agreement_processing']);
                return ['agreement'=>self::publicAgreement($this->agreements->get($sessionId,$agreementId,true)),'decision'=>$candidate['decision'],'arbiter'=>$analysis,'snapshot'=>$this->snapshots->build($sessionId)];
            }catch(\Throwable $e){$fresh=Access::session($sessionId);if($fresh['status']==='in_progress'&&$fresh['processing_status']==='agreement_processing')$this->sessions->setProcessingStatus($sessionId,'idle',['agreement_processing']);throw $e;}
        }finally{$this->sessions->releaseProcessingLock($sessionId,$lockToken);}
    }

}
