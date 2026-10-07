<?php
namespace CKM\EffectiveSales;
if (!defined('ABSPATH')) { exit; }

use CKM\NegotiationMaster\Access;
use CKM\NegotiationMaster\AssignmentAccessDeniedException;
use CKM\NegotiationMaster\AssignmentAttemptLimitException;
use CKM\NegotiationMaster\AssignmentUnavailableException;
use CKM\NegotiationMaster\AssignmentValidationException;
use CKM\NegotiationMaster\CoachBusyException;
use CKM\NegotiationMaster\CoachNotAvailableException;
use CKM\NegotiationMaster\CoachService;
use CKM\NegotiationMaster\CoachUnavailableException;
use CKM\NegotiationMaster\EvaluationService;
use CKM\NegotiationMaster\EvaluationUnavailableException;
use CKM\NegotiationMaster\MessageService;
use CKM\NegotiationMaster\OpponentBusyException;
use CKM\NegotiationMaster\OpponentUnavailableException;
use CKM\NegotiationMaster\PlayerSessionSnapshotBuilder;
use CKM\NegotiationMaster\RecoveryBusyException;
use CKM\NegotiationMaster\RecoveryService;
use CKM\NegotiationMaster\Schema;
use CKM\NegotiationMaster\SessionCompletedException;
use CKM\NegotiationMaster\SessionService;
use CKM\NegotiationMaster\StateConflictException;
use CKM\NegotiationMaster\WriterConflictException;

