<?php
namespace CKM\EffectiveSales;
if (!defined('ABSPATH')) { exit; }

/**
 * Provider adapter layer for partner-owned PSTN/IP telephony.
 *
 * v528 deliberately separates call-control credentials from the future media bridge:
 * MANGO/UIS APIs can be validated and assigned to an AI seller now, while realtime
 * AI voice over PSTN requires a SIP/RTP media gateway in the next layer.
 */
final class SalesTelephonyService {
    private const MANGO_BASE='https://app.mango-office.ru';
    private const UIS_BASE='https://callapi.uiscom.ru/v4.0';
    private $transport;

    public function __construct(?callable $transport=null){$this->transport=$transport;}

    private static function clean(string $v,int $max=500): string {
        $v=trim(wp_strip_all_tags($v));
        return function_exists('mb_substr')?mb_substr($v,0,$max,'UTF-8'):substr($v,0,$max);
    }
    private static function normalizeAdapter(string $adapter): string {
        $adapter=sanitize_key($adapter);
        if(!in_array($adapter,['mango','uis','direct_sip','custom'],true))throw new \InvalidArgumentException('Выберите поддерживаемого провайдера телефонии.');
        return $adapter;
    }
    private static function normalizeSecret(string $value,string $label,int $min=6,int $max=512): string {
        $value=trim($value);
        if(strlen($value)<$min||strlen($value)>$max||preg_match('/[\x00-\x1f\x7f]/',$value))throw new \InvalidArgumentException($label.' выглядит некорректно. Скопируйте значение целиком из кабинета провайдера.');
        return $value;
    }
    private static function normalizePhone(string $phone,bool $optional=false): string {
        $phone=preg_replace('/[^0-9+]/','',trim($phone));$phone=ltrim((string)$phone,'+');
        if($phone===''&&$optional)return '';
        if(!preg_match('/^[0-9]{7,15}$/',$phone))throw new \InvalidArgumentException('Номер телефона должен быть в международном формате, например 74951234567.');
        return $phone;
    }
    private static function normalizeExtension(string $value): string {
        $value=trim($value);if(!preg_match('/^[0-9A-Za-z._-]{1,32}$/',$value))throw new \InvalidArgumentException('Внутренний номер MANGO выглядит некорректно.');return $value;
    }
    private static function normalizeHttps(string $url): string {
        $url=esc_url_raw(trim($url));if($url===''||!str_starts_with(strtolower($url),'https://'))throw new \InvalidArgumentException('Для внешнего API укажите HTTPS-адрес.');return rtrim($url,'/');
    }
    public static function normalizeHandoffTarget(string $value,bool $optional=true): string {
        $value=trim($value);if($value===''&&$optional)return '';if(strlen($value)>240||preg_match('/[\r\n\x00]/',$value))throw new \InvalidArgumentException('Адрес перевода оператору выглядит некорректно.');
        if(preg_match('/^tel:\+([0-9]{7,15})$/i',$value))return $value;
        if(preg_match('/^sips?:[^\s@]+@[^\s;]+(?::[0-9]{1,5})?(?:;transport=tls)?$/i',$value))return $value;
        throw new \InvalidArgumentException('Для перевода укажите tel:+74951234567 или sip:operator@pbx.example.ru.');
    }
    public static function normalizeDirectSipUrl(string $url): string {
        $url=trim($url);if(!preg_match('/^sips:([^\\s\\/@?#;:]+|\\[[0-9A-Fa-f:]+\\])(?::([0-9]{1,5}))?(?:;transport=tcp)?$/i',$url,$m))throw new \InvalidArgumentException('SIP trunk должен иметь вид sips:sip.example.ru:5061 и использовать TLS.');
        $host=trim((string)$m[1],'[]');$port=(string)($m[2]??'');if($port!==''&&((int)$port<1||(int)$port>65535))throw new \InvalidArgumentException('Порт SIP trunk выглядит некорректно.');$lower=strtolower($host);
        if($lower==='localhost'||str_ends_with($lower,'.local'))throw new \InvalidArgumentException('Локальный SIP-адрес нельзя использовать для внешнего звонка.');
        if(filter_var($host,FILTER_VALIDATE_IP)){if(!filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new \InvalidArgumentException('Приватный или служебный IP нельзя использовать как SIP trunk.');}
        return $url;
    }
    public static function adapters(): array {
        return [
            'mango'=>['label'=>'MANGO OFFICE','mode'=>'API ВАТС','call_control'=>true,'media_bridge'=>false],
            'uis'=>['label'=>'UIS / CoMagic','mode'=>'Call API v4.0','call_control'=>true,'media_bridge'=>false],
            'direct_sip'=>['label'=>'SIP trunk · Сергей','mode'=>'SIP/PSTN → CKM Media Gateway → Сергей','call_control'=>true,'media_bridge'=>true],
            'custom'=>['label'=>'Другой SIP / API','mode'=>'Пользовательский адаптер','call_control'=>false,'media_bridge'=>false],
        ];
    }

    private function mangoApi(string $apiKey,string $apiSalt,string $path,array $payload=[]): array {
        $apiKey=self::normalizeSecret($apiKey,'API key MANGO',6,190);$apiSalt=self::normalizeSecret($apiSalt,'API salt MANGO',6,190);$path='/'.ltrim($path,'/');
        $json=wp_json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if(!is_string($json))throw new \RuntimeException('Не удалось подготовить запрос MANGO.');$sign=hash('sha256',$apiKey.$json.$apiSalt);
        if($this->transport){$out=($this->transport)('mango','POST',$path,['vpbx_api_key'=>$apiKey,'sign'=>$sign,'json'=>$json]);if(!is_array($out))throw new \RuntimeException('MANGO-адаптер вернул некорректный ответ.');return $out;}
        $response=wp_safe_remote_post(self::MANGO_BASE.$path,['timeout'=>15,'redirection'=>0,'body'=>['vpbx_api_key'=>$apiKey,'sign'=>$sign,'json'=>$json],'headers'=>['Accept'=>'application/json']]);
        if(is_wp_error($response))throw new \RuntimeException('MANGO OFFICE недоступен: '.$response->get_error_message());$code=(int)wp_remote_retrieve_response_code($response);$raw=(string)wp_remote_retrieve_body($response);$data=json_decode($raw,true);
        if($code<200||$code>=300||!is_array($data))throw new \RuntimeException('MANGO OFFICE вернул ошибку HTTP '.$code.'.');$result=(int)($data['result']??$data['Result']??1000);if($result!==1000&&$result!==0)throw new \RuntimeException('MANGO OFFICE не принял запрос. Код '.$result.'.');return $data;
    }
    private function uisRpc(string $method,array $params): array {
        if($this->transport){$out=($this->transport)('uis','POST','/v4.0',['jsonrpc'=>'2.0','id'=>'ckm-'.substr(hash('sha256',$method.wp_json_encode($params)),0,12),'method'=>$method,'params'=>$params]);if(!is_array($out))throw new \RuntimeException('UIS-адаптер вернул некорректный ответ.');return $out;}
        $body=['jsonrpc'=>'2.0','id'=>'ckm-'.substr(hash('sha256',$method.microtime(true)),0,12),'method'=>$method,'params'=>$params];$response=wp_safe_remote_post(self::UIS_BASE,['timeout'=>15,'redirection'=>0,'headers'=>['Accept'=>'application/json','Content-Type'=>'application/json; charset=UTF-8'],'body'=>wp_json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        if(is_wp_error($response))throw new \RuntimeException('UIS Call API недоступен: '.$response->get_error_message());$code=(int)wp_remote_retrieve_response_code($response);$raw=(string)wp_remote_retrieve_body($response);$data=json_decode($raw,true);if($code<200||$code>=300||!is_array($data))throw new \RuntimeException('UIS Call API вернул ошибку HTTP '.$code.'.');if(is_array($data['error']??null)){$err=$data['error'];$msg=self::clean((string)($err['message']??$err['data']['mnemonic']??''),300);throw new \RuntimeException($msg!==''?'UIS: '.$msg:'UIS Call API отклонил запрос.');}return $data;
    }
    private function uisLogin(string $login,string $password): array {
        $login=self::normalizeSecret($login,'Логин UIS',3,190);$password=self::normalizeSecret($password,'Пароль UIS',4,512);$data=$this->uisRpc('login.user',['login'=>$login,'password'=>$password]);$result=is_array($data['result']['data']??null)?$data['result']['data']:[];$token=(string)($result['access_token']??'');if($token==='')throw new \RuntimeException('UIS подтвердил соединение, но не вернул access_token. Проверьте права Call API и белый список IP.');return $result;
    }
    private function uisLogout(string $token): void {if($token==='')return;try{$this->uisRpc('logout.user',['access_token'=>$token]);}catch(\Throwable){} }

    private static function statusFromIntegration(array $row): array {
        $meta=is_array($row['public_meta']??null)?$row['public_meta']:[];
        return ['connected'=>(string)($row['status']??'')==='connected','integration_id'=>(string)($row['id']??''),'tenant_id'=>(int)($row['tenant_id']??0),'adapter'=>(string)($meta['adapter']??'custom'),'provider_name'=>(string)($row['account_name']??'IP-телефония'),'caller_id'=>(string)($row['account_username']??''),'call_control_ready'=>!empty($meta['call_control_ready']),'media_bridge_ready'=>!empty($meta['media_bridge_ready']),'media_bridge_required'=>empty($meta['media_bridge_ready']),'platform_voice_ready'=>class_exists(SalesPhoneGatewayService::class)&&!empty(SalesPhoneGatewayService::platformStatus()['sergey_ready']),'ai_voice_ready'=>(string)($meta['adapter']??'')==='direct_sip'&&class_exists(SalesPhoneGatewayService::class)&&!empty(SalesPhoneGatewayService::platformStatus()['sergey_ready'])&&!empty(SalesPhoneGatewayService::platformStatus()['worker_online']),'label'=>(string)($row['label']??'Телефония')];
    }
    public static function statusForScript(array $script): array {
        if(class_exists(SalesPartnerIntegrationService::class)){$bindings=SalesPartnerIntegrationService::bindingsForCurrentScript($script);if(isset($bindings['sip']))return self::statusFromIntegration((array)$bindings['sip']);}
        return ['connected'=>false,'integration_id'=>'','tenant_id'=>0,'adapter'=>'','provider_name'=>'','caller_id'=>'','call_control_ready'=>false,'media_bridge_ready'=>false,'media_bridge_required'=>true,'platform_voice_ready'=>class_exists(SalesPhoneGatewayService::class)&&!empty(SalesPhoneGatewayService::platformStatus()['sergey_ready']),'ai_voice_ready'=>false,'label'=>'Телефония'];
    }

    public function connectPartnerIntegration(string $label,string $adapter,array $input): array {
        if(!class_exists(SalesPartnerIntegrationService::class))throw new \RuntimeException('Хранилище партнёрских интеграций недоступно.');$adapter=self::normalizeAdapter($adapter);
        if($adapter==='mango'){
            $key=self::normalizeSecret((string)($input['api_key']??''),'API key MANGO',6,190);$salt=self::normalizeSecret((string)($input['api_salt']??''),'API salt MANGO',6,190);$extension=self::normalizeExtension((string)($input['extension']??''));$caller=self::normalizePhone((string)($input['caller_id']??''),true);
            $probe=$this->mangoApi($key,$salt,'/vpbx/config/users/request',['extension'=>$extension]);
            SalesPartnerIntegrationService::assertAccountAvailable('sip','mango:'.$key);
            return SalesPartnerIntegrationService::createVerifiedTelephony($label,['adapter'=>'mango','api_key'=>$key,'api_salt'=>$salt,'extension'=>$extension,'caller_id'=>$caller],['account_key'=>'mango:'.$key,'account_name'=>'MANGO OFFICE','account_username'=>$caller!==''?$caller:$extension,'adapter'=>'mango','adapter_label'=>'MANGO OFFICE','call_control_ready'=>true,'media_bridge_ready'=>false,'api_base'=>self::MANGO_BASE,'extension'=>$extension,'probe_users'=>is_array($probe['users']??null)?count($probe['users']):null]);
        }
        if($adapter==='uis'){
            $login=self::normalizeSecret((string)($input['login']??''),'Логин UIS',3,190);$password=self::normalizeSecret((string)($input['password']??''),'Пароль UIS',4,512);$virtual=self::normalizePhone((string)($input['virtual_number']??''));$session=$this->uisLogin($login,$password);$this->uisLogout((string)($session['access_token']??''));
            SalesPartnerIntegrationService::assertAccountAvailable('sip','uis:'.strtolower($login));
            return SalesPartnerIntegrationService::createVerifiedTelephony($label,['adapter'=>'uis','login'=>$login,'password'=>$password,'virtual_number'=>$virtual],['account_key'=>'uis:'.strtolower($login),'account_name'=>'UIS / CoMagic','account_username'=>$virtual,'adapter'=>'uis','adapter_label'=>'UIS / CoMagic','call_control_ready'=>true,'media_bridge_ready'=>false,'api_base'=>self::UIS_BASE,'virtual_number'=>$virtual]);
        }
        if($adapter==='direct_sip'){
            $providerUrl=self::normalizeDirectSipUrl((string)($input['provider_url']??''));$username=self::normalizeSecret((string)($input['username']??''),'SIP-логин',1,256);$password=self::normalizeSecret((string)($input['password']??''),'SIP-пароль',1,4096);$caller=self::normalizePhone((string)($input['caller_id']??''));$inbound=self::normalizePhone((string)($input['inbound_did']??''),true);if($inbound==='')$inbound=$caller;$handoff=self::normalizeHandoffTarget((string)($input['handoff_target_uri']??''),true);$name=self::clean((string)($input['provider_name']??'Direct SIP'),120);if($name==='')$name='Direct SIP';
            $accountKey='direct-sip-did:'.$inbound;SalesPartnerIntegrationService::assertAccountAvailable('sip',$accountKey);
            return SalesPartnerIntegrationService::createConfigured('sip',$label,['adapter'=>'direct_sip','provider_url'=>$providerUrl,'username'=>$username,'password'=>$password,'caller_id'=>$caller,'inbound_did'=>$inbound,'handoff_target_uri'=>$handoff,'provider_name'=>$name],['account_key'=>$accountKey,'account_name'=>$name,'account_username'=>$inbound,'adapter'=>'direct_sip','adapter_label'=>'SIP trunk · Сергей','call_control_ready'=>true,'media_bridge_ready'=>true,'direct_sip_ready'=>true,'provider_url'=>$providerUrl,'caller_id'=>$caller,'inbound_did'=>$inbound,'handoff_target_uri'=>$handoff]);
        }
        $server=self::normalizeHttps((string)($input['server']??''));$login=self::normalizeSecret((string)($input['login']??''),'Логин/ID провайдера',2,190);$password=self::normalizeSecret((string)($input['password']??''),'Пароль/API token провайдера',4,512);$caller=self::normalizePhone((string)($input['caller_id']??''),true);$name=self::clean((string)($input['provider_name']??'Другой провайдер'),120);if($name==='')$name='Другой провайдер';
        SalesPartnerIntegrationService::assertAccountAvailable('sip','custom:'.hash('sha256',strtolower($server.'|'.$login)));
        return SalesPartnerIntegrationService::createConfigured('sip',$label,['adapter'=>'custom','server'=>$server,'login'=>$login,'password'=>$password,'caller_id'=>$caller,'provider_name'=>$name],['account_key'=>'custom:'.hash('sha256',strtolower($server.'|'.$login)),'account_name'=>$name,'account_username'=>$caller,'adapter'=>'custom','adapter_label'=>$name,'call_control_ready'=>false,'media_bridge_ready'=>false,'api_base'=>$server]);
    }
    public function testPartnerIntegration(string $integrationId): array {
        $row=SalesPartnerIntegrationService::rawCurrentTenant($integrationId);if(!$row||((string)($row['provider']??''))!=='sip')throw new \InvalidArgumentException('Подключение телефонии не найдено.');$cred=SalesPartnerIntegrationService::credentialsForRow($row);$adapter=self::normalizeAdapter((string)($cred['adapter']??'custom'));
        try{
            if($adapter==='mango'){$probe=$this->mangoApi((string)$cred['api_key'],(string)$cred['api_salt'],'/vpbx/config/users/request',['extension'=>self::normalizeExtension((string)$cred['extension'])]);SalesPartnerIntegrationService::markCheck($integrationId,true,'');return ['ok'=>true,'adapter'=>'mango','remote_verified'=>true,'probe'=>$probe,'media_bridge_ready'=>false];}
            if($adapter==='uis'){$session=$this->uisLogin((string)$cred['login'],(string)$cred['password']);$this->uisLogout((string)($session['access_token']??''));SalesPartnerIntegrationService::markCheck($integrationId,true,'');return ['ok'=>true,'adapter'=>'uis','remote_verified'=>true,'media_bridge_ready'=>false];}
            if($adapter==='direct_sip'){self::normalizeDirectSipUrl((string)($cred['provider_url']??''));self::normalizePhone((string)($cred['caller_id']??''));$st=class_exists(SalesPhoneGatewayService::class)?SalesPhoneGatewayService::platformStatus():[];$ready=!empty($st['configured'])&&!empty($st['sergey_ready']);SalesPartnerIntegrationService::markCheck($integrationId,$ready,$ready?'':'Телефонный медиашлюз или Сергей ещё не настроены администратором CKM.');return ['ok'=>$ready,'adapter'=>'direct_sip','remote_verified'=>false,'media_bridge_ready'=>true,'platform_voice_ready'=>!empty($st['sergey_ready']),'phone_gateway_online'=>!empty($st['worker_online']),'message'=>$ready?(!empty($st['worker_online'])?'SIP trunk и телефонный медиашлюз Сергея готовы к входящему тестовому звонку.':'SIP trunk сохранён; Сергей готов, но phone media worker пока офлайн.'):'SIP trunk сохранён, но телефонный медиашлюз Сергея ещё не настроен.'];}
            return ['ok'=>true,'adapter'=>'custom','remote_verified'=>false,'media_bridge_ready'=>false,'message'=>'Пользовательский адаптер сохранён. Удалённая проверка появится после выбора конкретного API провайдера.'];
        }catch(\Throwable $e){SalesPartnerIntegrationService::markCheck($integrationId,false,$e->getMessage());throw $e;}
    }
    public function disconnectPartnerIntegration(string $integrationId): array {return SalesPartnerIntegrationService::deleteCurrentTenant($integrationId);}

    /** Call-control primitive for the next media-gateway layer. Does not pretend to be an AI voice call. */
    public function startProviderCall(array $row,string $contact,string $operatorEndpoint=''): array {
        if((string)($row['provider']??'')!=='sip')throw new \InvalidArgumentException('Телефонное подключение не найдено.');$cred=SalesPartnerIntegrationService::credentialsForRow($row);$adapter=self::normalizeAdapter((string)($cred['adapter']??'custom'));$contact=self::normalizePhone($contact);
        if($adapter==='mango'){$command='ckm.'.substr(hash('sha256',wp_generate_uuid4().microtime(true)),0,32);$result=$this->mangoApi((string)$cred['api_key'],(string)$cred['api_salt'],'/vpbx/commands/callback',['command_id'=>$command,'from'=>['extension'=>self::normalizeExtension((string)$cred['extension'])],'to_number'=>$contact]);return ['adapter'=>'mango','command_id'=>$command,'provider_response'=>$result];}
        if($adapter==='uis'){$operator=self::normalizePhone($operatorEndpoint);$auth=$this->uisLogin((string)$cred['login'],(string)$cred['password']);$token=(string)$auth['access_token'];try{$result=$this->uisRpc('start.simple_call',['access_token'=>$token,'first_call'=>'operator','switch_at_once'=>true,'virtual_phone_number'=>self::normalizePhone((string)$cred['virtual_number']),'external_id'=>'ckm-'.substr(hash('sha256',wp_generate_uuid4()),0,20),'direction'=>'out','contact'=>$contact,'operator'=>$operator]);return ['adapter'=>'uis','provider_response'=>$result];}finally{$this->uisLogout($token);}}
        throw new \RuntimeException('Для пользовательского адаптера ещё не настроен метод исходящего звонка.');
    }
}
