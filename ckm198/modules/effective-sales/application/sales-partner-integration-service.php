<?php
namespace CKM\EffectiveSales;
if (!defined('ABSPATH')) { exit; }

/**
 * Tenant-scoped channel credentials for partner-owned AI sellers.
 * Secrets are encrypted at rest and never returned to the browser after save.
 */
final class SalesPartnerIntegrationService {
    private const SCHEMA_VERSION='1.0.0';
    private const SCHEMA_OPTION='ckm_sales_partner_integrations_schema';
    private const MAX_PER_TENANT=30;
    private const PROVIDERS=['telegram','max','whatsapp','sip'];

    private static function table(): string { global $wpdb; return $wpdb->prefix.'ckm_sales_partner_integrations'; }
    private static function clean(string $v,int $max=500): string { $v=trim(wp_strip_all_tags($v)); return function_exists('mb_substr')?mb_substr($v,0,$max,'UTF-8'):substr($v,0,$max); }
    private static function now(): string { return gmdate('Y-m-d H:i:s'); }
    private static function tenantId(): int { return function_exists('ckmqp_scope_id')?(int)ckmqp_scope_id():0; }
    private static function scope(): string { return SalesScriptService::currentScopeKey(); }
    private static function userId(): int { return get_current_user_id(); }
    private static function normalizeProvider(string $provider): string { $provider=sanitize_key($provider); if(!in_array($provider,self::PROVIDERS,true))throw new \InvalidArgumentException('Неизвестный канал интеграции.'); return $provider; }
    private static function cryptoKey(): string { $seed=(defined('AUTH_KEY')?(string)AUTH_KEY:'').(defined('SECURE_AUTH_KEY')?(string)SECURE_AUTH_KEY:'').(function_exists('wp_salt')?(string)wp_salt('auth'):'').'|ckm-sales-partner-integrations-v1'; return hash('sha256',$seed,true); }
    private static function encrypt(array $data): string {
        if(!function_exists('openssl_encrypt'))throw new \RuntimeException('На сервере недоступно безопасное шифрование данных каналов.');
        $plain=wp_json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if(!is_string($plain)||$plain==='')throw new \RuntimeException('Не удалось подготовить данные подключения.');
        try{$iv=random_bytes(12);}catch(\Throwable){throw new \RuntimeException('Не удалось подготовить безопасное хранилище интеграций.');}
        $tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',self::cryptoKey(),OPENSSL_RAW_DATA,$iv,$tag,'ckm-sales-partner-integrations',16);
        if(!is_string($cipher)||strlen($tag)!==16)throw new \RuntimeException('Не удалось зашифровать данные подключения.');
        return 'v1:'.base64_encode($iv.$tag.$cipher);
    }
    private static function decrypt(string $stored): array {
        if(!str_starts_with($stored,'v1:')||!function_exists('openssl_decrypt'))throw new \RuntimeException('Сохранённые данные подключения имеют неподдерживаемый формат. Подключите канал заново.');
        $raw=base64_decode(substr($stored,3),true);if(!is_string($raw)||strlen($raw)<=28)throw new \RuntimeException('Не удалось прочитать данные подключения.');
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::cryptoKey(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'ckm-sales-partner-integrations');
        $data=is_string($plain)?json_decode($plain,true):null;if(!is_array($data))throw new \RuntimeException('Не удалось расшифровать данные подключения.');return $data;
    }
    private static function uuid(): string { return wp_generate_uuid4(); }

    public static function installSchema(): void {
        if(get_option(self::SCHEMA_OPTION,'')===self::SCHEMA_VERSION)return;
        global $wpdb;require_once ABSPATH.'wp-admin/includes/upgrade.php';$t=self::table();$c=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $t (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            integration_id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            tenant_id bigint unsigned NOT NULL DEFAULT 0,
            owner_user_id bigint unsigned NOT NULL DEFAULT 0,
            provider varchar(32) NOT NULL,
            label varchar(120) NOT NULL DEFAULT '',
            status varchar(24) NOT NULL DEFAULT 'configured',
            credentials_cipher longtext NULL,
            credentials_hint varchar(120) NOT NULL DEFAULT '',
            account_key varchar(190) NOT NULL DEFAULT '',
            account_name varchar(190) NOT NULL DEFAULT '',
            account_username varchar(190) NOT NULL DEFAULT '',
            public_meta longtext NULL,
            webhook_id char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
            webhook_secret_cipher longtext NULL,
            assigned_user_id bigint unsigned NOT NULL DEFAULT 0,
            assigned_scope varchar(96) NOT NULL DEFAULT '',
            assigned_script_id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
            last_error text NULL,
            last_checked_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY integration_id (integration_id),
            KEY tenant_provider (tenant_id,provider),
            KEY webhook_id (webhook_id),
            KEY assigned_agent (assigned_user_id,assigned_script_id)
        ) ENGINE=InnoDB $c;");
        update_option(self::SCHEMA_OPTION,self::SCHEMA_VERSION,false);
    }

