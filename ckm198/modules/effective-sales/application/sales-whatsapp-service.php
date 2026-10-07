<?php
namespace CKM\EffectiveSales;
if (!defined('ABSPATH')) { exit; }

/**
 * Partner-owned WhatsApp Cloud API connector.
 * One WABA integration owns one WABA-level callback override and can be assigned to one AI seller.
 */
final class SalesWhatsAppService {
    private const GRAPH_VERSION='v26.0';
    private const GRAPH_BASE='https://graph.facebook.com/';
    private const UPDATE_TTL=86400;
    private $transport;

    public function __construct(?callable $transport=null){$this->transport=$transport;}

    private static function clean(string $v,int $max=1200): string {
        $v=trim(wp_strip_all_tags($v));
        return function_exists('mb_substr')?mb_substr($v,0,$max,'UTF-8'):substr($v,0,$max);
    }
    private static function randomHex(int $bytes=24): string {
        try{return bin2hex(random_bytes($bytes));}catch(\Throwable){return hash('sha256',wp_generate_uuid4().microtime(true));}
    }
    private static function graphVersion(): string {
        $v=self::GRAPH_VERSION;
        if(function_exists('apply_filters'))$v=(string)apply_filters('ckm_sales_whatsapp_graph_version',$v);
        return preg_match('/^v\d+\.\d+$/',$v)?$v:self::GRAPH_VERSION;
    }
    private static function normalizeId(string $v,string $label): string {
        $v=trim($v);if(!preg_match('/^\d{5,32}$/',$v))throw new \InvalidArgumentException($label.' выглядит некорректно. Скопируйте числовой ID из Meta Business Manager.');return $v;
    }
    private static function normalizeToken(string $token): string {
        $token=trim($token);if(strlen($token)<40||strlen($token)>2048||preg_match('/\s/',$token))throw new \InvalidArgumentException('Access Token WhatsApp выглядит некорректно. Скопируйте токен целиком.');return $token;
    }
    private static function normalizeAppSecret(string $secret): string {
        $secret=trim($secret);if(!preg_match('/^[A-Fa-f0-9]{32,128}$/',$secret))throw new \InvalidArgumentException('App Secret выглядит некорректно. Скопируйте App Secret приложения Meta целиком.');return strtolower($secret);
    }
    private function api(string $token,string $method,string $path,array $query=[],array $body=[]): array {
        if($this->transport){$r=($this->transport)($method,$path,$query,$body,$token,self::graphVersion());if(!is_array($r))throw new \RuntimeException('WhatsApp-коннектор вернул некорректный ответ.');return $r;}
        $url=self::GRAPH_BASE.self::graphVersion().$path;if($query)$url.='?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
        $args=['method'=>$method,'timeout'=>20,'redirection'=>0,'headers'=>['Authorization'=>'Bearer '.$token,'Accept'=>'application/json']];
        if($method!=='GET'&&$body!==[]){$args['headers']['Content-Type']='application/json';$args['body']=wp_json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
        $response=wp_safe_remote_request($url,$args);if(is_wp_error($response))throw new \RuntimeException('WhatsApp Cloud API недоступен: '.$response->get_error_message());
        $code=(int)wp_remote_retrieve_response_code($response);$raw=(string)wp_remote_retrieve_body($response);$json=$raw!==''?json_decode($raw,true):[];
        if($code<200||$code>=300||!is_array($json)){$msg='';if(is_array($json)){$err=is_array($json['error']??null)?$json['error']:[];$msg=self::clean((string)($err['message']??$json['message']??''),300);}throw new \RuntimeException($msg!==''?'WhatsApp: '.$msg:'WhatsApp Cloud API вернул ошибку HTTP '.$code.'.');}
        if(isset($json['error'])&&is_array($json['error']))throw new \RuntimeException('WhatsApp: '.self::clean((string)($json['error']['message']??'Ошибка API'),300));
        return $json;
    }
    private static function statusFromIntegration(array $row): array {
        $meta=is_array($row['public_meta']??null)?$row['public_meta']:[];
        return ['connected'=>(string)($row['status']??'')==='connected','integration_id'=>(string)($row['id']??''),'tenant_id'=>(int)($row['tenant_id']??0),'waba_id'=>(string)($row['account_key']??''),'phone_number_id'=>(string)($meta['phone_number_id']??''),'display_phone_number'=>(string)($row['account_username']??''),'verified_name'=>(string)($row['account_name']??''),'connected_at'=>(string)($row['created_at']??''),'webhook_url'=>(string)($meta['webhook_url']??''),'graph_version'=>(string)($meta['graph_version']??self::graphVersion()),'central'=>true,'label'=>(string)($row['label']??'WhatsApp')];
    }
    public static function statusForScript(array $script): array {
        if(class_exists(SalesPartnerIntegrationService::class)){$bindings=SalesPartnerIntegrationService::bindingsForCurrentScript($script);if(isset($bindings['whatsapp']))return self::statusFromIntegration((array)$bindings['whatsapp']);}
        return ['connected'=>false,'integration_id'=>'','tenant_id'=>0,'waba_id'=>'','phone_number_id'=>'','display_phone_number'=>'','verified_name'=>'','connected_at'=>'','webhook_url'=>'','graph_version'=>self::graphVersion(),'central'=>true,'label'=>'WhatsApp'];
    }

    public function connectPartnerIntegration(string $label,string $phoneNumberId,string $wabaId,string $accessToken,string $appSecret): array {
        if(!class_exists(SalesPartnerIntegrationService::class))throw new \RuntimeException('Хранилище партнёрских интеграций недоступно.');
        $phone=self::normalizeId($phoneNumberId,'Phone Number ID');$waba=self::normalizeId($wabaId,'WhatsApp Business Account ID');$token=self::normalizeToken($accessToken);$appSecret=self::normalizeAppSecret($appSecret);
        $phoneInfo=$this->api($token,'GET','/'.$phone,['fields'=>'id,display_phone_number,verified_name,quality_rating']);
        if((string)($phoneInfo['id']??'')!==$phone)throw new \RuntimeException('WhatsApp подтвердил токен, но вернул другой Phone Number ID.');
        SalesPartnerIntegrationService::assertAccountAvailable('whatsapp',$waba);
        $hook=self::randomHex(24);$verify=self::randomHex(24);$url=rest_url('ckm/v1/sales/whatsapp/webhook/'.$hook);if(!str_starts_with(strtolower($url),'https://'))throw new \RuntimeException('Для WhatsApp webhook требуется HTTPS.');
        $display=self::clean((string)($phoneInfo['display_phone_number']??''),80);$name=self::clean((string)($phoneInfo['verified_name']??'WhatsApp Business'),120);
        // Meta requires a baseline WABA subscription. The CKM row must exist before the
        // override call because Meta immediately GET-verifies the alternate callback URL.
        $this->api($token,'POST','/'.$waba.'/subscribed_apps',[],[]);
        $local=SalesPartnerIntegrationService::createVerifiedWhatsApp($label,['access_token'=>$token,'phone_number_id'=>$phone,'business_account_id'=>$waba,'app_secret'=>$appSecret],['account_key'=>$waba,'account_name'=>$name,'account_username'=>$display,'phone_number_id'=>$phone,'waba_id'=>$waba,'graph_version'=>self::graphVersion(),'quality_rating'=>(string)($phoneInfo['quality_rating']??'')],$hook,$verify);
        try{
            $this->api($token,'POST','/'.$waba.'/subscribed_apps',[],['override_callback_uri'=>$url,'verify_token'=>$verify]);
            SalesPartnerIntegrationService::markCheck((string)$local['id'],true,'');
            return SalesPartnerIntegrationService::findCurrentTenant((string)$local['id'])??$local;
        }catch(\Throwable $e){try{SalesPartnerIntegrationService::deleteCurrentTenant((string)$local['id']);}catch(\Throwable){}try{$this->api($token,'DELETE','/'.$waba.'/subscribed_apps');}catch(\Throwable){}throw $e;}
    }
    public function disconnectPartnerIntegration(string $integrationId): array {
        $row=SalesPartnerIntegrationService::rawCurrentTenant($integrationId);if(!$row||((string)($row['provider']??''))!=='whatsapp')throw new \InvalidArgumentException('WhatsApp-подключение не найдено.');$remote=false;
        try{$cred=SalesPartnerIntegrationService::credentialsForRow($row);$token=self::normalizeToken((string)($cred['access_token']??''));$waba=self::normalizeId((string)($cred['business_account_id']??''),'WhatsApp Business Account ID');$this->api($token,'DELETE','/'.$waba.'/subscribed_apps');$remote=true;}catch(\Throwable){}
        $deleted=SalesPartnerIntegrationService::deleteCurrentTenant($integrationId);$deleted['remote_webhook_deleted']=$remote;return $deleted;
    }
    private static function containsUrl(mixed $value,string $needle): bool {
        if(is_string($value))return $needle!==''&&hash_equals($needle,$value);if(!is_array($value))return false;foreach($value as $v)if(self::containsUrl($v,$needle))return true;return false;
    }
    public function testPartnerIntegration(string $integrationId): array {
        $row=SalesPartnerIntegrationService::rawCurrentTenant($integrationId);if(!$row||((string)($row['provider']??''))!=='whatsapp')throw new \InvalidArgumentException('WhatsApp-подключение не найдено.');
        try{$cred=SalesPartnerIntegrationService::credentialsForRow($row);$token=self::normalizeToken((string)($cred['access_token']??''));$phone=self::normalizeId((string)($cred['phone_number_id']??''),'Phone Number ID');$waba=self::normalizeId((string)($cred['business_account_id']??''),'WhatsApp Business Account ID');$phoneInfo=$this->api($token,'GET','/'.$phone,['fields'=>'id,display_phone_number,verified_name,quality_rating']);$subs=$this->api($token,'GET','/'.$waba.'/subscribed_apps');$meta=json_decode((string)($row['public_meta']??''),true);$expected=is_array($meta)?(string)($meta['webhook_url']??''):'';if($expected!==''&&!self::containsUrl($subs,$expected))throw new \RuntimeException('WhatsApp API отвечает, но WABA не показывает callback этого подключения. Переподключите канал.');SalesPartnerIntegrationService::markCheck($integrationId,true,'');return ['ok'=>true,'phone'=>$phoneInfo,'webhook_ready'=>true];}catch(\Throwable $e){SalesPartnerIntegrationService::markCheck($integrationId,false,$e->getMessage());throw $e;}
    }

    public function verifyChallenge(string $hook,array $query): string {
        if(!preg_match('/^[a-f0-9]{48,64}$/',$hook))throw new \InvalidArgumentException('WhatsApp webhook не найден.');$row=SalesPartnerIntegrationService::rowByProviderWebhook('whatsapp',$hook);if(!$row)throw new \InvalidArgumentException('WhatsApp webhook не найден.');
        $mode=(string)($query['hub.mode']??$query['hub_mode']??'');$token=(string)($query['hub.verify_token']??$query['hub_verify_token']??'');$challenge=self::clean((string)($query['hub.challenge']??$query['hub_challenge']??''),200);$expected=SalesPartnerIntegrationService::webhookSecretForRow($row);
        if($mode!=='subscribe'||$token===''||!hash_equals($expected,$token)||$challenge==='')throw new \InvalidArgumentException('WhatsApp webhook verification не пройдена.');return $challenge;
    }
    public static function serveRawChallenge($served,$result,$request,$server){
        if($served||!is_object($result)||!method_exists($result,'get_headers'))return $served;$headers=(array)$result->get_headers();$challenge=(string)($headers['X-CKM-WhatsApp-Challenge']??'');if($challenge==='')return $served;
        if(!headers_sent()){header('Content-Type: text/plain; charset=UTF-8');header('Cache-Control: no-store, private');}
        echo $challenge;return true;
    }
    private static function headerSignature(): string {return trim((string)($_SERVER['HTTP_X_HUB_SIGNATURE_256']??''));}
    private static function verifySignature(array $row,string $raw): void {
        $cred=SalesPartnerIntegrationService::credentialsForRow($row);$secret=self::normalizeAppSecret((string)($cred['app_secret']??''));$actual=self::headerSignature();if(!str_starts_with($actual,'sha256='))throw new \InvalidArgumentException('WhatsApp webhook signature отсутствует.');$expected='sha256='.hash_hmac('sha256',$raw,$secret);if(!hash_equals($expected,$actual))throw new \InvalidArgumentException('WhatsApp webhook signature не совпадает.');
    }
    private static function leadName(array $value,string $waId): string {$contacts=is_array($value['contacts']??null)?$value['contacts']:[];foreach($contacts as $c){if(!is_array($c)||(string)($c['wa_id']??'')!==$waId)continue;$profile=is_array($c['profile']??null)?$c['profile']:[];$name=self::clean((string)($profile['name']??''),120);if($name!=='')return $name;}return 'WhatsApp-клиент';}
    private function sendTextByRow(array $row,string $waId,string $text): void {
        $cred=SalesPartnerIntegrationService::credentialsForRow($row);$token=self::normalizeToken((string)($cred['access_token']??''));$phone=self::normalizeId((string)($cred['phone_number_id']??''),'Phone Number ID');$this->api($token,'POST','/'.$phone.'/messages',[],['messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$waId,'type'=>'text','text'=>['preview_url'=>false,'body'=>self::clean($text,4000)]]);
    }
    public function webhook(string $hook,array $payload,string $raw): array {
        if(!preg_match('/^[a-f0-9]{48,64}$/',$hook))throw new \InvalidArgumentException('WhatsApp webhook не найден.');$row=SalesPartnerIntegrationService::rowByProviderWebhook('whatsapp',$hook);if(!$row)throw new \InvalidArgumentException('WhatsApp webhook не найден.');self::verifySignature($row,$raw);
        if((string)($payload['object']??'')!=='whatsapp_business_account')return ['ignored'=>true,'reason'=>'unsupported_object'];if((int)($row['assigned_user_id']??0)<1||(string)($row['assigned_scope']??'')===''||(string)($row['assigned_script_id']??'')==='')return ['ignored'=>true,'reason'=>'not_assigned'];
        $cred=SalesPartnerIntegrationService::credentialsForRow($row);$expectedPhone=(string)($cred['phone_number_id']??'');$messages=[];$valueFor=[];
        foreach((array)($payload['entry']??[]) as $entry){if(!is_array($entry))continue;foreach((array)($entry['changes']??[]) as $change){if(!is_array($change)||(string)($change['field']??'')!=='messages')continue;$value=is_array($change['value']??null)?$change['value']:[];$metadata=is_array($value['metadata']??null)?$value['metadata']:[];if($expectedPhone!==''&&(string)($metadata['phone_number_id']??'')!==$expectedPhone)continue;foreach((array)($value['messages']??[]) as $message){if(is_array($message)){$messages[]=$message;$valueFor=$value;}}}}
        if(!$messages)return ['ignored'=>true,'reason'=>'no_incoming_message'];$processed=0;$lastSession='';$replied=false;$queued=false;
        foreach($messages as $message){$mid=self::clean((string)($message['id']??''),220);$dedupe=$mid!==''?'ckm_sales_wa_update_'.hash('sha256',$hook.'|'.$mid):'';if($dedupe!==''&&get_transient($dedupe))continue;$waId=self::clean((string)($message['from']??''),40);if($waId===''||!preg_match('/^\d{6,20}$/',$waId)){if($dedupe!=='')set_transient($dedupe,1,self::UPDATE_TTL);continue;}$type=(string)($message['type']??'');$text='';if($type==='text'&&is_array($message['text']??null))$text=self::clean((string)($message['text']['body']??''),1200);
            if($text===''){$this->sendTextByRow($row,$waId,'Пока я умею работать с текстовыми сообщениями. Напишите вопрос текстом.');if($dedupe!=='')set_transient($dedupe,1,self::UPDATE_TTL);continue;}
            $uid=(int)$row['assigned_user_id'];$scope=(string)$row['assigned_scope'];$scriptId=(string)$row['assigned_script_id'];$script=SalesScriptService::findForOwner($uid,$scope,$scriptId);if(!$script||empty($script['ai_seller_enabled']))return ['ignored'=>true,'reason'=>'agent_inactive'];$sellerToken=(string)($script['ai_seller_token']??'');if($sellerToken==='')return ['ignored'=>true,'reason'=>'agent_inactive'];$integrationId=(string)($row['integration_id']??'');
            $result=(new SalesAiSellerService())->externalMessage($sellerToken,'whatsapp',$waId,self::leadName($valueFor,$waId),'WhatsApp +'.$waId,$text,$integrationId);$reply=self::clean((string)($result['reply']??''),1200);if($reply!==''){$this->sendTextByRow($row,$waId,$reply);$replied=true;}$lastSession=(string)($result['session_id']??'');$queued=$queued||!empty($result['queued_for_operator']);$processed++;if($dedupe!=='')set_transient($dedupe,1,self::UPDATE_TTL);
        }
        return ['ignored'=>$processed===0,'processed'=>$processed,'session_id'=>$lastSession,'replied'=>$replied,'queued_for_operator'=>$queued];
    }
    public function sendForSession(array $session,string $text): bool {
        if((string)($session['channel']??'web')!=='whatsapp')return false;$uid=(int)($session['owner_user_id']??0);$scope=(string)($session['scope']??'default');$scriptId=(string)($session['script_id']??'');$waId=(string)($session['external_thread_id']??'');if($uid<1||$scope===''||$scriptId===''||$waId==='')throw new \RuntimeException('WhatsApp-сессия повреждена.');$integrationId=(string)($session['external_integration_id']??'');$row=$integrationId!==''?SalesPartnerIntegrationService::integrationForSession($integrationId,$uid,$scope,$scriptId,'whatsapp'):SalesPartnerIntegrationService::integrationForOwnerScript($uid,$scope,$scriptId,'whatsapp');if(!$row)throw new \RuntimeException('WhatsApp-канал отключён или больше не назначен этому ИИ-продавцу.');$this->sendTextByRow($row,$waId,$text);return true;
    }
}
