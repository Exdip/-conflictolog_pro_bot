<?php
namespace CKM\EffectiveSales;
if (!defined('ABSPATH')) { exit; }

final class SalesTelegramService {
    private const INDEX_OPTION='ckm_sales_telegram_hooks_v1';
    private const UPDATE_TTL=86400;
    private const API_BASE='https://api.telegram.org/bot';
    private $transport;

    public function __construct(?callable $transport=null){$this->transport=$transport;}

    private static function clean(string $v,int $max=1200): string {
        $v=trim(wp_strip_all_tags($v));
        return function_exists('mb_substr')?mb_substr($v,0,$max,'UTF-8'):substr($v,0,$max);
    }
    private static function randomHex(int $bytes=24): string {
        try{return bin2hex(random_bytes($bytes));}catch(\Throwable){return hash('sha256',wp_generate_uuid4().microtime(true));}
    }
    private static function cryptoKey(): string {
        $seed=(defined('AUTH_KEY')?(string)AUTH_KEY:'').(defined('SECURE_AUTH_KEY')?(string)SECURE_AUTH_KEY:'').(function_exists('wp_salt')?(string)wp_salt('auth'):'').'|ckm-sales-telegram-v1';
        return hash('sha256',$seed,true);
    }
    private static function encrypt(string $plain): string {
        if($plain===''||!function_exists('openssl_encrypt'))throw new \RuntimeException('На сервере недоступно безопасное шифрование Bot Token.');
        try{$iv=random_bytes(12);}catch(\Throwable){throw new \RuntimeException('Не удалось подготовить безопасное хранилище Bot Token.');}
        $tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',self::cryptoKey(),OPENSSL_RAW_DATA,$iv,$tag,'ckm-sales-telegram',16);
        if(!is_string($cipher)||strlen($tag)!==16)throw new \RuntimeException('Не удалось зашифровать Bot Token.');
        return 'v1:'.base64_encode($iv.$tag.$cipher);
    }
    private static function decrypt(string $stored): string {
        if(!str_starts_with($stored,'v1:')||!function_exists('openssl_decrypt'))throw new \RuntimeException('Сохранённый Bot Token имеет неподдерживаемый формат. Подключите Telegram заново.');
        $raw=base64_decode(substr($stored,3),true);if(!is_string($raw)||strlen($raw)<=28)throw new \RuntimeException('Не удалось прочитать сохранённый Bot Token.');
        $iv=substr($raw,0,12);$tag=substr($raw,12,16);$cipher=substr($raw,28);$plain=openssl_decrypt($cipher,'aes-256-gcm',self::cryptoKey(),OPENSSL_RAW_DATA,$iv,$tag,'ckm-sales-telegram');
        if(!is_string($plain)||$plain==='')throw new \RuntimeException('Не удалось расшифровать Bot Token. Подключите Telegram заново.');
        return $plain;
    }
    private static function normalizeToken(string $token): string {
        $token=trim($token);if(!preg_match('/^\d{5,14}:[A-Za-z0-9_-]{20,80}$/',$token))throw new \InvalidArgumentException('Bot Token выглядит некорректно. Скопируйте токен целиком из BotFather.');return $token;
    }
    private function api(string $token,string $method,array $params=[]): array {
        if($this->transport){$r=($this->transport)($method,$params,$token);if(!is_array($r))throw new \RuntimeException('Telegram-коннектор вернул некорректный ответ.');return $r;}
        $response=wp_safe_remote_post(self::API_BASE.$token.'/'.$method,['timeout'=>15,'redirection'=>0,'body'=>$params,'headers'=>['Accept'=>'application/json']]);
        if(is_wp_error($response))throw new \RuntimeException('Telegram API недоступен: '.$response->get_error_message());
        $code=(int)wp_remote_retrieve_response_code($response);$json=json_decode((string)wp_remote_retrieve_body($response),true);
        if($code<200||$code>=300||!is_array($json)||empty($json['ok'])){$desc=is_array($json)?self::clean((string)($json['description']??''),260):'';throw new \RuntimeException($desc!==''?'Telegram: '.$desc:'Telegram API вернул ошибку HTTP '.$code.'.');}
        return $json;
    }
    private static function hookIndex(): array {$v=get_option(self::INDEX_OPTION,[]);return is_array($v)?$v:[];}
    private static function writeHookIndex(array $v): void {update_option(self::INDEX_OPTION,$v,false);}
    private static function addHook(string $hook,int $uid,string $scope,string $scriptId): void {$all=self::hookIndex();$all[hash('sha256',$hook)]=['user_id'=>$uid,'scope'=>$scope,'script_id'=>$scriptId,'updated_at'=>gmdate('c')];self::writeHookIndex($all);}
    private static function removeHook(string $hook): void {if($hook==='')return;$all=self::hookIndex();unset($all[hash('sha256',$hook)]);self::writeHookIndex($all);}
    private static function config(array $script): array {return is_array($script['ai_seller_telegram']??null)?$script['ai_seller_telegram']:[];}