    public static function canManageSecrets(): bool {
        if(!is_user_logged_in())return false;$uid=self::userId();$tenant=self::tenantId();if($tenant<0)return false;
        if(current_user_can('manage_options'))return true;
        return $tenant>0&&function_exists('ckm_quiz_pro_partner_user_is_owner')&&ckm_quiz_pro_partner_user_is_owner($tenant,$uid);
    }
    public static function canView(): bool {
        if(!is_user_logged_in())return false;$tenant=self::tenantId();if($tenant<0)return false;if(current_user_can('manage_options'))return true;
        if($tenant===0)return false;return function_exists('ckmqp_tenant_is_member')&&ckmqp_tenant_is_member($tenant,self::userId());
    }
    public static function tenantLabel(): string { $tenant=self::tenantId(); return $tenant>0?'Площадка #'.$tenant:'Платформа'; }
    public static function providers(): array { return ['telegram'=>'Telegram','max'=>'MAX','whatsapp'=>'WhatsApp','sip'=>'IP-телефония / SIP']; }

    private static function rowById(string $id,?int $tenant=null): ?array {
        self::installSchema();$id=self::clean($id,36);if($id==='')return null;$tenant=$tenant??self::tenantId();if($tenant<0)return null;global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE integration_id=%s AND tenant_id=%d LIMIT 1',$id,$tenant),ARRAY_A);return is_array($row)?$row:null;
    }
    public static function rowByProviderWebhook(string $provider,string $hook): ?array {
        self::installSchema();$provider=self::normalizeProvider($provider);if(!preg_match('/^[a-f0-9]{48,64}$/',$hook))return null;global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE provider=%s AND webhook_id=%s LIMIT 1',$provider,$hook),ARRAY_A);return is_array($row)?$row:null;
    }
    public static function rowByWebhook(string $hook): ?array { return self::rowByProviderWebhook('telegram',$hook); }
    private static function publicRow(array $row): array {
        $meta=json_decode((string)($row['public_meta']??''),true);if(!is_array($meta))$meta=[];
        return ['id'=>(string)$row['integration_id'],'tenant_id'=>(int)$row['tenant_id'],'provider'=>(string)$row['provider'],'provider_label'=>(string)(self::providers()[(string)$row['provider']]??$row['provider']),'label'=>(string)$row['label'],'status'=>(string)$row['status'],'credentials_hint'=>(string)$row['credentials_hint'],'account_key'=>(string)$row['account_key'],'account_name'=>(string)$row['account_name'],'account_username'=>(string)$row['account_username'],'public_meta'=>$meta,'webhook_ready'=>(string)$row['webhook_id']!=='','assigned_user_id'=>(int)$row['assigned_user_id'],'assigned_scope'=>(string)$row['assigned_scope'],'assigned_script_id'=>(string)$row['assigned_script_id'],'assigned'=>((string)$row['assigned_script_id'])!=='','last_error'=>(string)($row['last_error']??''),'last_checked_at'=>(string)($row['last_checked_at']??''),'created_at'=>(string)$row['created_at'],'updated_at'=>(string)$row['updated_at']];
    }
    public static function listCurrentTenant(?string $provider=null): array {
        self::installSchema();if(!self::canView())return [];$tenant=self::tenantId();global $wpdb;$sql='SELECT * FROM '.self::table().' WHERE tenant_id=%d';$args=[$tenant];if($provider!==null){$provider=self::normalizeProvider($provider);$sql.=' AND provider=%s';$args[]=$provider;}$sql.=' ORDER BY provider,label,id';$rows=$wpdb->get_results($wpdb->prepare($sql,...$args),ARRAY_A)?:[];return array_map(static fn(array $r): array=>self::publicRow($r),$rows);
    }
    public static function findCurrentTenant(string $id): ?array { $row=self::rowById($id);return $row?self::publicRow($row):null; }
    public static function credentialsForRow(array $row): array { return self::decrypt((string)($row['credentials_cipher']??'')); }
    public static function webhookSecretForRow(array $row): string { $data=self::decrypt((string)($row['webhook_secret_cipher']??''));return (string)($data['secret']??''); }