final class SalesController {
    public static function register(): void {
        register_rest_route('ckm/v1','/sales/sessions/start',['methods'=>'POST','callback'=>[self::class,'start'],'permission_callback'=>[self::class,'permission']]);
        register_rest_route('ckm/v1','/sales/sessions/(?P<id>\d+)',['methods'=>'GET','callback'=>[self::class,'resume'],'permission_callback'=>[self::class,'permission']]);
        register_rest_route('ckm/v1','/sales/sessions/(?P<id>\d+)/messages',['methods'=>'POST','callback'=>[self::class,'message'],'permission_callback'=>[self::class,'permission']]);
        register_rest_route('ckm/v1','/sales/sessions/(?P<id>\d+)/coach',['methods'=>'POST','callback'=>[self::class,'coach'],'permission_callback'=>[self::class,'permission']]);
        register_rest_route('ckm/v1','/sales/sessions/(?P<id>\d+)/complete',['methods'=>'POST','callback'=>[self::class,'complete'],'permission_callback'=>[self::class,'permission']]);
        register_rest_route('ckm/v1','/sales/sessions/(?P<id>\d+)/evaluate',['methods'=>'POST','callback'=>[self::class,'evaluate'],'permission_callback'=>[self::class,'permission']]);
        register_rest_route('ckm/v1','/sales/sessions/(?P<id>\d+)/result',['methods'=>'GET','callback'=>[self::class,'result'],'permission_callback'=>[self::class,'permission']]);
        register_rest_route('ckm/v1','/sales/sessions/(?P<id>\d+)/writer/takeover',['methods'=>'POST','callback'=>[self::class,'takeoverWriter'],'permission_callback'=>[self::class,'permission']]);
        register_rest_route('ckm/v1','/sales/practice/dialogs/import',['methods'=>'POST','callback'=>[self::class,'importPracticeDialog'],'permission_callback'=>[self::class,'permission']]);
        register_rest_route('ckm/v1','/sales/competitions',[
            ['methods'=>'GET','callback'=>[self::class,'competitions'],'permission_callback'=>[self::class,'permission']],
            ['methods'=>'POST','callback'=>[self::class,'createCompetition'],'permission_callback'=>[self::class,'permission']],
        ]);
        register_rest_route('ckm/v1','/sales/competitions/mine',['methods'=>'GET','callback'=>[self::class,'myCompetitions'],'permission_callback'=>[self::class,'permission']]);
        register_rest_route('ckm/v1','/sales/competitions/(?P<id>\d+)',['methods'=>'GET','callback'=>[self::class,'competitionDetail'],'permission_callback'=>[self::class,'permission']]);
        register_rest_route('ckm/v1','/sales/competitions/(?P<id>\d+)/launch',['methods'=>'POST','callback'=>[self::class,'launchCompetition'],'permission_callback'=>[self::class,'permission']]);
        register_rest_route('ckm/v1','/sales/competitions/(?P<id>\d+)/waiting',['methods'=>'GET','callback'=>[self::class,'competitionWaiting'],'permission_callback'=>[self::class,'permission']]);
        register_rest_route('ckm/v1','/sales/competitions/(?P<id>\d+)/host',['methods'=>'GET','callback'=>[self::class,'competitionHost'],'permission_callback'=>[self::class,'permission']]);
        register_rest_route('ckm/v1','/sales/competitions/(?P<id>\d+)/host/start',['methods'=>'POST','callback'=>[self::class,'startCompetitionByHost'],'permission_callback'=>[self::class,'permission']]);
        register_rest_route('ckm/v1','/sales/competitions/(?P<id>\d+)/close',['methods'=>'POST','callback'=>[self::class,'closeCompetition'],'permission_callback'=>[self::class,'permission']]);
        register_rest_route('ckm/v1','/sales/ai-seller/start',['methods'=>'POST','callback'=>[self::class,'aiSellerStart'],'permission_callback'=>'__return_true']);
        register_rest_route('ckm/v1','/sales/ai-seller/message',['methods'=>'POST','callback'=>[self::class,'aiSellerMessage'],'permission_callback'=>'__return_true']);
        register_rest_route('ckm/v1','/sales/ai-seller/end',['methods'=>'POST','callback'=>[self::class,'aiSellerEnd'],'permission_callback'=>'__return_true']);
        register_rest_route('ckm/v1','/sales/ai-seller/voice-ticket',['methods'=>'POST','callback'=>[self::class,'aiSellerVoiceTicket'],'permission_callback'=>'__return_true']);
        register_rest_route('ckm/v1','/sales/ai-seller/speak',['methods'=>'POST','callback'=>[self::class,'aiSellerSpeak'],'permission_callback'=>'__return_true']);
        register_rest_route('ckm/v1','/sales/ai-seller/state',['methods'=>'POST','callback'=>[self::class,'aiSellerState'],'permission_callback'=>'__return_true']);
        register_rest_route('ckm/v1','/sales/telegram/webhook/(?P<hook>[a-f0-9]{48,64})',['methods'=>'POST','callback'=>[self::class,'telegramWebhook'],'permission_callback'=>'__return_true']);
        register_rest_route('ckm/v1','/sales/max/webhook/(?P<hook>[a-f0-9]{48,64})',['methods'=>'POST','callback'=>[self::class,'maxWebhook'],'permission_callback'=>'__return_true']);
        register_rest_route('ckm/v1','/sales/whatsapp/webhook/(?P<hook>[a-f0-9]{48,64})',['methods'=>['GET','POST'],'callback'=>[self::class,'whatsappWebhook'],'permission_callback'=>'__return_true']);
        register_rest_route('ckm/v1','/sales/live-sip/webhook',['methods'=>'POST','callback'=>[self::class,'liveSipWebhook'],'permission_callback'=>'__return_true']);
        register_rest_route('ckm/v1','/sales/live-sip/sideband/claim',['methods'=>'POST','callback'=>[self::class,'liveSidebandClaim'],'permission_callback'=>'__return_true']);
        register_rest_route('ckm/v1','/sales/live-sip/sideband/event',['methods'=>'POST','callback'=>[self::class,'liveSidebandEvent'],'permission_callback'=>'__return_true']);
        register_rest_route('ckm/v1','/sales/live-sip/sideband/heartbeat',['methods'=>'POST','callback'=>[self::class,'liveSidebandHeartbeat'],'permission_callback'=>'__return_true']);
        register_rest_route('ckm/v1','/sales/phone-gateway/heartbeat',['methods'=>'POST','callback'=>[self::class,'phoneGatewayHeartbeat'],'permission_callback'=>'__return_true']);
        register_rest_route('ckm/v1','/sales/phone-gateway/open',['methods'=>'POST','callback'=>[self::class,'phoneGatewayOpen'],'permission_callback'=>'__return_true']);
        register_rest_route('ckm/v1','/sales/phone-gateway/turn',['methods'=>'POST','callback'=>[self::class,'phoneGatewayTurn'],'permission_callback'=>'__return_true']);
        register_rest_route('ckm/v1','/sales/phone-gateway/speak',['methods'=>'POST','callback'=>[self::class,'phoneGatewaySpeak'],'permission_callback'=>'__return_true']);
        register_rest_route('ckm/v1','/sales/phone-gateway/event',['methods'=>'POST','callback'=>[self::class,'phoneGatewayEvent'],'permission_callback'=>'__return_true']);
        register_rest_route('ckm/v1','/sales/phone-gateway/commands',['methods'=>'POST','callback'=>[self::class,'phoneGatewayCommands'],'permission_callback'=>'__return_true']);
    }