    private static function statusFromIntegration(array $row): array {
        $meta=is_array($row['public_meta']??null)?$row['public_meta']:[];$username=self::clean((string)($row['account_username']??''),80);
        return ['connected'=>(string)($row['status']??'')==='connected','integration_id'=>(string)($row['id']??''),'tenant_id'=>(int)($row['tenant_id']??0),'bot_id'=>(string)($row['account_key']??''),'bot_username'=>$username,'bot_name'=>self::clean((string)($row['account_name']??''),120),'connected_at'=>(string)($row['created_at']??''),'webhook_url'=>(string)($meta['webhook_url']??''),'deep_link'=>$username!==''?'https://t.me/'.ltrim($username,'@'):'','central'=>true,'label'=>(string)($row['label']??'Telegram')];
    }
    public static function statusForScript(array $script): array {
        if(class_exists(SalesPartnerIntegrationService::class)){$bindings=SalesPartnerIntegrationService::bindingsForCurrentScript($script);if(isset($bindings['telegram']))return self::statusFromIntegration((array)$bindings['telegram']);}
        $cfg=self::config($script);$connected=!empty($cfg['connected'])&&!empty($cfg['token_cipher'])&&!empty($cfg['hook_id'])&&!empty($cfg['secret_cipher']);
        return ['connected'=>$connected,'bot_id'=>(string)($cfg['bot_id']??''),'bot_username'=>self::clean((string)($cfg['bot_username']??''),80),'bot_name'=>self::clean((string)($cfg['bot_name']??''),120),'connected_at'=>(string)($cfg['connected_at']??''),'webhook_url'=>(string)($cfg['webhook_url']??''),'deep_link'=>$connected&&((string)($cfg['bot_username']??''))!==''?'https://t.me/'.ltrim((string)$cfg['bot_username'],'@'):'','central'=>false,'label'=>'Telegram'];
    }

