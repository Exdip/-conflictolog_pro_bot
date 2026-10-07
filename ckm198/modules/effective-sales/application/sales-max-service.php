<?php
namespace CKM\EffectiveSales;
if (!defined('ABSPATH')) { exit; }

/**
 * MAX Bot API channel for partner-owned AI sellers.
 * Uses the official HTTPS Bot API and tenant-scoped encrypted credentials.
 */
final class SalesMaxService {
    private const API_BASE='https://platform-api2.max.ru';
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
    private static function normalizeToken(string $token): string {
        $token=trim($token);
        if(strlen($token)<10||strlen($token)>1024||preg_match('/[\x00-\x20\x7f]/',$token))throw new \InvalidArgumentException('API token MAX выглядит некорректно. Скопируйте токен целиком из MAX для бизнеса.');
        return $token;
    }
    private function api(string $token,string $method,string $path,array $query=[],?array $body=null): array {
        $method=strtoupper($method);$path='/'.ltrim($path,'/');
        if($this->transport){$r=($this->transport)($method,$path,$query,$body,$token);if(!is_array($r))throw new \RuntimeException('MAX-коннектор вернул некорректный ответ.');return $r;}
        $url=self::API_BASE.$path;if($query)$url=add_query_arg($query,$url);
        $args=['method'=>$method,'timeout'=>15,'redirection'=>0,'headers'=>['Accept'=>'application/json','Authorization'=>$token]];
        if($body!==null){$args['headers']['Content-Type']='application/json';$args['body']=wp_json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
        $response=wp_safe_remote_request($url,$args);
        if(is_wp_error($response))throw new \RuntimeException('MAX API недоступен: '.$response->get_error_message());
        $code=(int)wp_remote_retrieve_response_code($response);$raw=(string)wp_remote_retrieve_body($response);$json=$raw!==''?json_decode($raw,true):[];
        if($code<200||$code>=300||!is_array($json)){$msg=is_array($json)?self::clean((string)($json['message']??$json['error']??''),260):'';throw new \RuntimeException($msg!==''?'MAX: '.$msg:'MAX API вернул ошибку HTTP '.$code.'.');}
        if(array_key_exists('success',$json)&&$json['success']===false){$msg=self::clean((string)($json['message']??''),260);throw new \RuntimeException($msg!==''?'MAX: '.$msg:'MAX API не выполнил запрос.');}
        return $json;
    }
    private static function statusFromIntegration(array $row): array {
        $meta=is_array($row['public_meta']??null)?$row['public_meta']:[];$username=self::clean((string)($row['account_username']??''),80);
        return ['connected'=>(string)($row['status']??'')==='connected','integration_id'=>(string)($row['id']??''),'tenant_id'=>(int)($row['tenant_id']??0),'bot_id'=>(string)($row['account_key']??''),'bot_username'=>$username,'bot_name'=>self::clean((string)($row['account_name']??''),120),'connected_at'=>(string)($row['created_at']??''),'webhook_url'=>(string)($meta['webhook_url']??''),'api_base'=>self::API_BASE,'central'=>true,'label'=>(string)($row['label']??'MAX')];
    }
    public static function statusForScript(array $script): array {
        if(class_exists(SalesPartnerIntegrationService::class)){$bindings=SalesPartnerIntegrationService::bindingsForCurrentScript($script);if(isset($bindings['max']))return self::statusFromIntegration((array)$bindings['max']);}
        return ['connected'=>false,'integration_id'=>'','tenant_id'=>0,'bot_id'=>'','bot_username'=>'','bot_name'=>'','connected_at'=>'','webhook_url'=>'','api_base'=>self::API_BASE,'central'=>true,'label'=>'MAX'];
    }

    public function connectPartnerIntegration(string $label,string $apiToken): array {
        if(!class_exists(SalesPartnerIntegrationService::class))throw new \RuntimeException('Хранилище партнёрских интеграций недоступно.');
        $token=self::normalizeToken($apiToken);$bot=$this->api($token,'GET','/me');$botId=(string)($bot['user_id']??'');$username=self::clean((string)($bot['username']??''),80);$name=self::clean((string)($bot['first_name']??$bot['name']??'MAX-бот'),120);
        if($botId===''||empty($bot['is_bot']))throw new \RuntimeException('MAX подтвердил токен, но не вернул корректные данные бота.');
        SalesPartnerIntegrationService::assertAccountAvailable('max',$botId);
        $hook=self::randomHex(24);$secret=self::randomHex(20);$url=rest_url('ckm/v1/sales/max/webhook/'.$hook);if(!str_starts_with(strtolower($url),'https://'))throw new \RuntimeException('Для MAX webhook требуется HTTPS.');
        $this->api($token,'POST','/subscriptions',[],['url'=>$url,'update_types'=>['message_created'],'secret'=>$secret]);
        try{return SalesPartnerIntegrationService::createVerifiedMax($label,['api_token'=>$token,'bot_id'=>$botId],['account_key'=>$botId,'account_name'=>$name,'account_username'=>$username,'api_base'=>self::API_BASE],$hook,$secret);}catch(\Throwable $e){try{$this->api($token,'DELETE','/subscriptions',['url'=>$url]);}catch(\Throwable){}throw $e;}
    }
    public function disconnectPartnerIntegration(string $integrationId): array {
        $row=SalesPartnerIntegrationService::rawCurrentTenant($integrationId);if(!$row||((string)($row['provider']??''))!=='max')throw new \InvalidArgumentException('MAX-подключение не найдено.');$remote=false;
        try{$cred=SalesPartnerIntegrationService::credentialsForRow($row);$token=self::normalizeToken((string)($cred['api_token']??''));$meta=json_decode((string)($row['public_meta']??''),true);$url=is_array($meta)?(string)($meta['webhook_url']??''):'';if($url==='')$url=rest_url('ckm/v1/sales/max/webhook/'.(string)($row['webhook_id']??''));$this->api($token,'DELETE','/subscriptions',['url'=>$url]);$remote=true;}catch(\Throwable){}
        $deleted=SalesPartnerIntegrationService::deleteCurrentTenant($integrationId);$deleted['remote_webhook_deleted']=$remote;return $deleted;
    }
    public function testPartnerIntegration(string $integrationId): array {
        $row=SalesPartnerIntegrationService::rawCurrentTenant($integrationId);if(!$row||((string)($row['provider']??''))!=='max')throw new \InvalidArgumentException('MAX-подключение не найдено.');
        try{$cred=SalesPartnerIntegrationService::credentialsForRow($row);$token=self::normalizeToken((string)($cred['api_token']??''));$bot=$this->api($token,'GET','/me');$subs=$this->api($token,'GET','/subscriptions');$meta=json_decode((string)($row['public_meta']??''),true);$expected=is_array($meta)?(string)($meta['webhook_url']??''):'';$found=false;foreach((array)($subs['subscriptions']??[]) as $sub){if(is_array($sub)&&$expected!==''&&hash_equals($expected,(string)($sub['url']??''))){$found=true;break;}}if(!$found)throw new \RuntimeException('MAX API отвечает, но webhook-подписка этого подключения не найдена. Переподключите канал.');SalesPartnerIntegrationService::markCheck($integrationId,true,'');return ['ok'=>true,'bot'=>$bot,'webhook_ready'=>true];}catch(\Throwable $e){SalesPartnerIntegrationService::markCheck($integrationId,false,$e->getMessage());throw $e;}
    }

    private static function headerSecret(): string {return trim((string)($_SERVER['HTTP_X_MAX_BOT_API_SECRET']??''));}
    private static function leadName(array $from): string {$name=trim(self::clean((string)($from['first_name']??''),60).' '.self::clean((string)($from['last_name']??''),60));return $name!==''?$name:'MAX-клиент';}
    private static function leadContact(array $from,string $userId): string {$u=self::clean((string)($from['username']??''),80);return $u!==''?'@'.ltrim($u,'@').' · MAX':'max:'.$userId;}
    private function sendTextByRow(array $row,string $userId,string $text): void {
        $cred=SalesPartnerIntegrationService::credentialsForRow($row);$token=self::normalizeToken((string)($cred['api_token']??''));$this->api($token,'POST','/messages',['user_id'=>$userId,'disable_link_preview'=>'true'],['text'=>self::clean($text,4000)]);
    }
    public function webhook(string $hook,array $update): array {
        if(!preg_match('/^[a-f0-9]{48,64}$/',$hook))throw new \InvalidArgumentException('MAX webhook не найден.');$row=SalesPartnerIntegrationService::rowByProviderWebhook('max',$hook);if(!$row)throw new \InvalidArgumentException('MAX webhook не найден.');$expected=SalesPartnerIntegrationService::webhookSecretForRow($row);$actual=self::headerSecret();if($actual===''||!hash_equals($expected,$actual))throw new \InvalidArgumentException('MAX webhook secret не совпадает.');
        if((string)($update['update_type']??'')!=='message_created')return ['ignored'=>true,'reason'=>'unsupported_update'];
        if((int)($row['assigned_user_id']??0)<1||(string)($row['assigned_scope']??'')===''||(string)($row['assigned_script_id']??'')==='')return ['ignored'=>true,'reason'=>'not_assigned'];
        $message=is_array($update['message']??null)?$update['message']:[];$sender=is_array($message['sender']??null)?$message['sender']:[];$recipient=is_array($message['recipient']??null)?$message['recipient']:[];$body=is_array($message['body']??null)?$message['body']:[];
        if(!empty($sender['is_bot']))return ['ignored'=>true,'reason'=>'bot_message'];$chatType=(string)($recipient['chat_type']??'');$userId=(string)($sender['user_id']??'');$text=self::clean((string)($body['text']??''),1200);$mid=self::clean((string)($body['mid']??''),180);
        if($chatType!=='dialog'||$userId==='')return ['ignored'=>true,'reason'=>'private_dialog_only'];$dedupe=$mid!==''?'ckm_sales_max_update_'.hash('sha256',$hook.'|'.$mid):'';if($dedupe!==''&&get_transient($dedupe))return ['ignored'=>true,'reason'=>'duplicate'];
        if($text===''){$this->sendTextByRow($row,$userId,'Пока я умею работать с текстовыми сообщениями. Напишите вопрос текстом.');if($dedupe!=='')set_transient($dedupe,1,self::UPDATE_TTL);return ['ignored'=>true,'reason'=>'non_text'];}
        $uid=(int)$row['assigned_user_id'];$scope=(string)$row['assigned_scope'];$scriptId=(string)$row['assigned_script_id'];$script=SalesScriptService::findForOwner($uid,$scope,$scriptId);if(!$script||empty($script['ai_seller_enabled']))return ['ignored'=>true,'reason'=>'agent_inactive'];$sellerToken=(string)($script['ai_seller_token']??'');if($sellerToken==='')return ['ignored'=>true,'reason'=>'agent_inactive'];$integrationId=(string)($row['integration_id']??'');
        $result=(new SalesAiSellerService())->externalMessage($sellerToken,'max',$userId,self::leadName($sender),self::leadContact($sender,$userId),$text,$integrationId);$reply=self::clean((string)($result['reply']??''),1200);if($reply!=='')$this->sendTextByRow($row,$userId,$reply);if($dedupe!=='')set_transient($dedupe,1,self::UPDATE_TTL);
        return ['ignored'=>false,'session_id'=>(string)($result['session_id']??''),'replied'=>$reply!=='','queued_for_operator'=>!empty($result['queued_for_operator'])];
    }
    public function sendForSession(array $session,string $text): bool {
        if((string)($session['channel']??'web')!=='max')return false;$uid=(int)($session['owner_user_id']??0);$scope=(string)($session['scope']??'default');$scriptId=(string)($session['script_id']??'');$userId=(string)($session['external_thread_id']??'');if($uid<1||$scope===''||$scriptId===''||$userId==='')throw new \RuntimeException('MAX-сессия повреждена.');$integrationId=(string)($session['external_integration_id']??'');$row=$integrationId!==''?SalesPartnerIntegrationService::integrationForSession($integrationId,$uid,$scope,$scriptId,'max'):SalesPartnerIntegrationService::integrationForOwnerScript($uid,$scope,$scriptId,'max');if(!$row)throw new \RuntimeException('MAX-канал отключён или больше не назначен этому ИИ-продавцу.');$this->sendTextByRow($row,$userId,$text);return true;
    }
}