    public static function permission() {
        // A signed team URL is its own stateless identity and must win over any organizer
        // cookie that happens to be present in the same browser. Otherwise REST may run
        // as the organizer and reject the team assignment.
        if (SalesCompetitionService::teamAuthRequested()) {
            if (!SalesCompetitionService::authenticateRequest()) {
                return new \WP_Error('sales_team_link_invalid','Ссылка команды недействительна или устарела.',['status'=>403]);
            }
        } elseif (!is_user_logged_in()) {
            return new \WP_Error('sales_auth_required','Требуется вход.',['status'=>401]);
        }
        try { Access::context(); return true; }
        catch (\Throwable) { return new \WP_Error('sales_access_denied','Доступ запрещён.',['status'=>403]); }
    }

    private static function body($request): array {
        $data = method_exists($request,'get_json_params') ? $request->get_json_params() : null;
        if (!is_array($data) || !$data) { $data = method_exists($request,'get_params') ? $request->get_params() : []; }
        return is_array($data) ? $data : [];
    }

    private static function flag(mixed $v): bool {
        if (is_bool($v)) { return $v; }
        return in_array(strtolower((string)$v),['1','true','yes','on'],true);
    }

    private static function clientId(array $data): string { return RecoveryService::normalizeClientId((string)($data['client_id'] ?? '')); }

    private static function assertWriter(int $sessionId,array $data): RecoveryService {
        SalesDomain::versionForSession($sessionId);
        $recovery = new RecoveryService();
        $recovery->assertWriter($sessionId,self::clientId($data));
        return $recovery;
    }