    /** Partner-owned Telegram connection stored once per tenant, then assigned to an agent. */
    public function connectPartnerIntegration(string $label,string $botToken): array {
        if(!class_exists(SalesPartnerIntegrationService::class))throw new \RuntimeException('Хранилище партнёрских интеграций недоступно.');
        $token=self::normalizeToken($botToken);$me=$this->api($token,'getMe');$bot=is_array($me['result']??null)?$me['result']:[];$botId=(string)($bot['id']??'');$username=self::clean((string)($bot['username']??''),80);if($botId===''||$username==='')throw new \RuntimeException('Telegram подтвердил токен, но не вернул данные бота.');SalesPartnerIntegrationService::assertAccountAvailable('telegram',$botId);
        $hook=self::randomHex(24);$secret=self::randomHex(20);$url=rest_url('ckm/v1/sales/telegram/webhook/'.$hook);if(!str_starts_with(strtolower($url),'https://'))throw new \RuntimeException('Для Telegram webhook требуется HTTPS.');
        $this->api($token,'setWebhook',['url'=>$url,'secret_token'=>$secret,'allowed_updates'=>wp_json_encode(['message']),'drop_pending_updates'=>'false']);
        try{return SalesPartnerIntegrationService::createVerifiedTelegram($label,['bot_token'=>$token],['account_key'=>$botId,'account_name'=>self::clean((string)($bot['first_name']??'Telegram-бот'),120),'account_username'=>$username],$hook,$secret);}catch(\Throwable $e){try{$this->api($token,'deleteWebhook',['drop_pending_updates'=>'false']);}catch(\Throwable){}throw $e;}
    }
    public function disconnectPartnerIntegration(string $integrationId): array {
        $row=SalesPartnerIntegrationService::rawCurrentTenant($integrationId);if(!$row||((string)($row['provider']??''))!=='telegram')throw new \InvalidArgumentException('Telegram-подключение не найдено.');$remote=false;
        try{$cred=SalesPartnerIntegrationService::credentialsForRow($row);$token=self::normalizeToken((string)($cred['bot_token']??''));$this->api($token,'deleteWebhook',['drop_pending_updates'=>'false']);$remote=true;}catch(\Throwable){}
        $deleted=SalesPartnerIntegrationService::deleteCurrentTenant($integrationId);$deleted['remote_webhook_deleted']=$remote;return $deleted;
    }
    public function testPartnerIntegration(string $integrationId): array {
        $row=SalesPartnerIntegrationService::rawCurrentTenant($integrationId);if(!$row||((string)($row['provider']??''))!=='telegram')throw new \InvalidArgumentException('Telegram-подключение не найдено.');
        try{$cred=SalesPartnerIntegrationService::credentialsForRow($row);$token=self::normalizeToken((string)($cred['bot_token']??''));$me=$this->api($token,'getMe');SalesPartnerIntegrationService::markCheck($integrationId,true,'');return ['ok'=>true,'bot'=>(array)($me['result']??[])];}catch(\Throwable $e){SalesPartnerIntegrationService::markCheck($integrationId,false,$e->getMessage());throw $e;}
    }

    public function connect(string $scriptId,string $botToken): array {
        $script=SalesScriptService::find($scriptId);if(!$script)throw new \InvalidArgumentException('Скрипт не найден.');
        if(empty($script['ai_seller_enabled'])||strlen((string)($script['ai_seller_token']??''))<32)throw new \RuntimeException('Сначала подключите ИИ-продавца и проверьте его через веб-диалог.');
        $token=self::normalizeToken($botToken);$me=$this->api($token,'getMe');$bot=is_array($me['result']??null)?$me['result']:[];$botId=(string)($bot['id']??'');$username=self::clean((string)($bot['username']??''),80);if($botId===''||$username==='')throw new \RuntimeException('Telegram подтвердил токен, но не вернул данные бота.');
        $old=self::config($script);if(!empty($old['token_cipher'])){try{$oldToken=self::decrypt((string)$old['token_cipher']);$this->api($oldToken,'deleteWebhook',['drop_pending_updates'=>'false']);}catch(\Throwable){}}if(!empty($old['hook_id']))self::removeHook((string)$old['hook_id']);
        $hook=self::randomHex(24);$secret=self::randomHex(20);$url=rest_url('ckm/v1/sales/telegram/webhook/'.$hook);if(!str_starts_with(strtolower($url),'https://'))throw new \RuntimeException('Для Telegram webhook требуется HTTPS.');
        $uid=get_current_user_id();$scope=SalesScriptService::currentScopeKey();if($uid<1)throw new \RuntimeException('Требуется вход.');$cipher=self::encrypt($token);$secretCipher=self::encrypt($secret);
        $this->api($token,'setWebhook',['url'=>$url,'secret_token'=>$secret,'allowed_updates'=>wp_json_encode(['message']),'drop_pending_updates'=>'false']);
        try{SalesScriptService::mutate($scriptId,static function(array $item) use($cipher,$secretCipher,$hook,$url,$botId,$username,$bot): array {$item['ai_seller_telegram']=['connected'=>true,'token_cipher'=>$cipher,'secret_cipher'=>$secretCipher,'hook_id'=>$hook,'webhook_url'=>$url,'bot_id'=>$botId,'bot_username'=>$username,'bot_name'=>SalesTelegramService::clean((string)($bot['first_name']??'Telegram-бот'),120),'connected_at'=>current_time('mysql')];return $item;});self::addHook($hook,$uid,$scope,$scriptId);}catch(\Throwable $e){try{$this->api($token,'deleteWebhook',['drop_pending_updates'=>'false']);}catch(\Throwable){}self::removeHook($hook);throw $e;}
        return self::statusForScript(SalesScriptService::find($scriptId)??[]);
    }

