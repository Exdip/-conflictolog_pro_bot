<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class BuilderController {
    public static function register(): void {
        register_rest_route('ckm/v1','/negotiation/builder/scenarios',[
            ['methods'=>'GET','callback'=>[self::class,'index'],'permission_callback'=>[self::class,'permission']],
            ['methods'=>'POST','callback'=>[self::class,'create'],'permission_callback'=>[self::class,'permission']],
        ]);
        register_rest_route('ckm/v1','/negotiation/builder/scenarios/(?P<id>\d+)',[
            'methods'=>'GET','callback'=>[self::class,'detail'],'permission_callback'=>[self::class,'permission'],
        ]);
        register_rest_route('ckm/v1','/negotiation/builder/scenarios/(?P<id>\d+)/save',[
            'methods'=>'POST','callback'=>[self::class,'save'],'permission_callback'=>[self::class,'permission'],
        ]);
        register_rest_route('ckm/v1','/negotiation/builder/scenarios/(?P<id>\d+)/validate',[
            'methods'=>'POST','callback'=>[self::class,'validateScenario'],'permission_callback'=>[self::class,'permission'],
        ]);
        register_rest_route('ckm/v1','/negotiation/builder/scenarios/(?P<id>\d+)/publish',[
            'methods'=>'POST','callback'=>[self::class,'publish'],'permission_callback'=>[self::class,'permission'],
        ]);
        register_rest_route('ckm/v1','/negotiation/builder/scenarios/(?P<id>\d+)/test-launch',[
            'methods'=>'POST','callback'=>[self::class,'testLaunch'],'permission_callback'=>[self::class,'permission'],
        ]);
        register_rest_route('ckm/v1','/negotiation/builder/scenarios/(?P<id>\d+)/archive',[
            'methods'=>'POST','callback'=>[self::class,'archive'],'permission_callback'=>[self::class,'permission'],
        ]);
        register_rest_route('ckm/v1','/negotiation/builder/duplicate',[
            'methods'=>'POST','callback'=>[self::class,'duplicate'],'permission_callback'=>[self::class,'permission'],
        ]);
    }

    public static function permission() {
        if(!is_user_logged_in())return new \WP_Error('neg_auth_required','Требуется вход.',['status'=>401]);
        return ScenarioBuilderService::canUse() ? true : new \WP_Error('neg_builder_forbidden','Конструктор сценариев недоступен.',['status'=>403]);
    }
    private static function body($request): array {
        $data=method_exists($request,'get_json_params')?$request->get_json_params():null;
        if(!is_array($data))$data=method_exists($request,'get_params')?$request->get_params():[];
        return is_array($data)?$data:[];
    }
    private static function id($request): int { return (int)(method_exists($request,'get_param')?$request->get_param('id'):0); }
    private static function response(array $data,int $status=200): \WP_REST_Response { return new \WP_REST_Response(['ok'=>true]+$data,$status,['Cache-Control'=>'no-store, private']); }
    private static function fail(\Throwable $e): \WP_REST_Response {
        if($e instanceof ScenarioBuilderAccessDeniedException)return new \WP_REST_Response(['ok'=>false,'code'=>'NEG_BUILDER_FORBIDDEN','message'=>$e->getMessage()],403,['Cache-Control'=>'no-store, private']);
        if($e instanceof ScenarioBuilderValidationException)return new \WP_REST_Response(['ok'=>false,'code'=>'NEG_BUILDER_VALIDATION','message'=>$e->getMessage(),'issues'=>$e->issues()],422,['Cache-Control'=>'no-store, private']);
        $status=$e instanceof \InvalidArgumentException?422:500;
        return new \WP_REST_Response(['ok'=>false,'code'=>'NEG_BUILDER_ERROR','message'=>$status===500?'Не удалось выполнить действие конструктора.':$e->getMessage()],$status,['Cache-Control'=>'no-store, private']);
    }

    public static function index($request): \WP_REST_Response { try{$s=new ScenarioBuilderService();return self::response(['scenarios'=>$s->listOwn(),'templates'=>$s->templates()]);}catch(\Throwable $e){return self::fail($e);} }
    public static function create($request): \WP_REST_Response { try{$d=self::body($request);return self::response(['detail'=>(new ScenarioBuilderService())->create((string)($d['title']??''))],201);}catch(\Throwable $e){return self::fail($e);} }
    public static function detail($request): \WP_REST_Response { try{return self::response(['detail'=>(new ScenarioBuilderService())->detail(self::id($request))]);}catch(\Throwable $e){return self::fail($e);} }
    public static function save($request): \WP_REST_Response { try{return self::response(['detail'=>(new ScenarioBuilderService())->save(self::id($request),self::body($request))]);}catch(\Throwable $e){return self::fail($e);} }
    public static function validateScenario($request): \WP_REST_Response { try{return self::response(['validation'=>(new ScenarioBuilderService())->validate(self::id($request))]);}catch(\Throwable $e){return self::fail($e);} }
    public static function publish($request): \WP_REST_Response { try{return self::response(['detail'=>(new ScenarioBuilderService())->publish(self::id($request))]);}catch(\Throwable $e){return self::fail($e);} }
    public static function testLaunch($request): \WP_REST_Response { try{$d=self::body($request);$mode=(string)($d['mode']??'training');return self::response(['test'=>(new ScenarioBuilderService())->testLaunch(self::id($request),$mode)],201);}catch(\Throwable $e){return self::fail($e);} }
    public static function archive($request): \WP_REST_Response { try{return self::response(['scenario'=>(new ScenarioBuilderService())->archive(self::id($request))]);}catch(\Throwable $e){return self::fail($e);} }
    public static function duplicate($request): \WP_REST_Response { try{$d=self::body($request);$id=(int)($d['source_scenario_id']??0);if($id<=0)throw new ScenarioBuilderValidationException(['Выберите исходный сценарий.']);return self::response(['detail'=>(new ScenarioBuilderService())->duplicate($id)],201);}catch(\Throwable $e){return self::fail($e);} }
}