    private static function decorate(array $snapshot): array {
        $session = is_array($snapshot['session'] ?? null) ? $snapshot['session'] : [];
        $versionId = (int)($session['scenario_version_id'] ?? 0);
        if ($versionId > 0) {
            $version = SalesDomain::assertVersion(Access::version($versionId));
            $snapshot['sales'] = SalesDomain::publicMechanics($version);
            global $wpdb;
            $factsTable = Schema::table('hidden_facts');
            $snapshot['sales']['hidden_fact_count'] = (int)$wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM `$factsTable` WHERE scenario_version_id=%d", $versionId)
            );
        }
        // Agreement UI is negotiation-specific and must never leak into the sales client.
        $snapshot['can_create_agreement'] = false;
        $snapshot['can_finish_without_agreement'] = false;
        // Competition is backed by exam mode: the coach must be unavailable even if a stale client asks for it.
        if ((string)($session['mode'] ?? 'training') !== 'training') { $snapshot['can_use_coach'] = false; }
        $exam = (string)($session['mode'] ?? 'training') === 'exam';
        $competition = $exam && (string)($session['session_kind'] ?? '') === 'assignment'
            && SalesCompetitionService::isCompetitionAssignment((int)($session['assignment_id'] ?? 0));
        $snapshot['sales_format'] = $competition ? 'competition' : ($exam ? 'check' : 'training');
        return $snapshot;
    }

    private static function id($request): int { return (int)(method_exists($request,'get_param') ? $request->get_param('id') : 0); }

    private static function failure(\Throwable $e,int $fallback=422): \WP_REST_Response {
        $status = $fallback; $code = 'SALES_SESSION_ERROR'; $message = $e->getMessage();
        if ($e instanceof WriterConflictException || $e instanceof RecoveryBusyException || $e instanceof StateConflictException || $e instanceof SessionCompletedException || $e instanceof OpponentBusyException || $e instanceof CoachBusyException) { $status=409; }
        elseif ($e instanceof AssignmentAccessDeniedException || $e instanceof CoachNotAvailableException) { $status=403; }
        elseif ($e instanceof AssignmentAttemptLimitException || $e instanceof AssignmentUnavailableException) { $status=409; }
        elseif ($e instanceof AssignmentValidationException) { $status=422; }
        elseif ($e instanceof OpponentUnavailableException || $e instanceof CoachUnavailableException || $e instanceof EvaluationUnavailableException) { $status=503; }
        elseif ($e instanceof \InvalidArgumentException) { $status=422; }
        if ($message === 'Session cannot be resumed.') { $status=409; $code='SESSION_NOT_RESUMABLE'; $message='Эту попытку уже нельзя продолжить. Начните новую тренировку.'; }
        elseif ($e instanceof WriterConflictException) { $code='WRITER_CONFLICT'; }
        elseif ($e instanceof StateConflictException) { $code='STATE_CONFLICT'; }
        elseif ($e instanceof SessionCompletedException) { $code='SESSION_COMPLETED'; }
        elseif ($e instanceof OpponentUnavailableException) { $code='SALES_CLIENT_UNAVAILABLE'; $message='Не удалось получить ответ ИИ-клиента.'; }
        elseif ($e instanceof CoachUnavailableException) { $code='SALES_COACH_UNAVAILABLE'; $message='Не удалось получить подсказку тренера.'; }
        elseif ($e instanceof EvaluationUnavailableException) { $code='SALES_EVALUATION_UNAVAILABLE'; $message='Итоговый разбор временно недоступен.'; }
        return new \WP_REST_Response(['ok'=>false,'code'=>$code,'message'=>$message],$status,['Cache-Control'=>'no-store, private']);
    }

    public static function start($request): \WP_REST_Response {
        try {
            $data=self::body($request); $scenarioId=(int)($data['scenario_id'] ?? 0); SalesDomain::versionForScenario($scenarioId);
            $service=new SessionService(); $restart=self::flag($data['restart'] ?? false); $clientId=self::clientId($data);
            if ($restart) { $active=$service->activeForScenario($scenarioId); if ($active && ($active['status']??'')==='in_progress') { (new RecoveryService())->assertWriter((int)$active['id'],$clientId); } }
            $result=$service->start($scenarioId,(string)($data['mode']??'training'),self::flag($data['voice_enabled']??false),$restart,(string)($data['difficulty']??'medium'));
            if (!empty($result['active_exists'])) { return new \WP_REST_Response(['ok'=>false,'code'=>'ACTIVE_SESSION_EXISTS','session_id'=>$result['session_id'],'status'=>$result['status']],409,['Cache-Control'=>'no-store, private']); }
            (new RecoveryService())->assertWriter((int)$result['session_id'],$clientId);
            $result['snapshot']=self::decorate((new PlayerSessionSnapshotBuilder())->build((int)$result['session_id']));
            return new \WP_REST_Response(['ok'=>true]+$result,201,['Cache-Control'=>'no-store, private']);
        } catch (\Throwable $e) { return self::failure($e,403); }
    }

    public static function resume($request): \WP_REST_Response {
        try {
            $id=self::id($request); SalesDomain::versionForSession($id); (new SessionService())->resume($id); $recovery=(new RecoveryService())->recover($id);
            return new \WP_REST_Response(['ok'=>true,'snapshot'=>self::decorate($recovery['snapshot']),'recovery'=>['recovered'=>!empty($recovery['recovered']),'busy'=>!empty($recovery['busy'])]],200,['Cache-Control'=>'no-store, private']);
        } catch (\Throwable $e) { return self::failure($e,403); }
    }

    public static function message($request): \WP_REST_Response {
        try {
            $id=self::id($request); $data=self::body($request); self::assertWriter($id,$data);
            $result=(new MessageService())->send($id,(string)($data['client_message_id']??''),(string)($data['content']??''),(string)($data['input_type']??'text'));
            if (is_array($result['snapshot']??null)) { $result['snapshot']=self::decorate($result['snapshot']); }
            $status=!empty($result['opponent_failed'])||!empty($result['opponent_pending'])?202:(!empty($result['idempotent'])?200:201);
            return new \WP_REST_Response(['ok'=>true]+$result,$status,['Cache-Control'=>'no-store, private']);
        } catch (\Throwable $e) { return self::failure($e,403); }
    }

    public static function coach($request): \WP_REST_Response {
        try {
            $id=self::id($request); $data=self::body($request); self::assertWriter($id,$data);
            $session=Access::session($id);
            if ((string)($session['mode'] ?? 'training') !== 'training') { throw new CoachNotAvailableException('В соревновании подсказки ИИ-тренера отключены.'); }
            $result=(new CoachService())->help($id,(string)($data['help_level']??'direction'),(string)($data['client_request_id']??''));
            return new \WP_REST_Response(['ok'=>true]+$result,!empty($result['idempotent'])?200:201,['Cache-Control'=>'no-store, private']);
        } catch (\Throwable $e) { return self::failure($e,403); }
    }

    public static function takeoverWriter($request): \WP_REST_Response {
        try {
            $id=self::id($request); $data=self::body($request); SalesDomain::versionForSession($id);
            $result=(new RecoveryService())->takeover($id,self::clientId($data));
            if (is_array($result['snapshot']??null)) { $result['snapshot']=self::decorate($result['snapshot']); }
            return new \WP_REST_Response(['ok'=>true]+$result,200,['Cache-Control'=>'no-store, private']);
        } catch (\Throwable $e) { return self::failure($e,403); }
    }

    public static function complete($request): \WP_REST_Response {
        try {
            $id=self::id($request); $data=self::body($request); self::assertWriter($id,$data);
            $revision=array_key_exists('expected_state_revision',$data)?(int)$data['expected_state_revision']:null;
            $done=(new SalesCompletionService())->complete($id,$revision);
            $result=(new EvaluationService())->evaluate($id);
            $gate=(new SalesCompetitionService())->resultGate($id);
            if (empty($gate['visible'])) {
                $result=['ready'=>true,'hidden'=>true,'message'=>(string)$gate['message'],'competition'=>$gate];
            }
            return new \WP_REST_Response(['ok'=>true]+$done+['result'=>$result],($result['ready']??false)?200:202,['Cache-Control'=>'no-store, private']);
        } catch (\Throwable $e) { return self::failure($e,403); }
    }

    public static function evaluate($request): \WP_REST_Response {
        try {
            $id=self::id($request); SalesDomain::versionForSession($id); $result=(new EvaluationService())->evaluate($id);
            $gate=(new SalesCompetitionService())->resultGate($id);
            if (empty($gate['visible'])) { $result=['ready'=>true,'hidden'=>true,'message'=>(string)$gate['message'],'competition'=>$gate]; }
            return new \WP_REST_Response(['ok'=>true,'result'=>$result],($result['ready']??false)?200:202,['Cache-Control'=>'no-store, private']);
        } catch (\Throwable $e) { return self::failure($e,403); }
    }

    public static function result($request): \WP_REST_Response {
        try {
            $id=self::id($request); SalesDomain::versionForSession($id); $result=(new EvaluationService())->evaluate($id);
            $gate=(new SalesCompetitionService())->resultGate($id);
            if (empty($gate['visible'])) { $result=['ready'=>true,'hidden'=>true,'message'=>(string)$gate['message'],'competition'=>$gate]; }
            return new \WP_REST_Response(['ok'=>true,'result'=>$result],200,['Cache-Control'=>'no-store, private']);
        } catch (\Throwable $e) { return self::failure($e,403); }
    }

    public static function importPracticeDialog($request): \WP_REST_Response {
        try {
            $data=self::body($request);$scriptId=trim((string)($data['script_id']??''));
            if($scriptId==='')throw new \InvalidArgumentException('Укажите методику продаж.');
            $result=SalesAiSellerWorkspaceService::importHumanDialog($scriptId,$data);
            $case=null;
            if(self::flag($data['create_case']??false)){
                $outcome=(string)($result['dialog']['goal_status']??'');
                if(in_array($outcome,['unsuccessful','stalled'],true)){
                    $made=SalesPracticeFeedbackService::createCase($scriptId,(string)$result['session_id']);$case=is_array($made['case']??null)?$made['case']:null;
                }
            }
            return new \WP_REST_Response(['ok'=>true]+$result+['training_case'=>$case],!empty($result['reused'])?200:201,['Cache-Control'=>'no-store, private']);
        } catch (\Throwable $e) { return self::failure($e,403); }
    }

    public static function competitions($request): \WP_REST_Response {
        try { return new \WP_REST_Response(['ok'=>true,'competitions'=>(new SalesCompetitionService())->managed()],200,['Cache-Control'=>'no-store, private']); }
        catch (\Throwable $e) { return self::failure($e,403); }
    }

    public static function createCompetition($request): \WP_REST_Response {
        try { return new \WP_REST_Response(['ok'=>true,'competition'=>(new SalesCompetitionService())->create(self::body($request))],201,['Cache-Control'=>'no-store, private']); }
        catch (\Throwable $e) { return self::failure($e,403); }
    }

    public static function myCompetitions($request): \WP_REST_Response {
        try { return new \WP_REST_Response(['ok'=>true,'competitions'=>(new SalesCompetitionService())->mine()],200,['Cache-Control'=>'no-store, private']); }
        catch (\Throwable $e) { return self::failure($e,403); }
    }

    public static function competitionDetail($request): \WP_REST_Response {
        try { return new \WP_REST_Response(['ok'=>true,'competition'=>(new SalesCompetitionService())->detail(self::id($request))],200,['Cache-Control'=>'no-store, private']); }
        catch (\Throwable $e) { return self::failure($e,403); }
    }

    public static function launchCompetition($request): \WP_REST_Response {
        try { return new \WP_REST_Response(['ok'=>true,'launch'=>(new SalesCompetitionService())->launch(self::id($request))],201,['Cache-Control'=>'no-store, private']); }
        catch (\Throwable $e) { return self::failure($e,403); }
    }

    public static function competitionWaiting($request): \WP_REST_Response {
        try { return new \WP_REST_Response(['ok'=>true,'competition'=>(new SalesCompetitionService())->waitingStatus(self::id($request))],200,['Cache-Control'=>'no-store, private']); }
        catch (\Throwable $e) { return self::failure($e,403); }
    }

    public static function competitionHost($request): \WP_REST_Response {
        try { return new \WP_REST_Response(['ok'=>true,'competition'=>(new SalesCompetitionService())->hostDashboard(self::id($request))],200,['Cache-Control'=>'no-store, private']); }
        catch (\Throwable $e) { return self::failure($e,403); }
    }

    public static function startCompetitionByHost($request): \WP_REST_Response {
        try { return new \WP_REST_Response(['ok'=>true,'competition'=>(new SalesCompetitionService())->startByHost(self::id($request))],200,['Cache-Control'=>'no-store, private']); }
        catch (\Throwable $e) { return self::failure($e,403); }
    }

    public static function closeCompetition($request): \WP_REST_Response {
        try { return new \WP_REST_Response(['ok'=>true,'competition'=>(new SalesCompetitionService())->close(self::id($request))],200,['Cache-Control'=>'no-store, private']); }
        catch (\Throwable $e) { return self::failure($e,403); }
    }

    private static function aiSellerFailure(\Throwable $e,int $fallback=422): \WP_REST_Response {
        $status=$fallback;if($e instanceof \InvalidArgumentException)$status=403;elseif($e instanceof \RuntimeException&&str_contains($e->getMessage(),'Слишком много запросов'))$status=429;
        return new \WP_REST_Response(['ok'=>false,'code'=>'AI_SELLER_ERROR','message'=>$e->getMessage()!==''?$e->getMessage():'Ошибка ИИ-продавца.'],$status,['Cache-Control'=>'no-store, private']);
    }
    public static function aiSellerStart($request): \WP_REST_Response {
        try{$d=self::body($request);$r=(new SalesAiSellerService())->start((string)($d['seller_token']??''));return new \WP_REST_Response(['ok'=>true]+$r,201,['Cache-Control'=>'no-store, private']);}catch(\Throwable $e){return self::aiSellerFailure($e,403);}
    }
    public static function aiSellerMessage($request): \WP_REST_Response {
        try{$d=self::body($request);$r=(new SalesAiSellerService())->message((string)($d['seller_token']??''),(string)($d['session_id']??''),(string)($d['content']??''));return new \WP_REST_Response(['ok'=>true]+$r,200,['Cache-Control'=>'no-store, private']);}catch(\Throwable $e){return self::aiSellerFailure($e);}
    }
    public static function aiSellerEnd($request): \WP_REST_Response {
        try{$d=self::body($request);$r=(new SalesAiSellerService())->end((string)($d['seller_token']??''),(string)($d['session_id']??''));return new \WP_REST_Response(['ok'=>true]+$r,200,['Cache-Control'=>'no-store, private']);}catch(\Throwable $e){return self::aiSellerFailure($e);}
    }
    public static function aiSellerVoiceTicket($request): \WP_REST_Response {
        try{$d=self::body($request);$r=(new SalesAiSellerService())->voiceTicket((string)($d['seller_token']??''),(string)($d['session_id']??''));return new \WP_REST_Response(['ok'=>true]+$r,200,['Cache-Control'=>'no-store, private']);}catch(\Throwable $e){return self::aiSellerFailure($e);}
    }
    public static function aiSellerSpeak($request): \WP_REST_Response {
        try{$d=self::body($request);$r=(new SalesAiSellerService())->speak((string)($d['seller_token']??''),(string)($d['session_id']??''),(int)($d['turn_index']??-1));return new \WP_REST_Response(['ok'=>true]+$r,200,['Cache-Control'=>'no-store, private']);}catch(\Throwable $e){return self::aiSellerFailure($e);}
    }
    public static function aiSellerState($request): \WP_REST_Response {
        try{$d=self::body($request);$r=(new SalesAiSellerService())->state((string)($d['seller_token']??''),(string)($d['session_id']??''),(int)($d['after']??0));return new \WP_REST_Response(['ok'=>true]+$r,200,['Cache-Control'=>'no-store, private']);}catch(\Throwable $e){return self::aiSellerFailure($e);}
    }
    public static function telegramWebhook($request): \WP_REST_Response {
        try{$hook=(string)(method_exists($request,'get_param')?$request->get_param('hook'):'');$d=self::body($request);$r=(new SalesTelegramService())->webhook($hook,$d);return new \WP_REST_Response(['ok'=>true]+$r,200,['Cache-Control'=>'no-store, private']);}catch(\Throwable $e){$status=$e instanceof \InvalidArgumentException?403:500;return new \WP_REST_Response(['ok'=>false,'code'=>'SALES_TELEGRAM_ERROR','message'=>$e->getMessage()!==''?$e->getMessage():'Ошибка Telegram-канала.'],$status,['Cache-Control'=>'no-store, private']);}
    }
    public static function maxWebhook($request): \WP_REST_Response {
        try{$hook=(string)(method_exists($request,'get_param')?$request->get_param('hook'):'');$d=self::body($request);$r=(new SalesMaxService())->webhook($hook,$d);return new \WP_REST_Response(['ok'=>true]+$r,200,['Cache-Control'=>'no-store, private']);}catch(\Throwable $e){$status=$e instanceof \InvalidArgumentException?403:500;return new \WP_REST_Response(['ok'=>false,'code'=>'SALES_MAX_ERROR','message'=>$e->getMessage()!==''?$e->getMessage():'Ошибка MAX-канала.'],$status,['Cache-Control'=>'no-store, private']);}
    }
    public static function whatsappWebhook($request): \WP_REST_Response {
        try{$hook=(string)(method_exists($request,'get_param')?$request->get_param('hook'):'');$svc=new SalesWhatsAppService();$method=method_exists($request,'get_method')?strtoupper((string)$request->get_method()):'POST';if($method==='GET'){$q=method_exists($request,'get_query_params')?(array)$request->get_query_params():[];$challenge=$svc->verifyChallenge($hook,$q);return new \WP_REST_Response(['verified'=>true],200,['X-CKM-WhatsApp-Challenge'=>$challenge,'Cache-Control'=>'no-store, private']);}$d=self::body($request);$raw=method_exists($request,'get_body')?(string)$request->get_body():wp_json_encode($d);$r=$svc->webhook($hook,$d,$raw);return new \WP_REST_Response(['ok'=>true]+$r,200,['Cache-Control'=>'no-store, private']);}catch(\Throwable $e){$status=$e instanceof \InvalidArgumentException?403:500;return new \WP_REST_Response(['ok'=>false,'code'=>'SALES_WHATSAPP_ERROR','message'=>$e->getMessage()!==''?$e->getMessage():'Ошибка WhatsApp-канала.'],$status,['Cache-Control'=>'no-store, private']);}
    }

    private static function phoneGatewaySecret($request): string {return method_exists($request,'get_header')?(string)$request->get_header('X-CKM-Phone-Gateway-Secret'):'';}
    public static function phoneGatewayHeartbeat($request): \WP_REST_Response {
        try{$d=self::body($request);$r=SalesPhoneGatewayService::heartbeat(self::phoneGatewaySecret($request),$d);return new \WP_REST_Response(['ok'=>true,'platform'=>$r],200,['Cache-Control'=>'no-store, private']);}catch(\Throwable $e){return new \WP_REST_Response(['ok'=>false,'code'=>'SALES_PHONE_GATEWAY_AUTH','message'=>$e->getMessage()],403,['Cache-Control'=>'no-store, private']);}
    }
    public static function phoneGatewayOpen($request): \WP_REST_Response {
        try{$d=self::body($request);$r=SalesPhoneGatewayService::open(self::phoneGatewaySecret($request),(string)($d['call_uuid']??''),(string)($d['did']??''),(string)($d['caller']??''));return new \WP_REST_Response(['ok'=>true]+$r,200,['Cache-Control'=>'no-store, private']);}catch(\Throwable $e){$status=$e instanceof \InvalidArgumentException?400:500;return new \WP_REST_Response(['ok'=>false,'code'=>'SALES_PHONE_GATEWAY_OPEN','message'=>$e->getMessage()],$status,['Cache-Control'=>'no-store, private']);}
    }
    public static function phoneGatewayTurn($request): \WP_REST_Response {
        try{$d=self::body($request);$r=SalesPhoneGatewayService::turn(self::phoneGatewaySecret($request),(string)($d['call_uuid']??''),(string)($d['text']??''));return new \WP_REST_Response(['ok'=>true]+$r,200,['Cache-Control'=>'no-store, private']);}catch(\Throwable $e){$status=$e instanceof \InvalidArgumentException?400:500;return new \WP_REST_Response(['ok'=>false,'code'=>'SALES_PHONE_GATEWAY_TURN','message'=>$e->getMessage()],$status,['Cache-Control'=>'no-store, private']);}
    }
    public static function phoneGatewaySpeak($request): \WP_REST_Response {
        try{$d=self::body($request);$r=SalesPhoneGatewayService::speak(self::phoneGatewaySecret($request),(string)($d['call_uuid']??''),(int)($d['turn_index']??-1));return new \WP_REST_Response(['ok'=>true]+$r,200,['Cache-Control'=>'no-store, private']);}catch(\Throwable $e){$status=$e instanceof \InvalidArgumentException?400:500;return new \WP_REST_Response(['ok'=>false,'code'=>'SALES_PHONE_GATEWAY_SPEAK','message'=>$e->getMessage()],$status,['Cache-Control'=>'no-store, private']);}
    }
    public static function phoneGatewayEvent($request): \WP_REST_Response {
        try{$d=self::body($request);$r=SalesPhoneGatewayService::event(self::phoneGatewaySecret($request),(string)($d['call_uuid']??''),(string)($d['status']??''),(string)($d['detail']??''));return new \WP_REST_Response(['ok'=>true]+$r,200,['Cache-Control'=>'no-store, private']);}catch(\Throwable $e){$status=$e instanceof \InvalidArgumentException?400:500;return new \WP_REST_Response(['ok'=>false,'code'=>'SALES_PHONE_GATEWAY_EVENT','message'=>$e->getMessage()],$status,['Cache-Control'=>'no-store, private']);}
    }
    public static function phoneGatewayCommands($request): \WP_REST_Response {
        try{$d=self::body($request);$r=SalesPhoneGatewayService::commands(self::phoneGatewaySecret($request),(string)($d['call_uuid']??''));return new \WP_REST_Response(['ok'=>true,'commands'=>$r],200,['Cache-Control'=>'no-store, private']);}catch(\Throwable $e){$status=$e instanceof \InvalidArgumentException?400:500;return new \WP_REST_Response(['ok'=>false,'code'=>'SALES_PHONE_GATEWAY_COMMANDS','message'=>$e->getMessage()],$status,['Cache-Control'=>'no-store, private']);}
    }

    private static function sidebandSecret($request): string {return method_exists($request,'get_header')?(string)$request->get_header('X-CKM-Sideband-Secret'):'';}
    public static function liveSidebandClaim($request): \WP_REST_Response {
        try{$d=self::body($request);$calls=SalesLiveSidebandService::claim(self::sidebandSecret($request),(int)($d['limit']??10));return new \WP_REST_Response(['ok'=>true,'calls'=>$calls],200,['Cache-Control'=>'no-store, private']);}catch(\Throwable $e){return new \WP_REST_Response(['ok'=>false,'code'=>'SALES_SIDEBAND_AUTH','message'=>$e->getMessage()],403,['Cache-Control'=>'no-store, private']);}
    }
    public static function liveSidebandEvent($request): \WP_REST_Response {
        try{$d=self::body($request);$r=SalesLiveSidebandService::ingest(self::sidebandSecret($request),(string)($d['session_id']??''),is_array($d['event']??null)?$d['event']:[]);return new \WP_REST_Response(['ok'=>true]+$r,200,['Cache-Control'=>'no-store, private']);}catch(\Throwable $e){$status=$e instanceof \InvalidArgumentException?403:500;return new \WP_REST_Response(['ok'=>false,'code'=>'SALES_SIDEBAND_ERROR','message'=>$e->getMessage()],$status,['Cache-Control'=>'no-store, private']);}
    }
    public static function liveSidebandHeartbeat($request): \WP_REST_Response {
        try{$d=self::body($request);$r=SalesLiveSidebandService::heartbeat(self::sidebandSecret($request),(string)($d['session_id']??''));return new \WP_REST_Response(['ok'=>true,'sideband'=>$r],200,['Cache-Control'=>'no-store, private']);}catch(\Throwable $e){return new \WP_REST_Response(['ok'=>false,'code'=>'SALES_SIDEBAND_AUTH','message'=>$e->getMessage()],403,['Cache-Control'=>'no-store, private']);}
    }

    public static function liveSipWebhook($request): \WP_REST_Response {
        try{$raw=method_exists($request,'get_body')?(string)$request->get_body():'';$headers=method_exists($request,'get_headers')?(array)$request->get_headers():[];$r=(new SalesLiveSipService())->handleIncomingWebhook($raw,$headers);return new \WP_REST_Response(['ok'=>true]+$r,200,['Cache-Control'=>'no-store, private']);}catch(\Throwable $e){$status=$e instanceof \InvalidArgumentException?403:500;return new \WP_REST_Response(['ok'=>false,'code'=>'SALES_LIVE_SIP_ERROR','message'=>$e->getMessage()!==''?$e->getMessage():'Ошибка Live SIP webhook.'],$status,['Cache-Control'=>'no-store, private']);}
    }

}