    private static function requireManage(): void { if(!self::canManageSecrets())throw new \RuntimeException('Изменять данные каналов может только владелец партнёрской площадки.'); }
    private static function hintFor(string $provider,array $credentials,array $meta=[]): string {
        if($provider==='telegram'){ $token=(string)($credentials['bot_token']??'');return $token!==''?'Bot Token · ••••'.substr($token,-4):'Bot Token сохранён'; }
        if($provider==='max'){ $id=self::clean((string)($credentials['bot_id']??''),80);return $id!==''?'Bot/App ID: '.$id:'API token сохранён'; }
        if($provider==='whatsapp'){ $id=self::clean((string)($credentials['phone_number_id']??''),80);return $id!==''?'Phone Number ID: '.$id:'Cloud API сохранён'; }
        $adapter=self::clean((string)($credentials['adapter']??''),40);if($adapter==='mango'){ $ext=self::clean((string)($credentials['extension']??''),40);return 'MANGO · '.($ext!==''?'вн. '.$ext:'API подключён'); }if($adapter==='uis'){ $num=self::clean((string)($credentials['virtual_number']??''),40);return 'UIS · '.($num!==''?$num:'Call API подключён'); }if($adapter==='direct_sip'){ $did=self::clean((string)($credentials['inbound_did']??$credentials['caller_id']??''),40);return 'SIP · Сергей · '.($did!==''?'+'.ltrim($did,'+'):'trunk сохранён'); }$login=self::clean((string)($credentials['login']??''),80);$server=self::clean((string)($credentials['server']??''),80);return trim(($server!==''?$server:'SIP/API').' · '.($login!==''?$login:'учётные данные сохранены'));
    }
    public static function createConfigured(string $provider,string $label,array $credentials,array $meta=[]): array {
        self::requireManage();self::installSchema();$provider=self::normalizeProvider($provider);$label=self::clean($label,120);if($label==='')$label=self::providers()[$provider];$tenant=self::tenantId();$uid=self::userId();if($tenant<0||$uid<1)throw new \RuntimeException('Площадка не определена.');
        global $wpdb;$count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table().' WHERE tenant_id=%d',$tenant));if($count>=self::MAX_PER_TENANT)throw new \RuntimeException('Достигнут лимит подключений для площадки.');
        $id=self::uuid();$now=self::now();$public=is_array($meta)?$meta:[];$ok=$wpdb->insert(self::table(),['integration_id'=>$id,'tenant_id'=>$tenant,'owner_user_id'=>$uid,'provider'=>$provider,'label'=>$label,'status'=>'configured','credentials_cipher'=>self::encrypt($credentials),'credentials_hint'=>self::hintFor($provider,$credentials,$public),'account_key'=>self::clean((string)($public['account_key']??''),190),'account_name'=>self::clean((string)($public['account_name']??''),190),'account_username'=>self::clean((string)($public['account_username']??''),190),'public_meta'=>wp_json_encode($public,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>$now,'updated_at'=>$now]);if($ok===false)throw new \RuntimeException('Не удалось сохранить подключение канала.');return self::findCurrentTenant($id)??[];
    }
    public static function assertAccountAvailable(string $provider,string $accountKey): void {
        self::installSchema();$provider=self::normalizeProvider($provider);$accountKey=self::clean($accountKey,190);if($accountKey==='')return;global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT integration_id,tenant_id FROM '.self::table().' WHERE provider=%s AND account_key=%s LIMIT 1',$provider,$accountKey),ARRAY_A);if(!is_array($row))return;$tenant=self::tenantId();throw new \RuntimeException((int)$row['tenant_id']===$tenant?'Этот аккаунт канала уже добавлен на площадку.':'Этот аккаунт канала уже подключён к другой площадке.');
    }
    public static function createVerifiedTelegram(string $label,array $credentials,array $meta,string $hook,string $secret): array {
        self::requireManage();self::installSchema();$tenant=self::tenantId();$account=self::clean((string)($meta['account_key']??''),190);if($account==='')throw new \RuntimeException('Telegram не вернул ID бота.');self::assertAccountAvailable('telegram',$account);global $wpdb;
        $row=self::createConfigured('telegram',$label,$credentials,$meta);$id=(string)$row['id'];$url=rest_url('ckm/v1/sales/telegram/webhook/'.$hook);$wpdb->update(self::table(),['status'=>'connected','webhook_id'=>$hook,'webhook_secret_cipher'=>self::encrypt(['secret'=>$secret]),'public_meta'=>wp_json_encode(array_merge($meta,['webhook_url'=>$url]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'last_checked_at'=>self::now(),'updated_at'=>self::now()],['integration_id'=>$id,'tenant_id'=>$tenant]);return self::findCurrentTenant($id)??[];
    }
    public static function createVerifiedMax(string $label,array $credentials,array $meta,string $hook,string $secret): array {
        self::requireManage();self::installSchema();$tenant=self::tenantId();$account=self::clean((string)($meta['account_key']??''),190);if($account==='')throw new \RuntimeException('MAX не вернул ID бота.');self::assertAccountAvailable('max',$account);global $wpdb;
        $row=self::createConfigured('max',$label,$credentials,$meta);$id=(string)$row['id'];$url=rest_url('ckm/v1/sales/max/webhook/'.$hook);$wpdb->update(self::table(),['status'=>'connected','webhook_id'=>$hook,'webhook_secret_cipher'=>self::encrypt(['secret'=>$secret]),'public_meta'=>wp_json_encode(array_merge($meta,['webhook_url'=>$url]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'last_checked_at'=>self::now(),'updated_at'=>self::now()],['integration_id'=>$id,'tenant_id'=>$tenant]);return self::findCurrentTenant($id)??[];
    }
    public static function createVerifiedWhatsApp(string $label,array $credentials,array $meta,string $hook,string $verifyToken): array {
        self::requireManage();self::installSchema();$tenant=self::tenantId();$account=self::clean((string)($meta['account_key']??''),190);if($account==='')throw new \RuntimeException('WhatsApp не вернул WhatsApp Business Account ID.');self::assertAccountAvailable('whatsapp',$account);global $wpdb;
        $row=self::createConfigured('whatsapp',$label,$credentials,$meta);$id=(string)$row['id'];$url=rest_url('ckm/v1/sales/whatsapp/webhook/'.$hook);$wpdb->update(self::table(),['status'=>'connected','webhook_id'=>$hook,'webhook_secret_cipher'=>self::encrypt(['secret'=>$verifyToken]),'public_meta'=>wp_json_encode(array_merge($meta,['webhook_url'=>$url]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'last_checked_at'=>self::now(),'updated_at'=>self::now()],['integration_id'=>$id,'tenant_id'=>$tenant]);return self::findCurrentTenant($id)??[];
    }
    public static function createVerifiedTelephony(string $label,array $credentials,array $meta): array {
        self::requireManage();self::installSchema();$account=self::clean((string)($meta['account_key']??''),190);if($account==='')throw new \RuntimeException('Телефонный провайдер не вернул идентификатор подключения.');self::assertAccountAvailable('sip',$account);global $wpdb;
        $row=self::createConfigured('sip',$label,$credentials,$meta);$id=(string)$row['id'];$wpdb->update(self::table(),['status'=>'connected','last_checked_at'=>self::now(),'updated_at'=>self::now()],['integration_id'=>$id,'tenant_id'=>self::tenantId()]);return self::findCurrentTenant($id)??[];
    }
    public static function markCheck(string $id,bool $ok,string $error=''): void { self::installSchema();global $wpdb;$wpdb->update(self::table(),['status'=>$ok?'connected':'error','last_error'=>self::clean($error,1000),'last_checked_at'=>self::now(),'updated_at'=>self::now()],['integration_id'=>$id,'tenant_id'=>self::tenantId()]); }
    public static function deleteCurrentTenant(string $id): array {
        self::requireManage();$row=self::rowById($id);if(!$row)throw new \InvalidArgumentException('Подключение не найдено.');if((string)$row['assigned_script_id']!=='')self::unbind((string)$row['integration_id']);global $wpdb;$wpdb->delete(self::table(),['integration_id'=>(string)$row['integration_id'],'tenant_id'=>self::tenantId()]);return self::publicRow($row);
    }
    public static function rawCurrentTenant(string $id): ?array { self::requireManage(); return self::rowById($id); }

    public static function bind(string $integrationId,string $scriptId): array {
        self::requireManage();$row=self::rowById($integrationId);if(!$row)throw new \InvalidArgumentException('Подключение не найдено.');$script=SalesScriptService::find($scriptId);if(!$script)throw new \InvalidArgumentException('ИИ-продавец не найден.');if(empty($script['ai_seller_enabled']))throw new \RuntimeException('Сначала подключите утверждённый скрипт к ИИ-продавцу.');
        $uid=self::userId();$scope=self::scope();$provider=(string)$row['provider'];$assignedScript=(string)$row['assigned_script_id'];if($assignedScript!==''&&$assignedScript!==$scriptId)throw new \RuntimeException('Это подключение уже назначено другому ИИ-продавцу. Сначала отвяжите его.');
        global $wpdb;$existing=$wpdb->get_results($wpdb->prepare('SELECT integration_id FROM '.self::table().' WHERE tenant_id=%d AND provider=%s AND assigned_user_id=%d AND assigned_scope=%s AND assigned_script_id=%s AND integration_id<>%s',self::tenantId(),$provider,$uid,$scope,$scriptId,$integrationId),ARRAY_A)?:[];foreach($existing as $old){try{self::unbind((string)$old['integration_id']);}catch(\Throwable){}}
        $wpdb->update(self::table(),['assigned_user_id'=>$uid,'assigned_scope'=>$scope,'assigned_script_id'=>$scriptId,'updated_at'=>self::now()],['integration_id'=>$integrationId,'tenant_id'=>self::tenantId()]);
        SalesScriptService::mutate($scriptId,static function(array $item) use($provider,$integrationId): array {$bindings=is_array($item['ai_seller_integrations']??null)?$item['ai_seller_integrations']:[];$bindings[$provider]=$integrationId;$item['ai_seller_integrations']=$bindings;return $item;});return self::findCurrentTenant($integrationId)??[];
    }

    public static function unbind(string $integrationId): array {
        self::requireManage();$row=self::rowById($integrationId);if(!$row)throw new \InvalidArgumentException('Подключение не найдено.');$scriptId=(string)$row['assigned_script_id'];$provider=(string)$row['provider'];if($scriptId!==''&&((int)$row['assigned_user_id']===self::userId())&&((string)$row['assigned_scope']===self::scope())){try{SalesScriptService::mutate($scriptId,static function(array $item) use($provider,$integrationId): array {$bindings=is_array($item['ai_seller_integrations']??null)?$item['ai_seller_integrations']:[];if((string)($bindings[$provider]??'')===$integrationId)unset($bindings[$provider]);$item['ai_seller_integrations']=$bindings;return $item;});}catch(\Throwable){}}
        global $wpdb;$wpdb->update(self::table(),['assigned_user_id'=>0,'assigned_scope'=>'','assigned_script_id'=>'','updated_at'=>self::now()],['integration_id'=>$integrationId,'tenant_id'=>self::tenantId()]);return self::findCurrentTenant($integrationId)??[];
    }
    public static function bindingsForCurrentScript(array $script): array {
        $id=(string)($script['id']??'');if($id==='')return [];$out=[];foreach(self::listCurrentTenant() as $row)if((string)$row['assigned_script_id']===$id)$out[(string)$row['provider']]=$row;return $out;
    }
    public static function assignedSipByInboundNumber(string $number): ?array {
        self::installSchema();$digits=preg_replace('/\\D+/','',(string)$number);if(!is_string($digits)||!preg_match('/^[0-9]{7,15}$/',$digits))return null;global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM ".self::table()." WHERE provider='sip' AND assigned_script_id<>'' AND account_username=%s ORDER BY updated_at DESC LIMIT 5",$digits),ARRAY_A)?:[];
        foreach($rows as $row){if(!is_array($row))continue;$meta=json_decode((string)($row['public_meta']??''),true);if(is_array($meta)&&(string)($meta['adapter']??'')==='direct_sip')return $row;}
        return null;
    }

    public static function integrationForSession(string $integrationId,int $userId,string $scope,string $scriptId,string $provider): ?array {
        self::installSchema();$provider=self::normalizeProvider($provider);if($integrationId===''||$userId<1||$scope===''||$scriptId==='')return null;global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE integration_id=%s AND provider=%s AND assigned_user_id=%d AND assigned_scope=%s AND assigned_script_id=%s LIMIT 1',$integrationId,$provider,$userId,$scope,$scriptId),ARRAY_A);return is_array($row)?$row:null;
    }
    public static function integrationForOwnerScript(int $userId,string $scope,string $scriptId,string $provider): ?array {
        self::installSchema();$provider=self::normalizeProvider($provider);global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE provider=%s AND assigned_user_id=%d AND assigned_scope=%s AND assigned_script_id=%s LIMIT 1',$provider,$userId,$scope,$scriptId),ARRAY_A);return is_array($row)?$row:null;
    }
    public static function publicForOwnerScript(int $userId,string $scope,string $scriptId,string $provider): ?array { $row=self::integrationForOwnerScript($userId,$scope,$scriptId,$provider);return $row?self::publicRow($row):null; }
}
