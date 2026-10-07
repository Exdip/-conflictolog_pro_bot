<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class AssignmentController {
    public static function register(): void {
        register_rest_route('ckm/v1','/negotiation/assignments',[
            ['methods'=>'GET','callback'=>[self::class,'index'],'permission_callback'=>[self::class,'permission']],
            ['methods'=>'POST','callback'=>[self::class,'create'],'permission_callback'=>[self::class,'permission']],
        ]);
        register_rest_route('ckm/v1','/negotiation/assignments/mine',[
            'methods'=>'GET','callback'=>[self::class,'mine'],'permission_callback'=>[self::class,'permission'],
        ]);
        register_rest_route('ckm/v1','/negotiation/assignments/(?P<id>\d+)',[
            'methods'=>'GET','callback'=>[self::class,'detail'],'permission_callback'=>[self::class,'permission'],
        ]);
        register_rest_route('ckm/v1','/negotiation/assignments/(?P<id>\d+)/participants',[
            'methods'=>'POST','callback'=>[self::class,'participants'],'permission_callback'=>[self::class,'permission'],
        ]);
        register_rest_route('ckm/v1','/negotiation/assignments/(?P<id>\d+)/participants/(?P<participant_id>\d+)/remove',[
            'methods'=>'POST','callback'=>[self::class,'removeParticipant'],'permission_callback'=>[self::class,'permission'],
        ]);
        register_rest_route('ckm/v1','/negotiation/assignments/(?P<id>\d+)/activate',[
            'methods'=>'POST','callback'=>[self::class,'activate'],'permission_callback'=>[self::class,'permission'],
        ]);
        register_rest_route('ckm/v1','/negotiation/assignments/(?P<id>\d+)/close',[
            'methods'=>'POST','callback'=>[self::class,'close'],'permission_callback'=>[self::class,'permission'],
        ]);
        register_rest_route('ckm/v1','/negotiation/assignments/(?P<id>\d+)/launch',[
            'methods'=>'POST','callback'=>[self::class,'launch'],'permission_callback'=>[self::class,'permission'],
        ]);
    }

    public static function permission() {
        if (!is_user_logged_in()) { return new \WP_Error('neg_auth_required','Требуется вход.',['status'=>401]); }
        try { Access::context(); return true; }
        catch (\Throwable) { return new \WP_Error('neg_access_denied','Доступ запрещён.',['status'=>403]); }
    }
    private static function body($request): array {
        $data=method_exists($request,'get_json_params')?$request->get_json_params():null;
        if(!is_array($data))$data=method_exists($request,'get_params')?$request->get_params():[];
        return is_array($data)?$data:[];
    }
    private static function id($request): int { return (int)(method_exists($request,'get_param')?$request->get_param('id'):0); }
    private static function response(array $data,int $status=200): \WP_REST_Response { return new \WP_REST_Response(['ok'=>true]+$data,$status,['Cache-Control'=>'no-store, private']); }
    private static function fail(\Throwable $e): \WP_REST_Response {
        if($e instanceof AssignmentAccessDeniedException)return new \WP_REST_Response(['ok'=>false,'code'=>'NEG_ASSIGNMENT_FORBIDDEN','message'=>$e->getMessage()],403,['Cache-Control'=>'no-store, private']);
        if($e instanceof AssignmentAttemptLimitException)return new \WP_REST_Response(['ok'=>false,'code'=>'NEG_ASSIGNMENT_ATTEMPT_LIMIT','message'=>$e->getMessage()],409,['Cache-Control'=>'no-store, private']);
        if($e instanceof AssignmentUnavailableException)return new \WP_REST_Response(['ok'=>false,'code'=>'NEG_ASSIGNMENT_UNAVAILABLE','message'=>$e->getMessage()],409,['Cache-Control'=>'no-store, private']);
        if($e instanceof AssignmentValidationException||$e instanceof \InvalidArgumentException)return new \WP_REST_Response(['ok'=>false,'code'=>'NEG_ASSIGNMENT_VALIDATION','message'=>$e->getMessage()],422,['Cache-Control'=>'no-store, private']);
        return new \WP_REST_Response(['ok'=>false,'code'=>'NEG_ASSIGNMENT_ERROR','message'=>'Не удалось выполнить действие назначения.'],500,['Cache-Control'=>'no-store, private']);
    }

    public static function index($request): \WP_REST_Response { try { $s=new AssignmentService(); if(!AssignmentService::canManage())throw new AssignmentAccessDeniedException('Назначения доступны организатору.'); return self::response(['assignments'=>$s->listOwn(),'scenarios'=>$s->availableScenarios()]); } catch(\Throwable $e){return self::fail($e);} }
    public static function create($request): \WP_REST_Response { try { return self::response(['assignment'=>(new AssignmentService())->create(self::body($request))],201); } catch(\Throwable $e){return self::fail($e);} }
    public static function mine($request): \WP_REST_Response { try { return self::response(['assignments'=>(new AssignmentService())->mine()]); } catch(\Throwable $e){return self::fail($e);} }
    public static function detail($request): \WP_REST_Response { try { return self::response(['assignment'=>(new AssignmentService())->detail(self::id($request))]); } catch(\Throwable $e){return self::fail($e);} }
    public static function participants($request): \WP_REST_Response { try { $d=self::body($request);$rows=$d['participants']??[];if(!is_array($rows))$rows=[];return self::response(['assignment'=>(new AssignmentService())->addParticipants(self::id($request),$rows)]); } catch(\Throwable $e){return self::fail($e);} }
    public static function removeParticipant($request): \WP_REST_Response { try { $pid=(int)(method_exists($request,'get_param')?$request->get_param('participant_id'):0);return self::response(['assignment'=>(new AssignmentService())->removeParticipant(self::id($request),$pid)]); } catch(\Throwable $e){return self::fail($e);} }
    public static function activate($request): \WP_REST_Response { try { return self::response(['assignment'=>(new AssignmentService())->setStatus(self::id($request),'active')]); } catch(\Throwable $e){return self::fail($e);} }
    public static function close($request): \WP_REST_Response { try { return self::response(['assignment'=>(new AssignmentService())->setStatus(self::id($request),'closed')]); } catch(\Throwable $e){return self::fail($e);} }
    public static function launch($request): \WP_REST_Response { try { return self::response(['launch'=>(new AssignmentService())->launch(self::id($request))],201); } catch(\Throwable $e){return self::fail($e);} }
}
