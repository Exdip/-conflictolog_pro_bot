<?php
/**
 * Deterministic end-to-end simulation of the partner T-Bank flow.
 * No external credentials/network are used: wp_remote_post is mocked.
 */
define('ABSPATH', __DIR__ . '/../');
define('ARRAY_A', 'ARRAY_A');
class WP_Error { public string $code; public string $message; public function __construct($code='error',$message=''){ $this->code=$code; $this->message=$message; } public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
function is_wp_error($v){ return $v instanceof WP_Error; }
function wp_salt($scheme='auth'){ return 'fixed-test-salt'; }
function wp_json_encode($v,$flags=0){ return json_encode($v,$flags); }
function sanitize_text_field($v){ return trim(strip_tags((string)$v)); }
function sanitize_email($v){ return filter_var((string)$v,FILTER_SANITIZE_EMAIL); }
function sanitize_key($v){ return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v)); }
function esc_url_raw($v,$protocols=null){ return filter_var($v,FILTER_VALIDATE_URL) ? $v : ''; }
function ckm_quiz_pro_partner_table(string $name): string { return 'wp_ckm_partner_'.$name; }
function ckm_quiz_pro_partner_partner_active(int $tenantId): bool { return true; }
function ckm_quiz_pro_partner_user_is_owner(int $tenantId,int $uid): bool { return true; }
function ckm_quiz_pro_partner_member_create(int $tenantId,array $data): array|WP_Error { return ['user_id'=>777,'login'=>$data['login'],'email'=>$data['email']]; }

