<?php
namespace {
    define('ABSPATH', __DIR__.'/');
    $GLOBALS['sales576_meta']=[];
    function wp_strip_all_tags($v){return strip_tags((string)$v);}
    function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
    function wp_generate_uuid4(){return '11111111-2222-4333-8444-555555555555';}
    function get_current_user_id(){return 7;}
    function get_user_meta($uid,$key,$single=true){return $GLOBALS['sales576_meta'][$uid][$key]??[];}
    function update_user_meta($uid,$key,$value){$GLOBALS['sales576_meta'][$uid][$key]=$value;return true;}
}
namespace CKM\EffectiveSales {
    final class SalesPracticeFeedbackService { public static function canManage(): bool { return true; } }
    final class SalesScriptService {
        public static function find(string $id): ?array { return $id==='script-1'?['id'=>'script-1']:null; }
        public static function currentScopeKey(): string { return 'tenant:alpha'; }
    }
}
namespace {
    require dirname(__DIR__).'/modules/effective-sales/application/sales-ai-seller-workspace-service.php';
    use CKM\EffectiveSales\SalesAiSellerWorkspaceService;

    $data=[
        'external_id'=>'crm-call-ABC-123',
        'channel'=>'phone',
        'outcome'=>'unsuccessful',
        'messages'=>[
            ['role'=>'client','content'=>'Нам это кажется слишком дорогим.'],
            ['role'=>'seller','content'=>'Давайте я объясню преимущества ещё раз.'],
        ],
    ];
    $first=SalesAiSellerWorkspaceService::importHumanDialog('script-1',$data);
    $second=SalesAiSellerWorkspaceService::importHumanDialog('script-1',$data);

    $fail=function(string $m){fwrite(STDERR,"FAIL: {$m}\n");exit(1);};
    if(!str_starts_with((string)$first['session_id'],'real_'))$fail('session id');
    if(!empty($first['reused']))$fail('first import must be new');
    if(empty($second['reused']))$fail('second import must be idempotent');
    $dialog=(array)$second['dialog'];
    if(($dialog['source_kind']??'')!=='human_import')$fail('source kind');
    if(($dialog['control_mode']??'')!=='human')$fail('control mode');
    if(($dialog['goal_status']??'')!=='unsuccessful')$fail('outcome');
    if(($dialog['channel']??'')!=='phone')$fail('channel');
    $raw=json_encode($GLOBALS['sales576_meta'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(str_contains((string)$raw,'crm-call-ABC-123'))$fail('raw external id leaked into storage');
    if(empty($dialog['external_source_hash']))$fail('external source hash missing');
    echo "SALES-576 real dialog import regression passed.\n";
}
