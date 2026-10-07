<?php
namespace CKM\EffectiveSales;
if (!defined('ABSPATH')) { exit; }

/**
 * Trusted bridge between a platform-owned sideband worker and the WordPress Inbox/CRM.
 * The worker opens the long-lived WebSocket to OpenAI; WordPress only stores call state,
 * transcript fragments and explicit transfer/hangup commands.
 */
final class SalesLiveSidebandService {
    private const CALLS_OPTION='ckm_sales_live_sideband_calls_v1';
    private const HEARTBEAT_OPTION='ckm_sales_live_sideband_worker_heartbeat_v1';
    private const MAX_CALLS=200;
    private const CLAIM_TTL=45;
    private const WORKER_ONLINE_TTL=90;

    private static function clean(string $v,int $max=1000,bool $trim=true): string {
        $v=wp_strip_all_tags($v);if($trim)$v=trim($v);
        return function_exists('mb_substr')?mb_substr($v,0,$max,'UTF-8'):substr($v,0,$max);
    }
    private static function calls(): array {$v=get_option(self::CALLS_OPTION,[]);return is_array($v)?$v:[];}
    private static function save(array $calls): void {
        uasort($calls,static fn($a,$b): int=>strcmp((string)($b['updated_at']??''),(string)($a['updated_at']??'')));
        update_option(self::CALLS_OPTION,array_slice($calls,0,self::MAX_CALLS,true),false);
    }
    private static function secret(): string {return class_exists(SalesLiveSipService::class)?SalesLiveSipService::sidebandSecretForServer():'';}
    private static function assertSecret(string $given): void {$expected=self::secret();if($expected===''||$given===''||!hash_equals($expected,$given))throw new \InvalidArgumentException('Sideband worker не авторизован.');}
    public static function workerOnline(): bool {$v=get_option(self::HEARTBEAT_OPTION,0);return is_numeric($v)&&((int)$v)>=time()-self::WORKER_ONLINE_TTL;}
    public static function workerStatus(): array {$ts=(int)get_option(self::HEARTBEAT_OPTION,0);return ['configured'=>self::secret()!=='','online'=>self::workerOnline(),'last_seen'=>$ts>0?gmdate('c',$ts):'','claim_url'=>rest_url('ckm/v1/sales/live-sip/sideband/claim'),'event_url'=>rest_url('ckm/v1/sales/live-sip/sideband/event'),'heartbeat_url'=>rest_url('ckm/v1/sales/live-sip/sideband/heartbeat')];}

    public static function registerCall(string $sessionId,array $ctx): void {
        $sessionId=self::clean($sessionId,190);if($sessionId==='')return;$uid=(int)($ctx['owner_user_id']??0);$scope=self::clean((string)($ctx['scope']??'default'),190);$scriptId=self::clean((string)($ctx['script_id']??''),190);if($uid<1||$scriptId==='')return;
        $calls=self::calls();$row=is_array($calls[$sessionId]??null)?$calls[$sessionId]:[];$now=gmdate('c');$contact=self::clean((string)($ctx['lead_contact']??''),180);$row=array_merge($row,[
            'session_id'=>$sessionId,'owner_user_id'=>$uid,'scope'=>$scope!==''?$scope:'default','script_id'=>$scriptId,'tenant_id'=>(int)($ctx['tenant_id']??0),'integration_id'=>self::clean((string)($ctx['integration_id']??''),190),'direction'=>(string)($ctx['direction']??'inbound'),'lead_contact'=>$contact,'status'=>'active','call_status'=>(string)($ctx['call_status']??'initialized'),'created_at'=>(string)($row['created_at']??$now),'updated_at'=>$now,'claimed_until'=>(int)($row['claimed_until']??0),'event_ids'=>is_array($row['event_ids']??null)?$row['event_ids']:[],
        ]);$calls[$sessionId]=$row;self::save($calls);
        SalesAiSellerWorkspaceService::recordExternalStart(['id'=>$sessionId,'owner_user_id'=>$uid,'script_id'=>$scriptId,'scope'=>$row['scope'],'control_mode'=>'ai'],'Телефон '.($contact!==''?$contact:''),$contact,'phone');
        SalesAiSellerWorkspaceService::recordLiveCallState(['id'=>$sessionId,'owner_user_id'=>$uid],(string)$row['call_status'],false,[]);
    }