$mockMode='ok';
$terminal='TBankTest'; $password='test-password'; $tenant=17; $orderId='11111111-1111-4111-8111-111111111111'; $paymentId='13660'; $amount=150000;
$plain=json_encode(['terminal_key'=>$terminal,'password'=>$password],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$iv=random_bytes(12); $tag=''; $cipher=openssl_encrypt($plain,'aes-256-gcm',hash('sha256',wp_salt('auth').'|ckm-partner-payment-provider-v1',true),OPENSSL_RAW_DATA,$iv,$tag,'ckm-tbank',16);
$credentialsCipher='v1:'.base64_encode($iv.$tag.$cipher);
$paymentOrder=['id'=>1,'tenant_id'=>$tenant,'owner_user_id'=>10,'customer_user_id'=>0,'customer_name'=>'Иван','customer_login'=>'ivan','customer_email'=>'ivan@example.test','amount_minor'=>$amount,'currency'=>'RUB','description'=>'Доступ организатора','status'=>'created','access_status'=>'pending','provider'=>'tbank','tbank_payment_id'=>'','order_id'=>$orderId,'public_token'=>str_repeat('a',64),'confirmation_url'=>'','last_error'=>'','paid_at'=>null];
$paymentMethod=['id'=>1,'tenant_id'=>$tenant,'owner_user_id'=>10,'provider'=>'tbank','enabled'=>1,'credentials_cipher'=>$credentialsCipher,'credentials_hint'=>'Test','mode'=>'test','account_status'=>'reachable','webhook_secret_cipher'=>''];
class FakeWpdb {
    public array $updates=[];
    public function prepare($q,...$args){ return $q.' '.implode(' ',array_map('strval',$args)); }
    public function get_row($q,$mode=null){ global $paymentMethod,$paymentOrder; if(str_contains($q,'payment_methods')) return $paymentMethod; if(str_contains($q,'payment_orders')) return $paymentOrder; return null; }
    public function update($table,$data,$where){ global $paymentOrder; $this->updates[]=['table'=>$table,'data'=>$data,'where'=>$where]; foreach($data as $k=>$v){$paymentOrder[$k]=$v;} return 1; }
}
$GLOBALS['wpdb']=new FakeWpdb();
function wp_remote_post($url,$args){
    global $terminal,$orderId,$paymentId,$amount,$mockMode;
    if (str_ends_with($url,'/Init')) {
        return [
            'response'=>['code'=>200],
            'body'=>json_encode([
                'Success'=>true,'ErrorCode'=>'0','TerminalKey'=>$terminal,'Status'=>'NEW',
                'PaymentId'=>$paymentId,'OrderId'=>$orderId,'Amount'=>($mockMode==='wrong_amount'?$amount+100:$amount),
                'PaymentURL'=>'https://securepayments.tbank.ru/test/abc'
            ])
        ];
    }
    if (str_ends_with($url,'/GetState')) {
        return [
            'response'=>['code'=>200],
            'body'=>json_encode([
                'Success'=>($mockMode!=='not_success'),'ErrorCode'=>'0','TerminalKey'=>($mockMode==='wrong_terminal'?'OtherTerminal':$terminal),'Status'=>'CONFIRMED',
                'PaymentId'=>$paymentId,'OrderId'=>$orderId,'Amount'=>($mockMode==='wrong_amount'?$amount+100:$amount)
            ])
        ];
    }
    return ['response'=>['code'=>404],'body'=>'{}'];
}
function wp_remote_retrieve_response_code($r){return (int)$r['response']['code'];}
function wp_remote_retrieve_body($r){return (string)$r['body'];}
require_once __DIR__.'/../includes/partner/payment-provider.php';
require_once __DIR__.'/../includes/partner/tbank-provider.php';
require_once __DIR__.'/../includes/partner/payments.php';

$c=[]; function t(&$c,$ok,$name){$c[]=$ok; echo ($ok?'PASS':'FAIL')." $name\n";}
$provider=ckm_quiz_pro_partner_payment_provider('tbank');
t($c,$provider instanceof CKM_Quiz_Pro_Partner_TBank_Provider,'provider resolves to T-Bank');
$order=$paymentOrder;
$created=$provider->create_payment($order);
t($c,is_array($created) && $created['payment_id']===$paymentId,'Init returns PaymentId');
t($c,is_array($created) && $created['confirmation_url']==='https://securepayments.tbank.ru/test/abc','Init returns HTTPS PaymentURL');
t($c,is_array($created) && $created['status']==='new','Init returns NEW status');
$order['tbank_payment_id']=$paymentId;
$synced=$provider->sync_order($order);
t($c,is_array($synced) && ($synced['provider_response']['Status']??'')==='CONFIRMED','GetState returns CONFIRMED');
t($c,is_array($synced) && ($synced['provider_response']['Amount']??0)===$amount,'GetState returns exact amount');
t($c,is_array($synced) && ($synced['provider_response']['OrderId']??'')===$orderId,'GetState returns exact OrderId');
$reconciled=ckm_quiz_pro_partner_tbank_sync_order(array_merge($paymentOrder,['tbank_payment_id'=>$paymentId]));
t($c,is_array($reconciled) && ($reconciled['status']??'')==='succeeded','reconciliation maps CONFIRMED to succeeded');
t($c,is_array($reconciled) && ($reconciled['access_status']??'')==='active','successful reconciliation activates organizer');
t($c,($reconciled['customer_user_id']??0)===777,'activation links created organizer user');
$paymentOrder['access_status']='pending'; $paymentOrder['status']='created';
$scalar=['TerminalKey'=>$terminal,'OrderId'=>$orderId,'Success'=>true,'Status'=>'CONFIRMED','PaymentId'=>$paymentId,'ErrorCode'=>'0','Amount'=>$amount];
$tokenData=$scalar; $tokenData['Password']=$password; foreach($tokenData as $k=>$v){ if(is_bool($v)) $tokenData[$k]=$v?'true':'false'; else $tokenData[$k]=(string)$v; } ksort($tokenData,SORT_STRING); $webhookToken=hash('sha256',implode('',array_values($tokenData))); $webhookPayload=$scalar; $webhookPayload['Token']=$webhookToken;
t($c,$provider->verify_webhook($tenant,$webhookPayload),'webhook token is accepted');
$webhookPayload['Amount']=$amount+1;
t($c,!$provider->verify_webhook($tenant,$webhookPayload),'tampered webhook is rejected');
$paymentOrder['access_status']='pending'; $paymentOrder['status']='created';
$webhookPayload['Amount']=$amount; $webhookResult=ckm_quiz_pro_partner_tbank_sync_webhook_payload($tenant,$webhookPayload);
t($c,is_array($webhookResult) && ($webhookResult['access_status']??'')==='active','webhook reconciliation activates organizer');
$paymentOrder['access_status']='pending'; $paymentOrder['status']='created';
$mockMode='wrong_amount';
$bad=ckm_quiz_pro_partner_tbank_sync_order(array_merge($paymentOrder,['tbank_payment_id'=>$paymentId]));
t($c,is_wp_error($bad) && $bad->get_error_code()==='amount_mismatch','wrong amount is rejected');
$paymentOrder['access_status']='pending'; $paymentOrder['status']='created';
$mockMode='wrong_terminal';
$bad=ckm_quiz_pro_partner_tbank_sync_order(array_merge($paymentOrder,['tbank_payment_id'=>$paymentId]));
t($c,is_wp_error($bad) && $bad->get_error_code()==='terminal_mismatch','wrong terminal is rejected');
$paymentOrder['access_status']='pending'; $paymentOrder['status']='created';
$mockMode='not_success';
$bad=ckm_quiz_pro_partner_tbank_sync_order(array_merge($paymentOrder,['tbank_payment_id'=>$paymentId]));
t($c,is_wp_error($bad) && $bad->get_error_code()==='provider_status','non-success response is rejected');
$fail=count(array_filter($c,fn($x)=>!$x)); echo 'TOTAL '.count($c).' FAIL '.$fail."\n"; exit($fail?1:0);