    public function disconnect(string $scriptId): array {
        $script=SalesScriptService::find($scriptId);if(!$script)throw new \InvalidArgumentException('Скрипт не найден.');$cfg=self::config($script);$remote=false;
        if(!empty($cfg['token_cipher'])){try{$token=self::decrypt((string)$cfg['token_cipher']);$this->api($token,'deleteWebhook',['drop_pending_updates'=>'false']);$remote=true;}catch(\Throwable){$remote=false;}}
        self::removeHook((string)($cfg['hook_id']??''));SalesScriptService::mutate($scriptId,static function(array $item): array {$item['ai_seller_telegram']=[];return $item;});
        return ['connected'=>false,'remote_webhook_deleted'=>$remote];
    }

    private static function resolveHook(string $hook): array {
        if(!preg_match('/^[a-f0-9]{48,64}$/',$hook))throw new \InvalidArgumentException('Telegram webhook не найден.');
        if(class_exists(SalesPartnerIntegrationService::class)){
            $row=SalesPartnerIntegrationService::rowByWebhook($hook);if($row){$uid=(int)($row['assigned_user_id']??0);$scope=(string)($row['assigned_scope']??'');$scriptId=(string)($row['assigned_script_id']??'');if($uid<1||$scope===''||$scriptId==='')throw new \InvalidArgumentException('Telegram подключён к площадке, но ещё не назначен ИИ-продавцу.');$script=SalesScriptService::findForOwner($uid,$scope,$scriptId);if(!$script||empty($script['ai_seller_enabled']))throw new \InvalidArgumentException('Назначенный ИИ-продавец больше не активен.');return ['user_id'=>$uid,'scope'=>$scope,'script'=>$script,'config'=>['_integration_row'=>$row]];}
        }
        $idx=self::hookIndex()[hash('sha256',$hook)]??null;if(!is_array($idx))throw new \InvalidArgumentException('Telegram webhook не найден.');
        $uid=(int)($idx['user_id']??0);$scope=(string)($idx['scope']??'default');$scriptId=(string)($idx['script_id']??'');$script=SalesScriptService::findForOwner($uid,$scope,$scriptId);if(!$script)throw new \InvalidArgumentException('Telegram webhook больше не активен.');$cfg=self::config($script);if(empty($cfg['connected'])||!hash_equals((string)($cfg['hook_id']??''),$hook))throw new \InvalidArgumentException('Telegram webhook больше не активен.');
        return ['user_id'=>$uid,'scope'=>$scope,'script'=>$script,'config'=>$cfg];
    }

    private static function headerSecret(): string {
        $v=(string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??'');return trim($v);
    }
    private static function leadName(array $from): string {$name=trim(self::clean((string)($from['first_name']??''),60).' '.self::clean((string)($from['last_name']??''),60));return $name!==''?$name:'Telegram-клиент';}
    private static function leadContact(array $from,string $chatId): string {$u=self::clean((string)($from['username']??''),80);return $u!==''?'@'.ltrim($u,'@'):'telegram:'.$chatId;}