    public static function claim(string $secret,int $limit=10): array {
        self::assertSecret($secret);update_option(self::HEARTBEAT_OPTION,time(),false);$calls=self::calls();$now=time();$claimed=[];$limit=max(1,min(25,$limit));
        foreach($calls as $sid=>&$row){if(count($claimed)>=$limit)break;if(!is_array($row)||!in_array((string)($row['status']??''),['active','claimed'],true))continue;if((int)($row['claimed_until']??0)>$now)continue;$row['status']='claimed';$row['claimed_until']=$now+self::CLAIM_TTL;$row['updated_at']=gmdate('c');$claimed[]=['session_id'=>(string)$sid,'project_id'=>SalesLiveSipService::platformProjectId(),'direction'=>(string)($row['direction']??''),'script_id'=>(string)($row['script_id']??''),'tenant_id'=>(int)($row['tenant_id']??0)];}unset($row);self::save($calls);return $claimed;
    }
    public static function heartbeat(string $secret,?string $sessionId=null): array {
        self::assertSecret($secret);update_option(self::HEARTBEAT_OPTION,time(),false);if($sessionId!==null&&$sessionId!==''){$calls=self::calls();if(isset($calls[$sessionId])&&is_array($calls[$sessionId])){$calls[$sessionId]['claimed_until']=time()+self::CLAIM_TTL;$calls[$sessionId]['status']='claimed';$calls[$sessionId]['updated_at']=gmdate('c');self::save($calls);}}return self::workerStatus();
    }
    private static function context(array $row): array {return ['id'=>(string)$row['session_id'],'owner_user_id'=>(int)$row['owner_user_id'],'script_id'=>(string)$row['script_id'],'scope'=>(string)$row['scope'],'control_mode'=>'ai'];}
    private static function dedupe(array &$row,string $eventId): bool {if($eventId==='')return false;$ids=is_array($row['event_ids']??null)?array_values($row['event_ids']):[];if(in_array($eventId,$ids,true))return true;$ids[]=$eventId;$row['event_ids']=array_slice($ids,-200);return false;}
    public static function ingest(string $secret,string $sessionId,array $event): array {
        self::assertSecret($secret);update_option(self::HEARTBEAT_OPTION,time(),false);$sessionId=self::clean($sessionId,190);$calls=self::calls();if(!isset($calls[$sessionId])||!is_array($calls[$sessionId]))throw new \InvalidArgumentException('Sideband session не зарегистрирована.');$row=&$calls[$sessionId];$type=self::clean((string)($event['type']??''),100);$eventId=self::clean((string)($event['event_id']??''),190);if(self::dedupe($row,$eventId)){self::save($calls);return ['duplicate'=>true,'event_type'=>$type];}
        $allowed=['session.started','session.input_transcript.delta','session.output_transcript.delta','session.usage.updated','transport.ringing','transport.answered','transport.failed','transport.dtmf.received','session.closed','error'];if(!in_array($type,$allowed,true))return ['ignored'=>true,'event_type'=>$type];
        $row['claimed_until']=time()+self::CLAIM_TTL;$row['updated_at']=gmdate('c');$ctx=self::context($row);
        if($type==='session.input_transcript.delta'||$type==='session.output_transcript.delta'){$role=$type==='session.input_transcript.delta'?'user':'assistant';$delta=(string)($event['delta']??'');SalesAiSellerWorkspaceService::recordLiveTranscriptDelta($ctx,$role,$delta,(int)($event['start_ms']??0),(int)($event['end_ms']??0),$eventId);}
        elseif($type==='transport.ringing'){$row['call_status']='ringing';SalesAiSellerWorkspaceService::recordLiveCallState($ctx,'ringing',false,[]);}
        elseif($type==='transport.answered'||$type==='session.started'){$row['call_status']='answered';SalesAiSellerWorkspaceService::recordLiveCallState($ctx,'answered',false,[]);}
        elseif($type==='session.usage.updated'){$row['usage']=is_array($event['usage']??null)?$event['usage']:[];SalesAiSellerWorkspaceService::recordLiveCallState($ctx,(string)($row['call_status']??'active'),false,(array)$row['usage']);}
        elseif($type==='transport.failed'||$type==='error'){$row['call_status']='failed';$row['status']='closed';$row['failure']=self::clean((string)($event['error']['message']??$event['message']??''),800);SalesAiSellerWorkspaceService::recordLiveCallState($ctx,'failed',true,(array)($row['usage']??[]));}
        elseif($type==='session.closed'){$row['call_status']='completed';$row['status']='closed';if(is_array($event['usage']??null))$row['usage']=$event['usage'];SalesAiSellerWorkspaceService::recordLiveCallState($ctx,'completed',true,(array)($row['usage']??[]));}
        self::save($calls);return ['accepted'=>true,'event_type'=>$type,'session_id'=>$sessionId];
    }
    private static function ownedRow(string $scriptId,string $sessionId): array {
        $uid=get_current_user_id();if($uid<1)throw new \RuntimeException('Требуется вход.');$scope=SalesScriptService::currentScopeKey();$calls=self::calls();$row=is_array($calls[$sessionId]??null)?$calls[$sessionId]:null;if(!$row||(int)$row['owner_user_id']!==$uid||(string)$row['scope']!==$scope||(string)$row['script_id']!==$scriptId)throw new \InvalidArgumentException('Телефонный диалог не найден.');return $row;
    }
    public static function dialogStatus(string $scriptId,string $sessionId): array {
        try{$row=self::ownedRow($scriptId,$sessionId);}catch(\Throwable){return ['phone'=>false];}$target='';$integration=SalesPartnerIntegrationService::integrationForSession((string)$row['integration_id'],(int)$row['owner_user_id'],(string)$row['scope'],(string)$row['script_id'],'sip');if($integration){$cred=SalesPartnerIntegrationService::credentialsForRow($integration);$target=(string)($cred['handoff_target_uri']??'');}return ['phone'=>true,'session_id'=>$sessionId,'call_status'=>(string)($row['call_status']??'active'),'active'=>(string)($row['status']??'')!=='closed','handoff_target'=>$target,'can_transfer'=>$target!==''];
    }
    public static function transferCurrentUser(string $scriptId,string $sessionId): array {
        $row=self::ownedRow($scriptId,$sessionId);$integration=SalesPartnerIntegrationService::integrationForSession((string)$row['integration_id'],(int)$row['owner_user_id'],(string)$row['scope'],(string)$row['script_id'],'sip');if(!$integration)throw new \RuntimeException('Direct SIP подключение этого звонка не найдено.');$cred=SalesPartnerIntegrationService::credentialsForRow($integration);$target=SalesTelephonyService::normalizeHandoffTarget((string)($cred['handoff_target_uri']??''),false);(new SalesLiveSipService())->refer($sessionId,$target);$calls=self::calls();if(isset($calls[$sessionId])){$calls[$sessionId]['call_status']='transferred';$calls[$sessionId]['status']='closed';$calls[$sessionId]['updated_at']=gmdate('c');self::save($calls);}SalesAiSellerWorkspaceService::recordLiveTransfer(self::context($row),$target);return ['ok'=>true,'target_uri'=>$target];
    }
    public static function hangupCurrentUser(string $scriptId,string $sessionId): array {
        $row=self::ownedRow($scriptId,$sessionId);(new SalesLiveSipService())->hangup($sessionId);$calls=self::calls();if(isset($calls[$sessionId])){$calls[$sessionId]['call_status']='ended_by_operator';$calls[$sessionId]['status']='closed';$calls[$sessionId]['updated_at']=gmdate('c');self::save($calls);}SalesAiSellerWorkspaceService::recordLiveCallState(self::context($row),'ended_by_operator',true,[]);return ['ok'=>true];
    }
}