    public function webhook(string $hook,array $update): array {
        $resolved=self::resolveHook($hook);$cfg=$resolved['config'];$expected=isset($cfg['_integration_row'])?SalesPartnerIntegrationService::webhookSecretForRow((array)$cfg['_integration_row']):self::decrypt((string)$cfg['secret_cipher']);$actual=self::headerSecret();if($actual===''||!hash_equals($expected,$actual))throw new \InvalidArgumentException('Telegram webhook secret не совпадает.');
        $updateId=(string)($update['update_id']??'');$dedupe=$updateId!==''?'ckm_sales_tg_update_'.hash('sha256',$hook.'|'.$updateId):'';if($dedupe!==''&&get_transient($dedupe))return ['ignored'=>true,'reason'=>'duplicate'];
        $message=is_array($update['message']??null)?$update['message']:[];$chat=is_array($message['chat']??null)?$message['chat']:[];$from=is_array($message['from']??null)?$message['from']:[];$chatId=(string)($chat['id']??'');$type=(string)($chat['type']??'');$text=self::clean((string)($message['text']??''),1200);
        if($chatId===''||$type!=='private'){if($dedupe!=='')set_transient($dedupe,1,self::UPDATE_TTL);return ['ignored'=>true,'reason'=>'private_text_only'];}
        if($text===''){$this->sendTextByConfig($cfg,$chatId,'Пока я умею работать с текстовыми сообщениями. Напишите вопрос текстом.');if($dedupe!=='')set_transient($dedupe,1,self::UPDATE_TTL);return ['ignored'=>true,'reason'=>'non_text'];}
        if(preg_match('/^\/start(?:@\w+)?(?:\s|$)/u',$text))$text='Здравствуйте';
        $script=(array)$resolved['script'];$sellerToken=(string)($script['ai_seller_token']??'');if($sellerToken==='')throw new \RuntimeException('ИИ-продавец отключён.');
        $integrationId=isset($cfg['_integration_row'])?(string)($cfg['_integration_row']['integration_id']??''):'';$result=(new SalesAiSellerService())->externalMessage($sellerToken,'telegram',$chatId,self::leadName($from),self::leadContact($from,$chatId),$text,$integrationId);
        $reply=self::clean((string)($result['reply']??''),1200);if($reply!=='')$this->sendTextByConfig($cfg,$chatId,$reply);if($dedupe!=='')set_transient($dedupe,1,self::UPDATE_TTL);
        return ['ignored'=>false,'session_id'=>(string)($result['session_id']??''),'replied'=>$reply!=='','queued_for_operator'=>!empty($result['queued_for_operator'])];
    }

    private function sendTextByConfig(array $cfg,string $chatId,string $text): void {
        if(isset($cfg['_integration_row'])){$cred=SalesPartnerIntegrationService::credentialsForRow((array)$cfg['_integration_row']);$token=self::normalizeToken((string)($cred['bot_token']??''));}
        else $token=self::decrypt((string)($cfg['token_cipher']??''));
        $this->api($token,'sendMessage',['chat_id'=>$chatId,'text'=>self::clean($text,4000),'disable_web_page_preview'=>'true']);
    }
    public function sendForSession(array $session,string $text): bool {
        if((string)($session['channel']??'web')!=='telegram')return false;$uid=(int)($session['owner_user_id']??0);$scope=(string)($session['scope']??'default');$scriptId=(string)($session['script_id']??'');$chatId=(string)($session['external_thread_id']??'');if($uid<1||$scriptId===''||$chatId==='')throw new \RuntimeException('Telegram-сессия повреждена.');
        if(class_exists(SalesPartnerIntegrationService::class)){$integrationId=(string)($session['external_integration_id']??'');$central=$integrationId!==''?SalesPartnerIntegrationService::integrationForSession($integrationId,$uid,$scope,$scriptId,'telegram'):SalesPartnerIntegrationService::integrationForOwnerScript($uid,$scope,$scriptId,'telegram');if($central){$this->sendTextByConfig(['_integration_row'=>$central],$chatId,$text);return true;}}
        $script=SalesScriptService::findForOwner($uid,$scope,$scriptId);if(!$script)throw new \RuntimeException('Telegram-агент больше не найден.');$cfg=self::config($script);if(empty($cfg['connected']))throw new \RuntimeException('Telegram-канал отключён.');$this->sendTextByConfig($cfg,$chatId,$text);return true;
    }

    public static function statusForTest(array $script): array {return self::statusForScript($script);}
}
