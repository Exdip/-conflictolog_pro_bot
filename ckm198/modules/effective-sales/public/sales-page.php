<?php
namespace CKM\EffectiveSales;
if (!defined('ABSPATH')) { exit; }

use CKM\NegotiationMaster\Access;
use CKM\NegotiationMaster\ProductCatalog;
use CKM\NegotiationMaster\ScenarioRepository;

final class SalesPage {
    private const PAGE_OPTION='ckm_sales_page_id';
    private const VERSION_OPTION='ckm_sales_page_version';
    private const PAGE_VERSION='2';
    private const CREATE_ERROR_PREFIX='ckm_sales_create_error_';
    private const SCRIPT_NOTICE_PREFIX='ckm_sales_script_notice_';

    public static function register(): void { add_shortcode('ckm_effective_sales',[self::class,'render']); }
    public static function maybeInstall(): void { if(get_option(self::VERSION_OPTION,'')!==self::PAGE_VERSION)self::install(); }
    public static function install(): void {
        $id=(int)get_option(self::PAGE_OPTION,0);
        if($id>0&&get_post($id)){update_option(self::VERSION_OPTION,self::PAGE_VERSION,false);return;}
        $existing=get_page_by_path('ckm-sales-master');
        if($existing){update_option(self::PAGE_OPTION,(int)$existing->ID,false);update_option(self::VERSION_OPTION,self::PAGE_VERSION,false);return;}
        $id=wp_insert_post(['post_title'=>'Эффективный продажник','post_name'=>'ckm-sales-master','post_status'=>'publish','post_type'=>'page','post_content'=>'[ckm_effective_sales]']);
        if(!is_wp_error($id)&&(int)$id>0){update_option(self::PAGE_OPTION,(int)$id,false);update_option(self::VERSION_OPTION,self::PAGE_VERSION,false);}
    }

    public static function handleRequest(): void {
        if (!AppShell::active() || strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST') { return; }
        if(!empty($_POST['ckm_sales_script_action'])){ self::handleScriptRequest(); }
        if(!empty($_POST['ckm_sales_team_action'])){ self::handleTeamRequest(); }
        if(empty($_POST['ckm_sales_create_competition'])){ return; }
        $slug=sanitize_key((string)wp_unslash($_POST['scenario_slug']??''));
        $back=self::url(['sales_scenario'=>$slug,'sales_format'=>'competition']);
        $uid=get_current_user_id();
        $fail=static function(string $message) use ($uid,$back): void {
            if($uid>0)set_transient(self::CREATE_ERROR_PREFIX.$uid,$message,90);
            wp_safe_redirect($back); exit;
        };
        if(!SalesCompetitionService::canManage())$fail('Соревнование может создать только организатор.');
        $nonce=(string)wp_unslash($_POST['_ckm_sales_create_nonce']??'');
        if(!wp_verify_nonce($nonce,'ckm_sales_create_competition'))$fail('Проверка безопасности не пройдена. Обновите страницу и повторите попытку.');
        try{
            $count=max(2,min(6,(int)($_POST['team_count']??2)));
            $rawNames=is_array($_POST['team_names']??null)?array_values((array)$_POST['team_names']):[];
            $teams=[];
            for($i=0;$i<$count;$i++)$teams[]=['team_name'=>(string)wp_unslash($rawNames[$i]??'')];
            $competition=(new SalesCompetitionService())->create([
                'scenario_id'=>(int)($_POST['scenario_id']??0),
                'title'=>(string)wp_unslash($_POST['competition_title']??''),
                'difficulty'=>(string)wp_unslash($_POST['difficulty']??'medium'),
                'host_mode'=>(string)wp_unslash($_POST['host_mode']??'ai'),
                'teams'=>$teams,
            ]);
            wp_safe_redirect(self::url(['sales_view'=>'competitions','sales_competition'=>(int)$competition['id']])); exit;
        }catch(\Throwable $e){$fail($e->getMessage()!==''?$e->getMessage():'Не удалось создать соревнование.');}
    }

    private static function takeCreateError(): string {
        $uid=get_current_user_id(); if($uid<=0)return '';
        $key=self::CREATE_ERROR_PREFIX.$uid; $message=(string)get_transient($key); if($message!=='')delete_transient($key); return $message;
    }

    private static function setScriptNotice(string $message,bool $error=false): void {
        $uid=get_current_user_id();if($uid<1)return;
        set_transient(self::SCRIPT_NOTICE_PREFIX.$uid,['message'=>$message,'error'=>$error],90);
    }
    public static function takeScriptNotice(): array {
        $uid=get_current_user_id();if($uid<1)return [];
        $key=self::SCRIPT_NOTICE_PREFIX.$uid;$v=get_transient($key);if($v!==false)delete_transient($key);
        return is_array($v)?$v:[];
    }
    private static function downloadSellerAnalyticsCsv(string $scriptId): void {
        $script=SalesScriptService::find($scriptId);if(!$script)throw new \InvalidArgumentException('ИИ-продавец не найден.');
        $rows=SalesAiSellerWorkspaceService::analyticsRows($scriptId);$labels=SalesAiSellerWorkspaceService::outcomeLabels();$pipeline=SalesAiSellerWorkspaceService::pipelineStages();
        nocache_headers();header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="ckm-sales-analytics-'.sanitize_file_name($scriptId).'-'.gmdate('Ymd-His').'.csv"');
        $fh=fopen('php://output','wb');if($fh===false)throw new \RuntimeException('Не удалось сформировать отчёт.');fwrite($fh,"\xEF\xBB\xBF");
        fputcsv($fh,['Лид','Контакт','Стадия','Результат','Кто ведёт','Сообщений','Начат','Последняя активность'],';');
        foreach($rows as $row){if(!is_array($row))continue;$stage=(string)($row['pipeline_stage']??'new');$outcome=(string)($row['goal_status']??'active');fputcsv($fh,[(string)($row['lead_name']??'Новый лид'),(string)($row['lead_contact']??''),(string)($pipeline[$stage]??'Новый'),(string)($labels[$outcome]??'Активный'),((string)($row['control_mode']??'ai')==='human'?'Человек':'ИИ'),(string)count((array)($row['messages']??[])),(string)($row['started_at']??''),(string)($row['last_at']??'')],';');}
        fclose($fh);exit;
    }
    private static function handleTeamRequest(): void {
        $action=sanitize_key((string)wp_unslash($_POST['ckm_sales_team_action']??''));
        $back=self::url(['sales_view'=>'results']);
        $nonce=(string)wp_unslash($_POST['_ckm_sales_team_nonce']??'');
        if(!wp_verify_nonce($nonce,'ckm_sales_team_development')){
            self::setScriptNotice('Проверка безопасности не пройдена. Обновите страницу и повторите попытку.',true);
            wp_safe_redirect($back);exit;
        }
        try{
            if($action!=='assign_training')throw new \InvalidArgumentException('Неизвестное действие развития команды.');
            $result=SalesTeamDevelopmentService::assignTraining(
                sanitize_text_field((string)wp_unslash($_POST['participant_key']??'')),
                sanitize_key((string)wp_unslash($_POST['focus_code']??'')),
                (float)($_POST['weak_score']??0),
                sanitize_text_field((string)wp_unslash($_POST['script_id']??''))
            );
            $assignment=(array)($result['assignment']??[]);$target=(array)($result['target']??[]);
            $message=!empty($result['reused'])
                ? 'Подходящая тренировка уже назначена сотруднику.'
                : 'Тренировка назначена: '.(string)($target['focus_title']??'развитие навыка').' · уровень '.(int)($target['level']??1).'.';
            self::setScriptNotice($message);
        }catch(\Throwable $e){
            self::setScriptNotice($e->getMessage()!==''?$e->getMessage():'Не удалось назначить тренировку.',true);
        }
        wp_safe_redirect($back);exit;
    }

    private static function handleScriptRequest(): void {
        $action=sanitize_key((string)wp_unslash($_POST['ckm_sales_script_action']??''));
        $nonce=(string)wp_unslash($_POST['_ckm_sales_script_nonce']??'');
        $back=self::url(['sales_view'=>'scripts']);
        if(!wp_verify_nonce($nonce,'ckm_sales_script')){self::setScriptNotice('Проверка безопасности не пройдена. Обновите страницу и повторите попытку.',true);wp_safe_redirect($back);exit;}
        try{
            if($action==='create_adaptive_template'){
                $script=SalesScriptService::createAdaptiveSalesTemplate();
                self::setScriptNotice('Готовый адаптивный скрипт создан. Проверьте его, затем утвердите и подключите к ИИ-продавцу.');
                wp_safe_redirect(self::url(['sales_view'=>'scripts','sales_script'=>(string)$script['id']]));exit;
            }
            if($action==='create'){
                $script=SalesScriptService::create([
                    'title'=>(string)wp_unslash($_POST['script_title']??''),
                    'product'=>(string)wp_unslash($_POST['script_product']??''),
                    'client'=>(string)wp_unslash($_POST['script_client']??''),
                    'situation_class'=>(string)wp_unslash($_POST['script_class']??''),
                    'goal'=>(string)wp_unslash($_POST['script_goal']??''),
                    'constraints'=>(string)wp_unslash($_POST['script_constraints']??''),
                ]);
                self::setScriptNotice('Черновик скрипта создан.');
                wp_safe_redirect(self::url(['sales_view'=>'scripts','sales_script'=>(string)$script['id']]));exit;
            }
            $id=sanitize_text_field((string)wp_unslash($_POST['script_id']??''));
            $dialogId=sanitize_text_field((string)wp_unslash($_POST['dialog_id']??''));
            if($action==='adaptive_next_case'){
                $back=self::url(['sales_methodology'=>$id]);
                SalesAdaptivePolygonService::createNextCase($id);
                wp_safe_redirect($back);exit;
            }
            if($action==='practice_adopt_methodology'){
                $back=self::url(['sales_view'=>'scripts','sales_script'=>$id]);
                $result=SalesPracticeFeedbackService::adoptInsight($id,sanitize_key((string)wp_unslash($_POST['focus_code']??'')));
                self::setScriptNotice(!empty($result['reused'])?'Практическое правило в методике обновлено новыми данными.':'Повторяющийся сигнал из реальных продаж добавлен в методику как управленческое правило.');
                wp_safe_redirect($back);exit;
            }
            if($action==='practice_activate_rule'||$action==='practice_disable_rule'){
                $back=self::url(['sales_view'=>'scripts','sales_script'=>$id]);
                $activate=$action==='practice_activate_rule';
                $result=SalesPracticeFeedbackService::setRuleActive($id,sanitize_key((string)wp_unslash($_POST['focus_code']??'')),$activate);
                self::setScriptNotice($activate?'Правило включено в действующий стандарт продаж. Новая редакция требует повторной проверки команды.':'Правило отключено; сохранено в методике для последующего возврата.');
                wp_safe_redirect($back);exit;
            }
            if($action==='standard_recert_assign'){
                $back=self::url(['sales_view'=>'scripts','sales_script'=>$id]);
                $result=SalesStandardRecertificationService::assign($id,sanitize_text_field((string)wp_unslash($_POST['participant_key']??'')));
                self::setScriptNotice(!empty($result['reused'])?'Повторная проверка уже назначена сотруднику.':'Повторная проверка новой редакции стандарта назначена сотруднику.');
                wp_safe_redirect($back);exit;
            }
            if($action==='standard_recert_assign_all'){
                $back=self::url(['sales_view'=>'scripts','sales_script'=>$id]);
                $result=SalesStandardRecertificationService::assignAll($id);
                self::setScriptNotice('Повторная проверка стандарта v'.(int)$result['revision'].' назначена: новых '.(int)$result['created'].', уже существовало '.(int)$result['reused'].'.');
                wp_safe_redirect($back);exit;
            }
            if($action==='standard_revision_confirm'||$action==='standard_revision_rework'){
                $back=self::url(['sales_view'=>'scripts','sales_script'=>$id]);
                $decision=$action==='standard_revision_confirm'?'confirm':'rework';
                $result=SalesStandardRevisionDecisionService::decide($id,$decision,max(0,(int)($_POST['standard_revision']??0)));
                self::setScriptNotice($decision==='confirm'?'Редакция стандарта закреплена по подтверждённому эффекту.':'Правило отправлено на доработку и исключено из действующего стандарта.');
                wp_safe_redirect($back);exit;
            }
            if(str_starts_with($action,'seller_')){$backArgs=['sales_view'=>'ai-sellers','sales_agent'=>$id];if($dialogId!=='')$backArgs['sales_dialog']=$dialogId;$back=self::url($backArgs);} 
            if(str_starts_with($action,'integration_'))$back=self::url(['sales_view'=>'integrations']);
            if($action==='integration_telegram_connect'){
                $status=(new SalesTelegramService())->connectPartnerIntegration((string)wp_unslash($_POST['integration_label']??'Telegram'),(string)wp_unslash($_POST['telegram_bot_token']??''));$user=(string)($status['account_username']??'');self::setScriptNotice('Telegram партнёра подключён'.($user!==''?' — @'.ltrim($user,'@'):'').'. Теперь назначьте его нужному ИИ-продавцу.');wp_safe_redirect($back);exit;
            }
            if($action==='integration_telegram_test'){
                (new SalesTelegramService())->testPartnerIntegration((string)wp_unslash($_POST['integration_id']??''));self::setScriptNotice('Telegram-подключение проверено: Bot API отвечает.');wp_safe_redirect($back);exit;
            }
            if($action==='integration_max_connect'){
                $status=(new SalesMaxService())->connectPartnerIntegration((string)wp_unslash($_POST['integration_label']??'MAX'),(string)wp_unslash($_POST['max_api_token']??''));$user=(string)($status['account_username']??'');self::setScriptNotice('MAX партнёра подключён'.($user!==''?' — @'.ltrim($user,'@'):'').'. Теперь назначьте его нужному ИИ-продавцу.');wp_safe_redirect($back);exit;
            }
            if($action==='integration_max_test'){
                (new SalesMaxService())->testPartnerIntegration((string)wp_unslash($_POST['integration_id']??''));self::setScriptNotice('MAX-подключение проверено: Bot API и webhook отвечают.');wp_safe_redirect($back);exit;
            }
            if($action==='integration_delete'){
                $integrationId=(string)wp_unslash($_POST['integration_id']??'');$row=SalesPartnerIntegrationService::findCurrentTenant($integrationId);if(!$row)throw new \InvalidArgumentException('Подключение не найдено.');$provider=(string)$row['provider'];if($provider==='telegram')(new SalesTelegramService())->disconnectPartnerIntegration($integrationId);elseif($provider==='max')(new SalesMaxService())->disconnectPartnerIntegration($integrationId);elseif($provider==='whatsapp')(new SalesWhatsAppService())->disconnectPartnerIntegration($integrationId);elseif($provider==='sip')(new SalesTelephonyService())->disconnectPartnerIntegration($integrationId);else SalesPartnerIntegrationService::deleteCurrentTenant($integrationId);self::setScriptNotice('Подключение удалено. Секреты больше не хранятся на площадке.');wp_safe_redirect($back);exit;
            }
            if($action==='integration_save_max'){
                $status=(new SalesMaxService())->connectPartnerIntegration((string)wp_unslash($_POST['integration_label']??'MAX'),(string)wp_unslash($_POST['max_api_token']??''));$user=(string)($status['account_username']??'');self::setScriptNotice('MAX партнёра подключён'.($user!==''?' — @'.ltrim($user,'@'):'').'.');wp_safe_redirect($back);exit;
            }
            if($action==='integration_save_whatsapp'||$action==='integration_whatsapp_connect'){
                $status=(new SalesWhatsAppService())->connectPartnerIntegration((string)wp_unslash($_POST['integration_label']??'WhatsApp'),(string)wp_unslash($_POST['wa_phone_number_id']??''),(string)wp_unslash($_POST['wa_business_account_id']??''),(string)wp_unslash($_POST['wa_access_token']??''),(string)wp_unslash($_POST['wa_app_secret']??''));$phone=(string)($status['account_username']??'');self::setScriptNotice('WhatsApp партнёра подключён'.($phone!==''?' — '.$phone:'').'. Теперь назначьте его нужному ИИ-продавцу.');wp_safe_redirect($back);exit;
            }
            if($action==='integration_whatsapp_test'){
                (new SalesWhatsAppService())->testPartnerIntegration((string)wp_unslash($_POST['integration_id']??''));self::setScriptNotice('WhatsApp-подключение проверено: Cloud API и WABA webhook отвечают.');wp_safe_redirect($back);exit;
            }
            if($action==='integration_phone_gateway_save'){SalesPhoneGatewayService::savePlatformSettings((string)wp_unslash($_POST['phone_gateway_secret']??''));self::setScriptNotice('Телефонный медиашлюз CKM сохранён. Голос остаётся Сергей через существующий Cartesia Gateway.');wp_safe_redirect($back);exit;}
            if($action==='integration_phone_gateway_test'){SalesPhoneGatewayService::testPlatform();self::setScriptNotice('Сергей подтверждён через CKM Voice Gateway. Для полного телефонного теста должен быть онлайн phone media worker.');wp_safe_redirect($back);exit;}
            if($action==='integration_telephony_connect'||$action==='integration_save_sip'){
                $adapter=(string)wp_unslash($_POST['telephony_adapter']??'custom');$input=[];
                if($adapter==='mango'){$input=['api_key'=>(string)wp_unslash($_POST['mango_api_key']??''),'api_salt'=>(string)wp_unslash($_POST['mango_api_salt']??''),'extension'=>(string)wp_unslash($_POST['mango_extension']??''),'caller_id'=>(string)wp_unslash($_POST['mango_caller_id']??'')];}
                elseif($adapter==='uis'){$input=['login'=>(string)wp_unslash($_POST['uis_login']??''),'password'=>(string)wp_unslash($_POST['uis_password']??''),'virtual_number'=>(string)wp_unslash($_POST['uis_virtual_number']??'')];}
                elseif($adapter==='direct_sip'){$input=['provider_name'=>(string)wp_unslash($_POST['direct_sip_provider_name']??'Direct SIP'),'provider_url'=>(string)wp_unslash($_POST['direct_sip_provider_url']??''),'username'=>(string)wp_unslash($_POST['direct_sip_username']??''),'password'=>(string)wp_unslash($_POST['direct_sip_password']??''),'caller_id'=>(string)wp_unslash($_POST['direct_sip_caller_id']??''),'inbound_did'=>(string)wp_unslash($_POST['direct_sip_inbound_did']??''),'handoff_target_uri'=>(string)wp_unslash($_POST['direct_sip_handoff_target']??'')];}
                else{$input=['server'=>(string)wp_unslash($_POST['sip_server']??''),'login'=>(string)wp_unslash($_POST['sip_login']??''),'password'=>(string)wp_unslash($_POST['sip_password']??''),'caller_id'=>(string)wp_unslash($_POST['sip_caller_id']??''),'provider_name'=>(string)wp_unslash($_POST['sip_provider']??'Другой провайдер')];}
                $status=(new SalesTelephonyService())->connectPartnerIntegration((string)wp_unslash($_POST['integration_label']??'Телефония продаж'),$adapter,$input);$providerName=(string)($status['account_name']??'Телефония');self::setScriptNotice($adapter==='direct_sip'?($providerName.' сохранён. Телефонный аудиопоток будет идти через CKM Media Gateway, а ответы озвучит Сергей. Нажмите «Проверить» после запуска phone media worker.'):($providerName.' подключён к площадке. API управления звонками проверен; для голоса Сергея назначьте отдельный SIP trunk, если оператор его выдаёт.'));wp_safe_redirect($back);exit;
            }
            if($action==='integration_telephony_test'){
                $result=(new SalesTelephonyService())->testPartnerIntegration((string)wp_unslash($_POST['integration_id']??''));self::setScriptNotice((string)($result['adapter']??'')==='direct_sip'?(string)($result['message']??'Direct SIP проверен.') : (!empty($result['remote_verified'])?'Телефония проверена: API провайдера отвечает. Для голоса Сергея нужен отдельный SIP trunk и CKM Media Gateway.':'Пользовательский телефонный адаптер сохранён; удалённая проверка появится после настройки конкретного API.'));wp_safe_redirect($back);exit;
            }
            if($action==='seller_integration_bind'){
                $integrationId=(string)wp_unslash($_POST['integration_id']??'');$bound=SalesPartnerIntegrationService::bind($integrationId,$id);self::setScriptNotice((string)($bound['provider_label']??'Канал').' назначен этому ИИ-продавцу.');wp_safe_redirect($back);exit;
            }
            if($action==='seller_integration_unbind'){
                $integrationId=(string)wp_unslash($_POST['integration_id']??'');SalesPartnerIntegrationService::unbind($integrationId);self::setScriptNotice('Канал отвязан от ИИ-продавца. Само подключение партнёра сохранено.');wp_safe_redirect($back);exit;
            }
            if($action==='seller_profile'){
                SalesAiSellerWorkspaceService::saveProfile($id,[
                    'name'=>(string)wp_unslash($_POST['seller_name']??''),
                    'tone'=>(string)wp_unslash($_POST['seller_tone']??''),
                    'handoff'=>(string)wp_unslash($_POST['seller_handoff']??''),
                    'primary_goal'=>(string)wp_unslash($_POST['seller_primary_goal']??''),
                ]);self::setScriptNotice('Профиль ИИ-продавца сохранён.');wp_safe_redirect($back);exit;
            }
            if($action==='seller_add_url'){
                SalesAiSellerWorkspaceService::addUrlKnowledge($id,(string)wp_unslash($_POST['knowledge_url']??''));self::setScriptNotice('Страница добавлена в базу знаний.');wp_safe_redirect($back);exit;
            }
            if($action==='seller_add_text'){
                SalesAiSellerWorkspaceService::addTextKnowledge($id,(string)wp_unslash($_POST['knowledge_title']??''),(string)wp_unslash($_POST['knowledge_text']??''));self::setScriptNotice('Материал добавлен в базу знаний.');wp_safe_redirect($back);exit;
            }
            if($action==='seller_delete_kb'){
                SalesAiSellerWorkspaceService::removeKnowledge($id,(string)wp_unslash($_POST['source_id']??''));self::setScriptNotice('Источник удалён из базы знаний.');wp_safe_redirect($back);exit;
            }
            if($action==='seller_add_correction'){
                SalesAiSellerWorkspaceService::addCorrection($id,(string)wp_unslash($_POST['correction_text']??''));self::setScriptNotice('Корректировка поведения сохранена.');wp_safe_redirect($back);exit;
            }
            if($action==='seller_delete_correction'){
                SalesAiSellerWorkspaceService::removeCorrection($id,(string)wp_unslash($_POST['correction_id']??''));self::setScriptNotice('Корректировка удалена.');wp_safe_redirect($back);exit;
            }
            if($action==='seller_telegram_connect'){
                $status=(new SalesTelegramService())->connect($id,(string)wp_unslash($_POST['telegram_bot_token']??''));$user=(string)($status['bot_username']??'');self::setScriptNotice('Telegram подключён'.($user!==''?' — @'.ltrim($user,'@'):'').'. Новые личные сообщения будут обрабатываться этим ИИ-продавцом.');wp_safe_redirect($back);exit;
            }
            if($action==='seller_telegram_disconnect'){
                (new SalesTelegramService())->disconnect($id);self::setScriptNotice('Telegram-канал отключён. Web-чат и история лидов сохранены.');wp_safe_redirect($back);exit;
            }
            if($action==='seller_lead_update'){
                $stage=sanitize_key((string)wp_unslash($_POST['pipeline_stage']??'new'));SalesAiSellerWorkspaceService::updateLead($id,$dialogId,(string)wp_unslash($_POST['lead_name']??''),(string)wp_unslash($_POST['lead_contact']??''),$stage);SalesAiSellerService::syncPipelineStageForAdmin($dialogId,$id,$stage);self::setScriptNotice('Карточка лида обновлена.');wp_safe_redirect($back);exit;
            }
            if($action==='seller_practice_case'){
                $participantKey=sanitize_text_field((string)wp_unslash($_POST['participant_key']??''));
                $made=SalesPracticeFeedbackService::createAndAssign($id,$dialogId,$participantKey);
                $case=(array)($made['case']??[]);$assignment=(array)($made['assignment']??[]);
                $message=!empty($made['case_reused'])?'Практический кейс уже существовал.':'Из реальной ситуации создан обезличенный тренировочный кейс.';
                if($participantKey!=='')$message.=' Тренировка '.(!empty($assignment['reused'])?'уже была назначена сотруднику.':'назначена сотруднику.');
                self::setScriptNotice($message);wp_safe_redirect($back);exit;
            }
            if($action==='seller_scenario_add'){
                SalesAiSellerWorkspaceService::addScenario($id,[
                    'name'=>(string)wp_unslash($_POST['scenario_name']??''),'trigger'=>(string)wp_unslash($_POST['scenario_trigger']??'idle'),'trigger_stage'=>(string)wp_unslash($_POST['scenario_trigger_stage']??'new'),'delay_minutes'=>(int)($_POST['scenario_delay_minutes']??0),'action'=>(string)wp_unslash($_POST['scenario_action']??'message'),'message'=>(string)wp_unslash($_POST['scenario_message']??''),'target_stage'=>(string)wp_unslash($_POST['scenario_target_stage']??'qualified'),
                ]);self::setScriptNotice('Сценарий автоматизации добавлен. Первый шаг готов.');wp_safe_redirect($back);exit;
            }
            if($action==='seller_scenario_step_add'){
                SalesAiSellerWorkspaceService::addScenarioStep($id,(string)wp_unslash($_POST['scenario_id']??''),[
                    'delay_minutes'=>(int)($_POST['scenario_step_delay_minutes']??0),'action'=>(string)wp_unslash($_POST['scenario_step_action']??'message'),'message'=>(string)wp_unslash($_POST['scenario_step_message']??''),'target_stage'=>(string)wp_unslash($_POST['scenario_step_target_stage']??'qualified'),
                ]);self::setScriptNotice('Следующий шаг добавлен в цепочку.');wp_safe_redirect($back);exit;
            }
            if($action==='seller_scenario_step_delete'){SalesAiSellerWorkspaceService::deleteScenarioStep($id,(string)wp_unslash($_POST['scenario_id']??''),(string)wp_unslash($_POST['scenario_step_id']??''));self::setScriptNotice('Шаг удалён из цепочки.');wp_safe_redirect($back);exit;}
            if($action==='seller_scenario_delete'){SalesAiSellerWorkspaceService::deleteScenario($id,(string)wp_unslash($_POST['scenario_id']??''));self::setScriptNotice('Сценарий удалён.');wp_safe_redirect($back);exit;}
            if($action==='seller_scenario_toggle'){SalesAiSellerWorkspaceService::toggleScenario($id,(string)wp_unslash($_POST['scenario_id']??''));self::setScriptNotice('Статус сценария изменён.');wp_safe_redirect($back);exit;}
            if($action==='seller_scenario_run'){
                $script=SalesScriptService::find($id);if(!$script||empty($script['ai_seller_enabled'])||empty($script['ai_seller_token']))throw new \RuntimeException('ИИ-продавец должен быть подключён.');if($dialogId==='')throw new \InvalidArgumentException('Сначала выберите активный диалог.');$run=(new SalesAiSellerService())->runScenarioNow((string)$script['ai_seller_token'],$dialogId,(string)wp_unslash($_POST['scenario_id']??''));self::setScriptNotice('Выполнен шаг '.(int)($run['step_number']??1).' выбранного сценария.');wp_safe_redirect($back);exit;
            }
            if($action==='seller_export_analytics'){self::downloadSellerAnalyticsCsv($id);}
            if($action==='seller_phone_refer'){SalesPhoneGatewayService::transferCurrentUser($id,$dialogId);self::setScriptNotice('Команда перевода звонка живому оператору поставлена в очередь медиашлюза.');wp_safe_redirect($back);exit;}
            if($action==='seller_phone_hangup'){SalesPhoneGatewayService::hangupCurrentUser($id,$dialogId);self::setScriptNotice('Команда завершения телефонного звонка поставлена в очередь медиашлюза.');wp_safe_redirect($back);exit;}
            if($action==='seller_takeover'||$action==='seller_return_ai'||$action==='seller_operator_message'){
                $script=SalesScriptService::find($id);if(!$script||empty($script['ai_seller_enabled'])||empty($script['ai_seller_token']))throw new \RuntimeException('ИИ-продавец должен быть подключён.');$svc=new SalesAiSellerService();$token=(string)$script['ai_seller_token'];
                if($action==='seller_takeover'){$svc->operatorControl($token,$dialogId,'human');self::setScriptNotice('Разговор передан человеку. ИИ больше не отвечает клиенту автоматически.');}
                elseif($action==='seller_return_ai'){$svc->operatorControl($token,$dialogId,'ai');self::setScriptNotice('Управление возвращено ИИ-продавцу.');}
                else{$svc->operatorMessage($token,$dialogId,(string)wp_unslash($_POST['operator_message']??''));self::setScriptNotice('Сообщение специалиста отправлено клиенту.');}
                wp_safe_redirect($back);exit;
            }
            if($action==='generate_client'||$action==='regenerate_client'){
                $script=(new SalesScriptClientService())->generate($id);
                $source=(string)($script['ai_client_generation_source']??'fallback');
                self::setScriptNotice($source==='ai'?'Собственный ИИ-клиент сгенерирован по вашему скрипту.':'ИИ-клиент создан по безопасному резервному шаблону: генеративный ИИ сейчас недоступен.');
                wp_safe_redirect(self::url(['sales_view'=>'scripts','sales_script'=>$id]));exit;
            }
            if($action==='connect_seller'){
                SalesScriptService::connectAiSeller($id);self::setScriptNotice('ИИ-продавец подключён. Публичная ссылка готова для разговора с реальным клиентом.');
                wp_safe_redirect(self::url(['sales_view'=>'scripts','sales_script'=>$id]));exit;
            }
            if($action==='disconnect_seller'){
                SalesScriptService::disconnectAiSeller($id);self::setScriptNotice('ИИ-продавец отключён. Старая публичная ссылка больше не работает.');
                wp_safe_redirect(self::url(['sales_view'=>'scripts','sales_script'=>$id]));exit;
            }
            if($action==='approve'){
                SalesScriptService::approve($id);self::setScriptNotice('Скрипт утверждён.');
                wp_safe_redirect(self::url(['sales_view'=>'scripts','sales_script'=>$id]));exit;
            }
            if($action==='delete'){
                SalesScriptService::remove($id);self::setScriptNotice('Скрипт удалён.');wp_safe_redirect($back);exit;
            }
            throw new \InvalidArgumentException('Неизвестное действие со скриптом.');
        }catch(\Throwable $e){self::setScriptNotice($e->getMessage()!==''?$e->getMessage():'Не удалось сохранить скрипт.',true);wp_safe_redirect($back);exit;}
    }

    public static function url(array $args=[]): string {
        $id=(int)get_option(self::PAGE_OPTION,0);
        $base=$id>0&&get_post($id)?(string)get_permalink($id):home_url('/ckm-sales-master/');
        if(function_exists('ckmqp_tenant_link'))$base=ckmqp_tenant_link($base,ckmqp_scope_id());
        return $args?add_query_arg($args,$base):$base;
    }

    private static function accessGate(): ?string {
        if(!is_user_logged_in()){
            $u=function_exists('wp_login_url')?wp_login_url(self::url()):'#';
            return '<div class="ckm-sales-card ckm-sales-gate"><h2>Эффективный продажник</h2><p>Для тренировки или соревнования необходимо войти.</p><a class="ckm-sales-btn ckm-sales-primary" href="'.esc_url($u).'">Войти</a></div>';
        }
        if(current_user_can('manage_options'))return null;
        if(function_exists('ckm_quiz_pro_can_organize')&&ckm_quiz_pro_can_organize()&&function_exists('ckm_quiz_pro_can_access_format')){
            $uid=ckm_quiz_pro_effective_organizer_user_id();
            if(!ckm_quiz_pro_can_access_format($uid,'negotiation_duel_v1')){
                $buy=ckm_quiz_pro_organizer_url(['view'=>'payment','games'=>'negotiation_duel_v1']);
                return '<div class="ckm-sales-card ckm-sales-gate"><div class="ckm-sales-kicker">Эффективный продажник</div><h2>Нужен доступ к деловым переговорам</h2><p>Тренажёр использует существующий доступ к переговорным играм ЦКМ.</p><a class="ckm-sales-btn ckm-sales-primary" href="'.esc_url($buy).'">Открыть доступ</a></div>';
            }
        }
        return null;
    }

    private static function library(): array {
        $library=ProductCatalog::libraryBySlug(SalesDomain::LIBRARY_SLUG);
        if(!$library)throw new \RuntimeException('Библиотека тренажёра продаж ещё не установлена.');
        return $library;
    }

    private static function cardMeta(array $card): array {
        $meta=is_array($card['meta']??null)?$card['meta']:[];
        return [
            'category'=>(string)($meta['category']??'Продажи'),
            'description'=>(string)($meta['description']??''),
            'difficulty'=>(string)($meta['difficulty']??'Средний'),
            'duration'=>(string)($meta['duration']??'7–10 мин'),
            'stage'=>(string)($meta['stage']??''),
            'skill'=>(string)($meta['skill']??''),
        ];
    }

    private static function classificationOrder(): array {
        return ['Первичный контакт','Диагностика','Ценность','Цена','Отсрочка','Конкурент','Сложное решение','Риск и недоверие','Особые условия','Продвижение сделки','Сложный клиент','Экспертная продажа'];
    }

    private static function sortSalesCards(array $cards): array {
        $order=array_flip(self::classificationOrder());
        usort($cards,static function(array $a,array $b) use($order): int {
            $am=self::cardMeta($a);$bm=self::cardMeta($b);
            $ai=$order[$am['category']]??999;$bi=$order[$bm['category']]??999;
            return $ai<=>$bi ?: strcmp((string)($a['title']??''),(string)($b['title']??''));
        });
        return $cards;
    }

    private static function scenarioCountLabel(int $count): string {
        $n=abs($count)%100;$n1=$n%10;
        $word=($n>10&&$n<20)?'сценариев':($n1===1?'сценарий':($n1>=2&&$n1<=4?'сценария':'сценариев'));
        return $count.' '.$word;
    }

    private static function renderHome(array $library): string {
        $cards=self::sortSalesCards(ProductCatalog::libraryCards((int)$library['id']));
        $classes=self::classificationOrder();
        ob_start(); ?>
        <section class="ckm-sales-hero ckm-sales-card">
            <div class="ckm-sales-kicker">ТРЕНАЖЁР ПРОДАЖ</div>
            <h1>Эффективный продажник</h1>
            <p class="ckm-sales-lead">Поймите клиента. Найдите настоящую потребность. Покажите ценность. Продвиньте сделку.</p>
            <div class="ckm-sales-flow"><span>1. Разобраться</span><b>→</b><span>2. Показать ценность</span><b>→</b><span>3. Снять сомнение</span><b>→</b><span>4. Договориться о следующем шаге</span></div>
        </section>
        <section class="ckm-sales-section">
            <div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">ДВА ОСНОВНЫХ ФОРМАТА</div><h2>Выберите формат работы</h2></div></div>
            <div class="ckm-sales-mode-grid ckm-sales-mode-grid-two">
                <article class="ckm-sales-card ckm-sales-mode is-main"><span>🎯</span><h3>Тренировка</h3><p>Один участник или одна команда работает с ИИ-клиентом. По ходу разговора можно запросить подсказку ИИ-тренера.</p><small>ИИ-клиент + ИИ-тренер + итоговый разбор</small></article>
                <article class="ckm-sales-card ckm-sales-mode ckm-sales-mode-competition"><span>🏆</span><h3>Соревнование</h3><p>2–6 команд получают одинаковый сценарий и независимо работают со своими экземплярами ИИ-клиента.</p><small>Подсказки отключены · общий рейтинг после завершения всех команд</small></article>
            </div>
        </section>
        <section class="ckm-sales-section">
            <div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">КЛАССИФИКАЦИЯ СИТУАЦИЙ</div><h2>12 типов реальных продаж</h2></div><span class="ckm-sales-count"><?php echo esc_html(self::scenarioCountLabel(count($cards))); ?></span></div>
            <div class="ckm-sales-taxonomy" id="ckm-sales-taxonomy"><button type="button" class="is-active" data-sales-class-filter="">Все</button><?php foreach($classes as $i=>$class): ?><button type="button" data-sales-class-filter="<?php echo esc_attr($class); ?>"><b><?php echo (int)($i+1); ?></b><?php echo esc_html($class); ?></button><?php endforeach; ?></div>
        </section>
        <section class="ckm-sales-section">
            <div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">СЦЕНАРИИ</div><h2>Выберите ситуацию</h2></div></div>
            <div class="ckm-sales-scenario-grid" id="ckm-sales-scenario-grid">
            <?php foreach($cards as $card):$m=self::cardMeta($card); ?>
                <article class="ckm-sales-card ckm-sales-scenario-card" data-sales-class="<?php echo esc_attr($m['category']); ?>">
                    <div class="ckm-sales-card-top"><span class="ckm-sales-pill"><?php echo esc_html($m['category']); ?></span><span class="ckm-sales-status">Готов</span></div>
                    <h3><?php echo esc_html((string)$card['title']); ?></h3>
                    <p><?php echo esc_html($m['description']); ?></p>
                    <div class="ckm-sales-classification">
                        <?php if($m['stage']!==''): ?><span><small>Этап</small><strong><?php echo esc_html($m['stage']); ?></strong></span><?php endif; ?>
                        <?php if($m['skill']!==''): ?><span><small>Главный навык</small><strong><?php echo esc_html($m['skill']); ?></strong></span><?php endif; ?>
                    </div>
                    <div class="ckm-sales-meta"><span><?php echo esc_html($m['difficulty']); ?></span><span><?php echo esc_html($m['duration']); ?></span><span>ИИ-клиент</span></div>
                    <div class="ckm-sales-card-actions ckm-sales-card-actions-split">
                        <a class="ckm-sales-btn ckm-sales-primary" href="<?php echo esc_url(self::url(['sales_scenario'=>(string)$card['slug'],'sales_format'=>'training'])); ?>">Тренировка</a>
                        <a class="ckm-sales-btn" href="<?php echo esc_url(self::url(['sales_scenario'=>(string)$card['slug'],'sales_format'=>'competition'])); ?>">Соревнование</a>
                    </div>
                </article>
            <?php endforeach; ?>
            </div>
        </section>
        <?php return (string)ob_get_clean();
    }

    private static function scenario(string $slug,array $library): array {
        $scenario=(new ScenarioRepository())->findPublishedSystemBySlug($slug);
        if(!$scenario||(int)($scenario['library_id']??0)!==(int)$library['id'])throw new \InvalidArgumentException('Сценарий продаж недоступен.');
        $version=SalesDomain::assertVersion(Access::version((int)$scenario['current_version_id']));
        return [$scenario,$version];
    }

    private static function customScenario(int $scenarioId): array {
        if($scenarioId<1)throw new \InvalidArgumentException('Собственный ИИ-клиент не выбран.');
        $scenario=Access::scenario($scenarioId);
        if($scenario['tenant_id']===null)throw new \InvalidArgumentException('Собственный ИИ-клиент недоступен.');
        $version=SalesDomain::versionForScenario($scenarioId);
        $mechanics=SalesDomain::mechanics($version);
        if(empty($mechanics['script_generated']))throw new \InvalidArgumentException('Сценарий не создан из скрипта продаж.');
        return [$scenario,$version];
    }

    private static function scenarioArgs(array $scenario,array $extra=[]): array {
        $base=$scenario['tenant_id']===null?['sales_scenario'=>(string)$scenario['slug']]:['sales_custom_scenario'=>(int)$scenario['id']];
        return $base+$extra;
    }

    private static function renderBrief(array $scenario,array $version,string $notice='',bool $restart=false): string {
        $known=SalesDomain::decode((string)($version['player_known_facts_json']??''));
        $mechanics=SalesDomain::publicMechanics($version);
        $meta=self::cardMeta(['slug'=>(string)$scenario['slug'],'meta'=>ProductCatalog::meta((string)$scenario['slug'])]);
        if($scenario['tenant_id']!==null){$meta['category']=(string)($mechanics['situation_class']??'Свой скрипт');$meta['stage']=(string)($mechanics['deal_stage']??'');$meta['skill']=(string)($mechanics['primary_skill']??'');}
        ob_start(); ?>
        <div class="ckm-sales-brief-grid">
            <section class="ckm-sales-card ckm-sales-brief-main">
                <a class="ckm-sales-back" href="<?php echo esc_url($scenario['tenant_id']!==null?self::url(['sales_view'=>'scripts','sales_script'=>(string)(SalesDomain::mechanics($version)['script_id']??'')]):self::url()); ?>">← <?php echo $scenario['tenant_id']!==null?'К скрипту':'Все сценарии'; ?></a>
                <div class="ckm-sales-kicker">ТРЕНИРОВКА</div>
                <h1><?php echo esc_html((string)$scenario['title']); ?></h1>
                <div class="ckm-sales-brief-tags"><span><?php echo esc_html($meta['category']); ?></span><?php if($meta['stage']!==''): ?><span><?php echo esc_html($meta['stage']); ?></span><?php endif; ?></div>
                <?php if($meta['skill']!==''): ?><p class="ckm-sales-primary-skill"><strong>Главный навык:</strong> <?php echo esc_html($meta['skill']); ?></p><?php endif; ?>
                <p class="ckm-sales-lead"><?php echo esc_html((string)$version['player_situation']); ?></p>
                <div class="ckm-sales-brief-task"><strong>Ваша задача</strong><p><?php echo esc_html((string)$version['player_task']); ?></p></div>
                <div class="ckm-sales-format-note"><strong>Подсказки включены</strong><span>Во время тренировки доступна кнопка «Нужна подсказка».</span></div>
                <?php if($known): ?><div class="ckm-sales-known"><h3>Что вам известно</h3><?php foreach($known as $fact):if(!is_array($fact))continue; ?><div><strong><?php echo esc_html((string)($fact['title']??'')); ?></strong><span><?php echo esc_html((string)($fact['content']??'')); ?></span></div><?php endforeach; ?></div><?php endif; ?>
            </section>
            <aside class="ckm-sales-card ckm-sales-client-preview">
                <?php if($notice!==''): ?><div class="ckm-sales-inline-status ckm-sales-error"><?php echo esc_html($notice); ?></div><?php endif; ?>
                <div class="ckm-sales-kicker">ПЕРВАЯ РЕПЛИКА КЛИЕНТА</div>
                <div class="ckm-sales-avatar">АМ</div>
                <h3><?php echo esc_html((string)$version['opponent_name']); ?></h3>
                <p class="ckm-sales-muted"><?php echo esc_html((string)$version['opponent_role']); ?></p>
                <blockquote><?php echo esc_html((string)($mechanics['opening_message']??'')); ?></blockquote>
                <label>Сложность<select id="ckm-sales-difficulty" class="ckm-sales-select"><option value="soft">Мягкий</option><option value="medium" selected>Средний</option><option value="hard">Жёсткий</option><option value="expert">Эксперт</option></select></label>
                <button class="ckm-sales-btn ckm-sales-primary ckm-sales-btn-wide" id="ckm-sales-start" data-scenario-id="<?php echo (int)$scenario['id']; ?>" data-scenario-slug="<?php echo esc_attr((string)$scenario['slug']); ?>" data-custom-scenario="<?php echo $scenario['tenant_id']!==null?'1':'0'; ?>" data-restart="<?php echo $restart?'1':'0'; ?>"><?php echo $restart?'Начать заново':'Начать разговор'; ?></button>
                <div class="ckm-sales-inline-status" id="ckm-sales-start-status" aria-live="polite"></div>
            </aside>
        </div>
        <?php return (string)ob_get_clean();
    }

    private static function renderCompetitionCreate(array $scenario,array $version): string {
        if(!SalesCompetitionService::canManage()){
            return '<section class="ckm-sales-card ckm-sales-gate"><div class="ckm-sales-kicker">СОРЕВНОВАНИЕ</div><h2>Соревнование создаёт организатор</h2><p>Для входа в соревнование используйте персональную ссылку своей команды, которую выдаёт организатор.</p></section>';
        }
        ob_start(); ?>
        <section class="ckm-sales-card ckm-sales-competition-create">
            <a class="ckm-sales-back" href="<?php echo esc_url(self::url()); ?>">← Все сценарии</a>
            <div class="ckm-sales-kicker">СОРЕВНОВАНИЕ</div>
            <h1><?php echo esc_html((string)$scenario['title']); ?></h1>
            <p class="ckm-sales-lead">2–6 команд проходят один и тот же сценарий независимо. Подсказки ИИ-тренера отключены. Общий рейтинг открывается после завершения всех команд.</p>
            <?php if($createError=self::takeCreateError()): ?><div class="ckm-sales-inline-status ckm-sales-error" aria-live="polite"><?php echo esc_html($createError); ?></div><?php endif; ?>
            <div class="ckm-sales-competition-rules"><span>Одинаковый сценарий</span><span>Одинаковая сложность</span><span>Без подсказок</span><span>Оценка по 100-балльной шкале</span></div>
            <form id="ckm-sales-competition-create" class="ckm-sales-competition-form" method="post" data-native-submit="1" data-scenario-id="<?php echo (int)$scenario['id']; ?>">
                <input type="hidden" name="ckm_sales_create_competition" value="1">
                <input type="hidden" name="scenario_id" value="<?php echo (int)$scenario['id']; ?>">
                <input type="hidden" name="scenario_slug" value="<?php echo esc_attr((string)$scenario['slug']); ?>">
                <?php wp_nonce_field('ckm_sales_create_competition','_ckm_sales_create_nonce'); ?>
                <div class="ckm-sales-form-grid">
                    <label>Название соревнования<input id="ckm-sales-competition-title" name="competition_title" value="<?php echo esc_attr((string)$scenario['title'].' — соревнование'); ?>" maxlength="255"></label>
                    <label>Сложность<select id="ckm-sales-competition-difficulty" name="difficulty" class="ckm-sales-select"><option value="soft">Мягкий</option><option value="medium" selected>Средний</option><option value="hard">Жёсткий</option><option value="expert">Эксперт</option></select></label>
                    <label>Ведущий<select id="ckm-sales-competition-host-mode" name="host_mode" class="ckm-sales-select"><option value="ai" selected>ИИ-ведущий</option><option value="human">Ведущий-человек</option></select></label>
                    <label>Количество команд<select id="ckm-sales-team-count" name="team_count" class="ckm-sales-select"><?php for($i=2;$i<=6;$i++): ?><option value="<?php echo $i; ?>"><?php echo $i; ?></option><?php endfor; ?></select></label>
                </div>
                <p class="ckm-sales-muted">Укажите только названия команд и выберите ведущего. После создания система выдаст одну ссылку ведущего и отдельную ссылку для каждой команды — логин и email команд не нужны.</p>
                <div class="ckm-sales-team-list" id="ckm-sales-team-list">
                    <?php for($i=1;$i<=6;$i++): ?>
                    <div class="ckm-sales-team-row" data-team-row="<?php echo $i; ?>" <?php echo $i>2?'hidden':''; ?>>
                        <strong>Команда <?php echo $i; ?></strong>
                        <input class="ckm-sales-team-name" name="team_names[]" placeholder="Название команды" value="Команда <?php echo esc_attr(chr(64+$i)); ?>">
                    </div>
                    <?php endfor; ?>
                </div>
                <button class="ckm-sales-btn ckm-sales-primary" type="submit">Создать соревнование</button>
                <div class="ckm-sales-inline-status" id="ckm-sales-competition-status" aria-live="polite"></div>
            </form>
        </section>
        <?php return (string)ob_get_clean();
    }

    private static function renderCompetitions(): string {
        $service=new SalesCompetitionService();
        $mine=$service->mine();
        $managed=SalesCompetitionService::canManage()?$service->managed():[];
        ob_start(); ?>
        <section class="ckm-sales-card ckm-sales-results-list ckm-sales-competitions-page">
            <div class="ckm-sales-kicker">СОРЕВНОВАНИЯ</div><h1>Командные соревнования</h1>
            <p class="ckm-sales-lead">Каждая команда разговаривает со своим экземпляром ИИ-клиента. Подсказки во время соревнования недоступны.</p>
            <div class="ckm-sales-competition-columns">
                <div><h2>Мне назначено</h2>
                <?php if(!$mine): ?><p class="ckm-sales-muted">Активных соревнований пока нет.</p><?php else: foreach($mine as $c):$p=is_array($c['participant']??null)?$c['participant']:[]; ?>
                    <article class="ckm-sales-competition-card">
                        <div><strong><?php echo esc_html((string)$c['title']); ?></strong><span><?php echo esc_html((string)($c['scenario_title']??'')); ?></span><small><?php echo esc_html((string)($p['team_name']??'Команда')); ?> · <?php echo esc_html((string)($c['difficulty_label']??'')); ?></small></div>
                        <?php if(!empty($c['can_launch'])): ?><button class="ckm-sales-btn ckm-sales-primary" data-sales-launch-competition="<?php echo (int)$c['id']; ?>"><?php echo !empty($p['active_session_id'])?'Продолжить':'Начать'; ?></button><?php else: ?><span class="ckm-sales-status">Завершено</span><?php endif; ?>
                    </article>
                <?php endforeach; endif; ?></div>
                <?php if(SalesCompetitionService::canManage()): ?><div><h2>Я организую</h2>
                <?php if(!$managed): ?><p class="ckm-sales-muted">Вы ещё не создавали соревнования.</p><?php else: foreach($managed as $c): ?>
                    <article class="ckm-sales-competition-card">
                        <div><strong><?php echo esc_html((string)$c['title']); ?></strong><span><?php echo esc_html((string)($c['scenario_title']??'')); ?></span><small><?php echo esc_html((string)($c['host_mode_label']??'ИИ-ведущий')); ?> · команд: <?php echo (int)($c['participant_count']??0); ?> · завершено: <?php echo (int)($c['completed_sessions']??0); ?></small></div>
                        <a class="ckm-sales-btn" href="<?php echo esc_url(self::url(['sales_view'=>'competitions','sales_competition'=>(int)$c['id']])); ?>">Открыть</a>
                    </article>
                <?php endforeach; endif; ?></div><?php endif; ?>
            </div>
            <div class="ckm-sales-inline-status" id="ckm-sales-competition-list-status" aria-live="polite"></div>
        </section>
        <?php return (string)ob_get_clean();
    }

    private static function renderCompetitionDetail(int $id): string {
        if(!SalesCompetitionService::canManage())throw new \RuntimeException('Рейтинг соревнования доступен организатору.');
        $c=(new SalesCompetitionService())->detail($id);
        $teams=(array)($c['teams']??[]);
        ob_start(); ?>
        <section class="ckm-sales-card ckm-sales-results-list ckm-sales-competition-detail">
            <a class="ckm-sales-back" href="<?php echo esc_url(self::url(['sales_view'=>'competitions'])); ?>">← К соревнованиям</a>
            <div class="ckm-sales-kicker">СОРЕВНОВАНИЕ</div><h1><?php echo esc_html((string)$c['title']); ?></h1>
            <p class="ckm-sales-lead"><?php echo esc_html((string)($c['scenario']['title']??'')); ?> · <?php echo esc_html((string)$c['difficulty_label']); ?></p>
            <div class="ckm-sales-competition-progress"><strong><?php echo (int)$c['completed_teams']; ?> из <?php echo (int)$c['team_count']; ?></strong><span>команд завершили разговор</span></div>
            <div class="ckm-sales-host-link-card"><div><div class="ckm-sales-kicker">ВЕДУЩИЙ</div><strong><?php echo esc_html((string)$c['host_mode_label']); ?></strong><span><?php echo esc_html((string)$c['host_announcement']); ?></span></div><div class="ckm-sales-host-link-actions"><input readonly value="<?php echo esc_attr((string)$c['host_url']); ?>"><button class="ckm-sales-btn" type="button" data-sales-copy-link="<?php echo esc_attr((string)$c['host_url']); ?>">Копировать ссылку</button><a class="ckm-sales-btn ckm-sales-primary" href="<?php echo esc_url((string)$c['host_url']); ?>">Открыть экран ведущего</a></div></div>
            <div class="ckm-sales-team-links"><h2>Ссылки команд</h2><p class="ckm-sales-muted">Передайте каждой команде только её ссылку. Вход в аккаунт не требуется. Команда отметится готовой после открытия ссылки.</p><?php foreach($teams as $team): if(empty($team['team_link']))continue; ?>
                <div class="ckm-sales-team-link-row"><strong><?php echo esc_html((string)$team['team_name']); ?></strong><input readonly value="<?php echo esc_attr((string)$team['team_link']); ?>"><button class="ckm-sales-btn" type="button" data-sales-copy-link="<?php echo esc_attr((string)$team['team_link']); ?>">Копировать</button></div>
            <?php endforeach; ?></div>
            <?php if(empty($c['ranking_ready'])): ?>
                <div class="ckm-sales-waiting"><h3>Рейтинг пока скрыт</h3><p>Он появится после завершения и оценки всех команд. До этого команды не видят подсказок и чужих результатов.</p></div>
            <?php else: ?>
                <div class="ckm-sales-ranking"><h2>Общий рейтинг</h2><?php foreach($teams as $i=>$team): ?>
                    <div class="ckm-sales-rank-row"><b><?php echo $i+1; ?></b><strong><?php echo esc_html((string)$team['team_name']); ?></strong><span><?php echo $team['best_score']!==null?esc_html((string)round((float)$team['best_score']).'/100'):'—'; ?></span></div>
                <?php endforeach; ?></div>
            <?php endif; ?>
            <?php if((string)$c['status']!=='closed'): ?><button class="ckm-sales-btn" data-sales-close-competition="<?php echo (int)$c['id']; ?>">Закрыть соревнование</button><?php endif; ?>
            <div class="ckm-sales-inline-status" id="ckm-sales-competition-detail-status" aria-live="polite"></div>
        </section>
        <?php return (string)ob_get_clean();
    }

    private static function renderCompetitionWaiting(int $id): string {
        $c=(new SalesCompetitionService())->waitingStatus($id);
        ob_start(); ?>
        <section class="ckm-sales-card ckm-sales-team-waiting" id="ckm-sales-team-waiting" data-competition-id="<?php echo (int)$id; ?>">
            <div class="ckm-sales-kicker">СОРЕВНОВАНИЕ</div>
            <h1><?php echo esc_html((string)$c['team_name']); ?></h1>
            <p class="ckm-sales-lead"><?php echo esc_html((string)$c['title']); ?> · <?php echo esc_html((string)$c['scenario_title']); ?></p>
            <div class="ckm-sales-waiting-icon">✓</div>
            <h2>Команда готова</h2>
            <p id="ckm-sales-team-waiting-message"><?php echo esc_html((string)$c['message']); ?></p>
            <div class="ckm-sales-competition-progress"><strong id="ckm-sales-team-ready-count"><?php echo (int)$c['ready_teams']; ?> из <?php echo (int)$c['team_count']; ?></strong><span>команд подключились</span></div>
            <div class="ckm-sales-format-note"><strong><?php echo esc_html((string)$c['host_mode_label']); ?></strong><span>Не закрывайте эту страницу. Разговор откроется автоматически после старта.</span></div>
            <div class="ckm-sales-inline-status" id="ckm-sales-team-waiting-status" aria-live="polite"></div>
        </section>
        <?php return (string)ob_get_clean();
    }

    private static function renderCompetitionHost(int $id): string {
        if(!SalesCompetitionService::canManage())throw new \RuntimeException('Экран ведущего доступен организатору.');
        $c=(new SalesCompetitionService())->hostDashboard($id);
        $teams=(array)($c['teams']??[]);
        ob_start(); ?>
        <section class="ckm-sales-card ckm-sales-host-panel" id="ckm-sales-host-panel" data-competition-id="<?php echo (int)$id; ?>" data-host-mode="<?php echo esc_attr((string)$c['host_mode']); ?>" data-host-phase="<?php echo esc_attr((string)$c['host_phase']); ?>">
            <a class="ckm-sales-back" href="<?php echo esc_url(self::url(['sales_view'=>'competitions','sales_competition'=>$id])); ?>">← К соревнованию</a>
            <div class="ckm-sales-kicker">ЭКРАН ВЕДУЩЕГО</div>
            <h1><?php echo esc_html((string)$c['title']); ?></h1>
            <p class="ckm-sales-lead"><?php echo esc_html((string)($c['scenario']['title']??'')); ?> · <?php echo esc_html((string)$c['host_mode_label']); ?></p>
            <div class="ckm-sales-host-announcement"><strong><?php echo esc_html((string)$c['host_mode_label']); ?></strong><p id="ckm-sales-host-announcement-text"><?php echo esc_html((string)$c['host_announcement']); ?></p></div>
            <div class="ckm-sales-host-metrics"><div><strong id="ckm-sales-host-ready"><?php echo (int)$c['ready_teams']; ?>/<?php echo (int)$c['team_count']; ?></strong><span>готовы</span></div><div><strong id="ckm-sales-host-completed"><?php echo (int)$c['completed_teams']; ?>/<?php echo (int)$c['team_count']; ?></strong><span>завершили</span></div></div>
            <?php if((string)$c['host_mode']==='human' && (string)$c['host_phase']==='waiting'): ?>
                <button class="ckm-sales-btn ckm-sales-primary ckm-sales-host-start" id="ckm-sales-host-start" <?php echo empty($c['all_ready'])?'disabled':''; ?>>Начать соревнование</button>
                <p class="ckm-sales-muted" id="ckm-sales-host-start-note"><?php echo empty($c['all_ready'])?'Кнопка станет доступна, когда откроют ссылки все команды.':'Все команды готовы. Можно начинать.'; ?></p>
            <?php elseif((string)$c['host_mode']==='ai' && (string)$c['host_phase']==='waiting'): ?>
                <div class="ckm-sales-format-note"><strong>Автоматический старт</strong><span>ИИ-ведущий запустит соревнование сразу после готовности всех команд.</span></div>
            <?php endif; ?>
            <div class="ckm-sales-host-team-list" id="ckm-sales-host-team-list">
                <?php foreach($teams as $team): ?>
                    <div class="ckm-sales-host-team" data-team-key="<?php echo esc_attr((string)$team['team_key']); ?>"><strong><?php echo esc_html((string)$team['team_name']); ?></strong><span><?php echo esc_html(['waiting'=>'Не подключена','ready'=>'Готова','playing'=>'В разговоре','completed'=>'Завершила'][(string)$team['state']]??'Ожидает'); ?></span><b><?php echo $team['best_score']!==null?esc_html((string)round((float)$team['best_score']).'/100'):''; ?></b></div>
                <?php endforeach; ?>
            </div>
            <div class="ckm-sales-ranking" id="ckm-sales-host-ranking" <?php echo empty($c['ranking_ready'])?'hidden':''; ?>><h2>Общий рейтинг</h2><div id="ckm-sales-host-ranking-rows"><?php if(!empty($c['ranking_ready'])): foreach($teams as $i=>$team): ?><div class="ckm-sales-rank-row"><b><?php echo $i+1; ?></b><strong><?php echo esc_html((string)$team['team_name']); ?></strong><span><?php echo $team['best_score']!==null?esc_html((string)round((float)$team['best_score']).'/100'):'—'; ?></span></div><?php endforeach; endif; ?></div></div>
            <?php if((string)$c['status']!=='closed'): ?><button class="ckm-sales-btn" data-sales-close-competition="<?php echo (int)$id; ?>">Закрыть соревнование</button><?php endif; ?>
            <div class="ckm-sales-inline-status" id="ckm-sales-host-status" aria-live="polite"></div>
        </section>
        <?php return (string)ob_get_clean();
    }

    private static function renderSession(array $scenario,array $version,array $session): string {
        $mechanics=SalesDomain::publicMechanics($version);
        $stages=$mechanics['stages'] ?: ['Разобраться','Показать ценность','Снять сомнение','Договориться о следующем шаге'];
        if(isset($stages[3]))$stages[3]='Договориться о следующем шаге';
        $exam=(string)($session['mode']??'training')==='exam';
        $competition=$exam&&(int)($session['assignment_id']??0)>0&&SalesCompetitionService::isCompetitionAssignment((int)$session['assignment_id']);
        $assessment=$exam&&!$competition;
        $internalMechanics=SalesDomain::mechanics($version);
        $scriptId=(string)($internalMechanics['script_id']??'');
        $scenarioOwner=(int)($scenario['created_by']??0);
        $trainingScript=(!$exam&&$scriptId!==''&&$scenarioOwner>0)
            ?SalesScriptService::findForOwner($scenarioOwner,SalesScriptService::currentScopeKey(),$scriptId):null;
        $activeTrainingRules=$trainingScript?SalesPracticeFeedbackService::activeRules($trainingScript):[];
        $salesFormat=$competition?'competition':($assessment?'check':'training');
        $teamName=$competition?SalesCompetitionService::currentTeamName((int)($session['assignment_id']??0)):'';
        $teamAuth=$competition?SalesCompetitionService::currentTeamAuthArgs():[];
        ob_start(); ?>
        <div class="ckm-sales-session" id="ckm-sales-session" data-session-id="<?php echo (int)$session['id']; ?>" data-scenario-id="<?php echo (int)$scenario['id']; ?>" data-scenario-slug="<?php echo esc_attr((string)$scenario['slug']); ?>" data-sales-format="<?php echo esc_attr($salesFormat); ?>" data-script-id="<?php echo esc_attr($scriptId); ?>" data-opening="<?php echo esc_attr((string)$mechanics['opening_message']); ?>">
            <?php if($competition): ?><div class="ckm-sales-competition-banner"><strong><?php echo esc_html($teamName!==''?$teamName:'Команда'); ?></strong><span>Соревнование · Подсказки ИИ-тренера отключены до завершения всех команд.</span></div><?php elseif($assessment): ?><div class="ckm-sales-competition-banner ckm-sales-check-banner"><strong>Проверка</strong><span>Контрольный режим · подсказки ИИ-тренера отключены.</span></div><?php endif; ?>
            <div class="ckm-sales-stagebar" id="ckm-sales-stagebar" role="list" aria-label="Этапы разговора"><?php foreach($stages as $i=>$stage): ?><span class="<?php echo $i===0?'is-current':'is-future'; ?>" role="listitem" data-stage-index="<?php echo (int)$i; ?>" data-stage-number="<?php echo (int)($i+1); ?>"<?php echo $i===0?' aria-current="step"':''; ?>><b><?php echo $i+1; ?></b><em><?php echo esc_html($stage); ?></em></span><?php endforeach; ?></div>
            <?php if(!$exam&&$activeTrainingRules): ?><section class="ckm-sales-training-rules"><strong>Действующий стандарт методики</strong><div><?php foreach($activeTrainingRules as $rule): ?><p><?php echo esc_html((string)$rule['rule']); ?></p><?php endforeach; ?></div></section><?php endif; ?>
            <div class="ckm-sales-game-grid">
                <aside class="ckm-sales-card ckm-sales-side">
                    <a class="ckm-sales-back" href="<?php echo esc_url($competition?self::url($teamAuth):($assessment?self::url($scriptId!==''?['sales_methodology'=>$scriptId]:[]):self::url(self::scenarioArgs($scenario,['sales_format'=>'training'])))); ?>">← <?php echo $competition?'В комнату команды':($assessment?'К Центру развития продаж':'К вводной'); ?></a>
                    <div class="ckm-sales-kicker"><?php echo $competition?'СОРЕВНОВАНИЕ':($assessment?'ПРОВЕРКА':'СИТУАЦИЯ'); ?></div><h3><?php echo esc_html((string)$scenario['title']); ?></h3>
                    <p><?php echo esc_html((string)$version['player_situation']); ?></p>
                    <div class="ckm-sales-mini-task"><strong>Цель</strong><span><?php echo esc_html((string)$version['player_task']); ?></span></div>
                </aside>
                <section class="ckm-sales-card ckm-sales-dialogue">
                    <div class="ckm-sales-dialogue-head"><div><div class="ckm-sales-kicker">ДИАЛОГ</div><h2>Разговор с клиентом</h2></div><span id="ckm-sales-runtime-status">Загрузка…</span></div>
                    <div class="ckm-sales-messages" id="ckm-sales-messages"></div>
                    <?php if(!$competition&&!$assessment): ?><div class="ckm-sales-coach" id="ckm-sales-coach-output" hidden></div><?php endif; ?>
                    <div class="ckm-sales-composer" id="ckm-sales-composer">
                        <textarea id="ckm-sales-input" rows="3" placeholder="Ответьте клиенту…"></textarea>
                        <div class="ckm-sales-composer-actions <?php echo $competition?'is-competition':''; ?>"><?php if(!$competition&&!$assessment): ?><button class="ckm-sales-btn" id="ckm-sales-hint">Нужна подсказка</button><?php endif; ?><button class="ckm-sales-btn ckm-sales-primary" id="ckm-sales-send">Отправить</button></div>
                    </div>
                </section>
                <aside class="ckm-sales-card ckm-sales-progress">
                    <div class="ckm-sales-kicker">ЧТО ВЫ УЖЕ УЗНАЛИ</div><h3>Картина клиента</h3>
                    <div id="ckm-sales-discoveries" class="ckm-sales-discoveries"><p class="ckm-sales-muted">Пока ничего не раскрыто.</p></div>
                    <button class="ckm-sales-btn ckm-sales-finish" id="ckm-sales-finish">Завершить разговор</button>
                </aside>
            </div>
            <section class="ckm-sales-card ckm-sales-result" id="ckm-sales-result" hidden></section>
        </div>
        <?php return (string)ob_get_clean();
    }


    private static function referenceScenarioForClass(array $library,string $class): ?array {
        foreach(self::sortSalesCards(ProductCatalog::libraryCards((int)$library['id'])) as $card){
            $m=self::cardMeta($card);if($m['category']===$class)return $card;
        }
        return null;
    }

    private static function renderScripts(array $library): string {
        $notice=self::takeScriptNotice();$scripts=SalesScriptService::all();$classes=SalesScriptService::classes();$cards=self::sortSalesCards(ProductCatalog::libraryCards((int)$library['id']));
        ob_start(); ?>
        <section class="ckm-sales-card ckm-sales-script-hero">
            <div class="ckm-sales-kicker">СКРИПТЫ ПРОДАЖ</div><h1>Создавайте адаптивные скрипты</h1>
            <p class="ckm-sales-lead">Не заученный текст, а карта разговора: цель → вопросы → развилки → ценность → конкретный следующий шаг.</p>
            <div class="ckm-sales-script-flow"><span><b>1</b>Создать</span><span>→</span><span><b>2</b>Проверить на ИИ-клиенте</span><span>→</span><span><b>3</b>Утвердить</span></div>
        </section>
        <?php if(!empty($notice['message'])): ?><div class="ckm-sales-inline-status <?php echo !empty($notice['error'])?'ckm-sales-error':''; ?>"><?php echo esc_html((string)$notice['message']); ?></div><?php endif; ?>
        <section class="ckm-sales-section ckm-sales-script-layout">
          <div class="ckm-sales-card ckm-sales-script-builder">
            <div class="ckm-sales-kicker">КОНСТРУКТОР</div><h2>Создать свой скрипт</h2>
            <form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'scripts'])); ?>" class="ckm-sales-template-action">
              <?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="create_adaptive_template">
              <button class="ckm-sales-btn ckm-sales-primary" type="submit">Готовый скрипт: «Продажа адаптивных скриптов»</button>
            </form>
            <form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'scripts'])); ?>" class="ckm-sales-script-form">
              <?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="create">
              <label><span>Название скрипта</span><input name="script_title" required maxlength="160" placeholder="Например: Продажа CRM — дорого"></label>
              <label><span>Продукт</span><textarea name="script_product" required rows="3" placeholder="Что продаём, ключевые особенности и цена"></textarea></label>
              <label><span>Клиент</span><textarea name="script_client" required rows="3" placeholder="Кто клиент, роль, компания, контекст"></textarea></label>
              <label><span>Класс ситуации</span><select name="script_class" required><?php foreach($classes as $class): ?><option value="<?php echo esc_attr($class); ?>"><?php echo esc_html($class); ?></option><?php endforeach; ?></select></label>
              <label><span>Цель разговора</span><textarea name="script_goal" required rows="3" placeholder="Какой результат разговора считаем хорошим"></textarea></label>
              <label><span>Ограничения</span><textarea name="script_constraints" rows="3" placeholder="Например: не давать скидку сразу; не давить; не обещать неподтверждённое"></textarea></label>
              <button class="ckm-sales-btn ckm-sales-primary" type="submit">Создать черновик</button>
            </form>
          </div>
          <div class="ckm-sales-card ckm-sales-script-list">
            <div class="ckm-sales-kicker">МОИ СКРИПТЫ</div><h2><?php echo $scripts?'Сохранённые скрипты':'Пока нет скриптов'; ?></h2>
            <?php if(!$scripts): ?><p class="ckm-sales-muted">Создайте первый черновик слева. Он сохранится в вашем кабинете на этой площадке.</p><?php else: foreach($scripts as $script): ?>
              <a class="ckm-sales-script-row" href="<?php echo esc_url(self::url(['sales_view'=>'scripts','sales_script'=>(string)$script['id']])); ?>"><span><strong><?php echo esc_html((string)$script['title']); ?></strong><small><?php echo esc_html((string)$script['situation_class']); ?></small></span><b class="<?php echo (string)$script['status']==='approved'?'is-approved':''; ?>"><?php echo (string)$script['status']==='approved'?'Утверждён':'Черновик'; ?></b></a>
            <?php endforeach; endif; ?>
          </div>
        </section>
        <section class="ckm-sales-section"><div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">ОПОРНЫЕ СЦЕНАРИИ</div><h2>12 ситуаций для проверки скриптов</h2></div></div>
          <div class="ckm-sales-scenario-grid ckm-sales-script-template-grid"><?php foreach($cards as $card):$m=self::cardMeta($card); ?>
            <article class="ckm-sales-card ckm-sales-scenario-card"><div class="ckm-sales-card-top"><span class="ckm-sales-pill"><?php echo esc_html($m['category']); ?></span><span class="ckm-sales-status">ИИ-клиент</span></div><h3><?php echo esc_html((string)$card['title']); ?></h3><p><?php echo esc_html($m['description']); ?></p><div class="ckm-sales-card-actions"><a class="ckm-sales-btn" href="<?php echo esc_url(self::url(['sales_scenario'=>(string)$card['slug'],'sales_format'=>'training'])); ?>">Проверить подход</a></div></article>
          <?php endforeach; ?></div>
        </section>
        <?php return (string)ob_get_clean();
    }

    private static function renderScriptDetail(array $script,array $library): string {
        $notice=self::takeScriptNotice();$steps=SalesScriptService::playbook((string)$script['situation_class']);$reference=self::referenceScenarioForClass($library,(string)$script['situation_class']);$practiceInsights=SalesPracticeFeedbackService::canManage()?SalesPracticeFeedbackService::insights((string)$script['id']):[];$practiceNotes=is_array($script['practice_methodology_notes']??null)?array_values($script['practice_methodology_notes']):[];$recert=SalesStandardRecertificationService::canManage()?SalesStandardRecertificationService::dashboard((string)$script['id']):['available'=>false];$review=SalesStandardRevisionDecisionService::canManage()?SalesStandardRevisionDecisionService::dashboard((string)$script['id']):['available'=>false,'history'=>[]];$impact=is_array($review['impact']??null)?$review['impact']:(SalesStandardImpactService::canManage()?SalesStandardImpactService::dashboard((string)$script['id']):['available'=>false]);
        ob_start(); ?>
        <section class="ckm-sales-card ckm-sales-script-detail">
          <a class="ckm-sales-back" href="<?php echo esc_url(self::url(['sales_view'=>'scripts'])); ?>">← К скриптам</a>
          <div class="ckm-sales-card-top"><span class="ckm-sales-pill"><?php echo esc_html((string)$script['situation_class']); ?></span><span class="ckm-sales-status <?php echo (string)$script['status']==='approved'?'is-approved':''; ?>"><?php echo (string)$script['status']==='approved'?'Утверждён':'Черновик'; ?></span></div>
          <div class="ckm-sales-kicker">АДАПТИВНЫЙ СКРИПТ</div><h1><?php echo esc_html((string)$script['title']); ?></h1>
          <?php if(!empty($notice['message'])): ?><div class="ckm-sales-inline-status <?php echo !empty($notice['error'])?'ckm-sales-error':''; ?>"><?php echo esc_html((string)$notice['message']); ?></div><?php endif; ?>
          <div class="ckm-sales-script-context"><div><small>Продукт</small><p><?php echo esc_html((string)$script['product']); ?></p></div><div><small>Клиент</small><p><?php echo esc_html((string)$script['client']); ?></p></div><div><small>Цель</small><p><?php echo esc_html((string)$script['goal']); ?></p></div><?php if((string)$script['constraints']!==''): ?><div><small>Ограничения</small><p><?php echo esc_html((string)$script['constraints']); ?></p></div><?php endif; ?></div>
          <div class="ckm-sales-script-steps"><?php foreach($steps as $i=>$step): ?><article><b><?php echo (int)($i+1); ?></b><div><h3><?php echo esc_html((string)$step[0]); ?></h3><p><?php echo esc_html((string)$step[1]); ?></p></div></article><?php endforeach; ?></div>
          <div class="ckm-sales-script-rule"><strong>Правило скрипта</strong><span>Не переходите к следующему блоку автоматически. Переход определяется ответом клиента; если гипотеза не подтверждена, вернитесь к уточняющему вопросу.</span></div>
          <section class="ckm-sales-practice-methodology">
            <div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">ПРАКТИКА → МЕТОДИКА</div><h2>Что реальная практика предлагает закрепить в стандарте</h2></div><span class="ckm-sales-muted"><?php echo count($practiceInsights); ?> типов сигналов</span></div>
            <p class="ckm-sales-muted">Рекомендация появляется из повторяющихся трудных диалогов. Кнопка не переписывает утверждённый скрипт автоматически: она добавляет управленческое правило, подтверждённое практикой, которое руководитель может учитывать при следующей редакции стандарта.</p>
            <?php if(!$practiceInsights): ?><div class="ckm-sales-format-note"><strong>Практических сигналов пока нет</strong><span>Они появятся после реальных неуспешных, застопоренных или переданных человеку диалогов.</span></div>
            <?php else: ?><div class="ckm-sales-methodology-signals">
              <?php foreach($practiceInsights as $signal):$repeated=!empty($signal['repeated']);$adopted=!empty($signal['adopted']); ?>
                <article class="ckm-sales-methodology-signal <?php echo $repeated?'is-repeated':'is-observation'; ?>">
                  <div class="ckm-sales-card-top"><div><span class="ckm-sales-pill"><?php echo esc_html((string)$signal['focus_title']); ?></span><strong><?php echo (int)$signal['count']; ?> трудных диалогов</strong></div><span class="ckm-sales-status <?php echo $adopted?'is-approved':''; ?>"><?php echo $adopted?'Добавлено в методику':($repeated?'Повторяющийся сигнал':'Наблюдение'); ?></span></div>
                  <p><?php echo esc_html((string)$signal['recommendation']); ?></p>
                  <div class="ckm-sales-meta"><span>Неуспешных: <?php echo (int)$signal['unsuccessful']; ?></span><span>Застопорено: <?php echo (int)$signal['stalled']; ?></span><span>Передано человеку: <?php echo (int)$signal['handoff']; ?></span><?php if(!empty($signal['channels'])): ?><span>Каналы: <?php echo esc_html(implode(', ',(array)$signal['channels'])); ?></span><?php endif; ?></div>
                  <?php if($repeated&&!$adopted): ?><form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'scripts','sales_script'=>(string)$script['id']])); ?>">
                    <?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="practice_adopt_methodology"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><input type="hidden" name="focus_code" value="<?php echo esc_attr((string)$signal['focus_code']); ?>"><button class="ckm-sales-btn ckm-sales-primary" type="submit">Добавить в методику</button>
                  </form><?php elseif(!$repeated): ?><small class="ckm-sales-muted">Нужен ещё минимум один подобный случай, прежде чем менять стандарт.</small><?php endif; ?>
                </article>
              <?php endforeach; ?>
            </div><?php endif; ?>
            <?php if($practiceNotes): ?><div class="ckm-sales-methodology-adopted">
              <h3>Правила, уже принятые из практики</h3>
              <p class="ckm-sales-muted">Принятое правило сначала не действует. Руководитель отдельно включает его для ИИ-продавца и обучающих тренировок; при необходимости правило можно отключить.</p>
              <?php foreach($practiceNotes as $note):if(!is_array($note))continue;$active=(string)($note['status']??'adopted')==='active'; ?>
                <div class="ckm-sales-methodology-active-row">
                  <strong><?php echo esc_html((string)($note['focus_title']??'Практическое правило')); ?></strong>
                  <span><?php echo esc_html((string)($note['recommendation']??'')); ?></span>
                  <small>Подтверждено: <?php echo (int)($note['evidence_count']??0); ?> диалогов · <?php echo $active?'Действует в обучении и ИИ-продавце':'Принято, но пока не действует'; ?></small>
                  <?php if((string)($script['status']??'draft')==='approved'): ?>
                    <form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'scripts','sales_script'=>(string)$script['id']])); ?>">
                      <?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?>
                      <input type="hidden" name="ckm_sales_script_action" value="<?php echo $active?'practice_disable_rule':'practice_activate_rule'; ?>">
                      <input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>">
                      <input type="hidden" name="focus_code" value="<?php echo esc_attr((string)($note['focus_code']??'')); ?>">
                      <button class="ckm-sales-btn <?php echo $active?'':'ckm-sales-primary'; ?>" type="submit"><?php echo $active?'Отключить правило':'Включить в стандарт'; ?></button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
              <p class="ckm-sales-muted">Редакция действующего стандарта: <?php echo (int)($script['practice_standard_revision']??0); ?></p>
            </div><?php endif; ?>
          </section>
          <?php if(!empty($recert['available'])): ?>
          <section class="ckm-sales-standard-recert">
            <div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">НОВАЯ РЕДАКЦИЯ СТАНДАРТА · v<?php echo (int)$recert['revision']; ?></div><h2>Повторная проверка команды</h2></div><span class="ckm-sales-status is-approved"><?php echo esc_html((string)$recert['focus_title']); ?></span></div>
            <p><?php echo esc_html((string)$recert['rule']); ?></p>
            <div class="ckm-sales-meta"><span>Сотрудников: <?php echo (int)$recert['employee_count']; ?></span><span>Проверку завершили: <?php echo (int)$recert['completed_count']; ?></span><?php if((int)$recert['scenario_id']>0): ?><span>Контрольный кейс: #<?php echo (int)$recert['scenario_id']; ?></span><?php endif; ?></div>
            <?php if(!empty($recert['employees'])): ?>
              <form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'scripts','sales_script'=>(string)$script['id']])); ?>" class="ckm-sales-recert-all">
                <?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="standard_recert_assign_all"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><button class="ckm-sales-btn ckm-sales-primary" type="submit">Назначить повторную проверку всей команде</button>
              </form>
              <div class="ckm-sales-recert-list">
              <?php foreach((array)$recert['employees'] as $employee):$assignment=is_array($employee['assignment']??null)?$employee['assignment']:null; ?>
                <div class="ckm-sales-recert-row"><div><strong><?php echo esc_html((string)$employee['label']); ?></strong><small>Последний результат: <?php echo (int)round((float)$employee['latest_score']); ?>/100</small></div>
                  <?php if($assignment): ?><span class="ckm-sales-status <?php echo (string)($assignment['status']??'')==='Выполнено'?'is-approved':''; ?>"><?php echo esc_html((string)$assignment['status']); ?></span><?php if(!empty($assignment['assignment_url'])): ?><a class="ckm-sales-btn" href="<?php echo esc_url((string)$assignment['assignment_url']); ?>">Открыть</a><?php endif; ?>
                  <?php else: ?><form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'scripts','sales_script'=>(string)$script['id']])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="standard_recert_assign"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><input type="hidden" name="participant_key" value="<?php echo esc_attr((string)$employee['participant_key']); ?>"><button class="ckm-sales-btn" type="submit">Назначить проверку</button></form><?php endif; ?>
                </div>
              <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>
          <?php endif; ?>
          <?php if(!empty($impact['available'])):$c=is_array($impact['conclusion']??null)?$impact['conclusion']:[];$avgBefore=$impact['average_before']??null;$avgAfter=$impact['average_after']??null;$avgDelta=$impact['average_delta']??null;$passRate=$impact['pass_rate']??null; ?>
          <section class="ckm-sales-standard-impact">
            <div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">ЭФФЕКТ РЕДАКЦИИ · v<?php echo (int)$impact['revision']; ?></div><h2>Изменился ли навык после нового стандарта</h2></div><span class="ckm-sales-status <?php echo in_array((string)($c['code']??''),['confirmed','preliminary_positive'],true)?'is-approved':''; ?>"><?php echo esc_html((string)($c['title']??'Ждём данных')); ?></span></div>
            <p><?php echo esc_html((string)($c['text']??'')); ?></p>
            <div class="ckm-sales-impact-metrics">
              <div><small>Навык</small><strong><?php echo esc_html((string)$impact['focus_title']); ?></strong></div>
              <div><small>Среднее до</small><strong><?php echo $avgBefore===null?'—':esc_html((string)(int)round((float)$avgBefore).'/100'); ?></strong></div>
              <div><small>Среднее после</small><strong><?php echo $avgAfter===null?'—':esc_html((string)(int)round((float)$avgAfter).'/100'); ?></strong></div>
              <div><small>Изменение</small><strong><?php echo $avgDelta===null?'—':esc_html(((float)$avgDelta>0?'+':'').(string)(int)round((float)$avgDelta)); ?></strong></div>
              <div><small>Достигли 75+</small><strong><?php echo $passRate===null?'—':esc_html((string)(int)round((float)$passRate).'%'); ?></strong></div>
            </div>
            <p class="ckm-sales-muted">Расчёт детерминированный: сравнивается один и тот же критерий до назначения новой редакции и в контрольной exam-проверке. Дополнительная ИИ-оценка для вывода не запускается.</p>
            <?php if(!empty($impact['employees'])): ?><div class="ckm-sales-impact-table-wrap"><table class="ckm-sales-impact-table"><thead><tr><th>Сотрудник</th><th>До</th><th>После</th><th>Δ</th><th>Статус</th></tr></thead><tbody>
              <?php foreach((array)$impact['employees'] as $row):$before=is_array($row['before']??null)?$row['before']:null;$after=is_array($row['after']??null)?$row['after']:null;$delta=$row['delta']??null; ?>
                <tr><td><strong><?php echo esc_html((string)$row['label']); ?></strong></td><td><?php echo $before&&$before['criterion_score']!==null?esc_html((string)(int)round((float)$before['criterion_score']).'/100'):'—'; ?></td><td><?php echo $after&&$after['criterion_score']!==null?esc_html((string)(int)round((float)$after['criterion_score']).'/100'):'—'; ?></td><td><?php echo $delta===null?'—':esc_html(((float)$delta>0?'+':'').(string)(int)round((float)$delta)); ?></td><td><?php if(!$row['assignment']): ?>Не назначено<?php elseif(!$after): ?><?php echo esc_html((string)$row['status']); ?><?php else: ?><span class="ckm-sales-status <?php echo !empty($row['passed'])?'is-approved':''; ?>"><?php echo !empty($row['passed'])?'75+ достигнуто':'Ниже 75'; ?></span><?php endif; ?></td></tr>
              <?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
            <?php $decision=is_array($review['decision']??null)?$review['decision']:null;$permissions=is_array($review['permissions']??null)?$review['permissions']:[]; ?>
            <div class="ckm-sales-revision-decision">
              <div><strong>Управленческое решение по редакции</strong><span><?php echo esc_html((string)($permissions['reason']??'')); ?></span></div>
              <?php if($decision): ?><span class="ckm-sales-status is-approved"><?php echo esc_html((string)($decision['decision_label']??'Решение принято')); ?></span>
              <?php else: ?>
                <?php if(!empty($permissions['confirm'])): ?><form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'scripts','sales_script'=>(string)$script['id']])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="standard_revision_confirm"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><input type="hidden" name="standard_revision" value="<?php echo (int)$review['revision']; ?>"><button class="ckm-sales-btn ckm-sales-primary" type="submit">Закрепить редакцию</button></form><?php endif; ?>
                <?php if(!empty($permissions['rework'])): ?><form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'scripts','sales_script'=>(string)$script['id']])); ?>" onsubmit="return confirm('Отправить правило на доработку и исключить его из действующего стандарта?');"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="standard_revision_rework"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><input type="hidden" name="standard_revision" value="<?php echo (int)$review['revision']; ?>"><button class="ckm-sales-btn" type="submit">Отправить правило на доработку</button></form><?php endif; ?>
              <?php endif; ?>
            </div>
          </section>
          <?php endif; ?>
          <?php if(!empty($review['history'])): ?>
          <section class="ckm-sales-revision-history"><div class="ckm-sales-kicker">ЖУРНАЛ РЕШЕНИЙ ПО СТАНДАРТУ</div>
            <?php foreach(array_slice((array)$review['history'],0,8) as $row):$snap=is_array($row['impact']??null)?$row['impact']:[]; ?><div class="ckm-sales-revision-history-row"><div><strong>v<?php echo (int)($row['revision']??0); ?> · <?php echo esc_html((string)($row['focus_title']??'')); ?></strong><small><?php echo esc_html((string)($row['at']??'')); ?></small></div><span class="ckm-sales-status <?php echo (string)($row['decision']??'')==='confirm'?'is-approved':''; ?>"><?php echo esc_html((string)($row['decision_label']??'')); ?></span><span><?php echo isset($snap['average_delta'])&&$snap['average_delta']!==null?esc_html((((float)$snap['average_delta']>0)?'+':'').(string)(int)round((float)$snap['average_delta'])):'—'; ?></span></div><?php endforeach; ?>
          </section>
          <?php endif; ?>
          <?php $customId=(int)($script['ai_client_scenario_id']??0); ?>
          <div class="ckm-sales-card ckm-sales-script-ai-client">
            <div class="ckm-sales-kicker">СОБСТВЕННЫЙ ИИ-КЛИЕНТ</div>
            <?php if($customId>0): ?>
              <h2><?php echo esc_html((string)($script['ai_client_name']??'ИИ-клиент')); ?></h2>
              <p class="ckm-sales-muted"><?php echo esc_html((string)($script['ai_client_role']??'')); ?></p>
              <blockquote><?php echo esc_html((string)($script['ai_client_opening']??'')); ?></blockquote>
              <div class="ckm-sales-script-client-meta"><span>Скрытых факторов: <b><?php echo (int)($script['ai_client_hidden_fact_count']??0); ?></b></span><span>Версия клиента: <b><?php echo (int)($script['ai_client_version_number']??1); ?></b></span><span><?php echo (string)($script['ai_client_generation_source']??'')==='ai'?'Сгенерирован ИИ':'Резервный профиль'; ?></span></div>
              <div class="ckm-sales-card-actions"><a class="ckm-sales-btn ckm-sales-primary" href="<?php echo esc_url(self::url(['sales_custom_scenario'=>$customId,'sales_format'=>'training'])); ?>">Проверить на своём ИИ-клиенте</a>
              <form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'scripts','sales_script'=>(string)$script['id']])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="regenerate_client"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><button class="ckm-sales-btn" type="submit">Перегенерировать ИИ-клиента</button></form></div>
            <?php else: ?>
              <h2>Создать клиента именно под этот скрипт</h2><p>Система сформирует скрытый профиль клиента, первую реплику, мотивы, риски, критерии решения и условия следующего шага из вашего продукта, клиента, цели и ограничений.</p>
              <form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'scripts','sales_script'=>(string)$script['id']])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="generate_client"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><button class="ckm-sales-btn ckm-sales-primary" type="submit">Сгенерировать своего ИИ-клиента</button></form>
            <?php endif; ?>
          </div>
          <?php $sellerEnabled=!empty($script['ai_seller_enabled'])&&strlen((string)($script['ai_seller_token']??''))>=32;$sellerUrl=$sellerEnabled?self::url(['sales_view'=>'ai-seller','sales_seller'=>(string)$script['ai_seller_token']]):''; ?>
          <div class="ckm-sales-card ckm-sales-script-ai-seller">
            <div class="ckm-sales-kicker">ИИ-ПРОДАВЕЦ</div>
            <?php if($sellerEnabled): ?>
              <h2>ИИ-продавец подключён</h2>
              <p>Настоящий клиент может открыть отдельную ссылку, говорить через микрофон или писать текстом. ИИ отвечает по этому скрипту и не выходит за подтверждённые данные продукта.</p>
              <div class="ckm-sales-script-client-meta"><span><?php echo function_exists('ckm_quiz_pro_voice_ready')&&ckm_quiz_pro_voice_ready()&&function_exists('ckm_quiz_pro_ai_voice_provider')&&ckm_quiz_pro_ai_voice_provider()==='gateway'?'Голос: Сергей · Cartesia':'Голос: браузерный русский'; ?></span><span>Аудио не сохраняется в WordPress</span><span>Скрипт: <b><?php echo esc_html((string)$script['status']==='approved'?'утверждён':'черновик'); ?></b></span></div>
              <label class="ckm-sales-public-link"><span>Ссылка для клиента</span><input type="text" readonly value="<?php echo esc_attr($sellerUrl); ?>" onclick="this.select()"></label>
              <div class="ckm-sales-card-actions"><a class="ckm-sales-btn ckm-sales-primary" href="<?php echo esc_url($sellerUrl); ?>" target="_blank" rel="noopener">Открыть ИИ-продавца</a>
                <form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'scripts','sales_script'=>(string)$script['id']])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="disconnect_seller"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><button class="ckm-sales-btn" type="submit">Отключить ИИ-продавца</button></form>
              </div>
            <?php else: ?>
              <h2>Подключить скрипт к реальному разговору</h2><p>После подключения клиент получает отдельный голосовой экран: задаёт вопросы, а ИИ-продавец ведёт разговор по адаптивному скрипту; при включённом Gateway ответ озвучивает профессиональный голос Сергея.</p>
              <form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'scripts','sales_script'=>(string)$script['id']])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="connect_seller"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><button class="ckm-sales-btn ckm-sales-primary" type="submit" <?php echo (string)$script['status']!=='approved'?'disabled':''; ?>>Подключить к ИИ-продавцу</button></form>
              <?php if((string)$script['status']!=='approved'): ?><p class="ckm-sales-muted">Перед подключением утвердите скрипт — реальный клиент не должен получать непроверенную версию.</p><?php endif; ?>
            <?php endif; ?>
          </div>
          <div class="ckm-sales-card-actions ckm-sales-script-actions">
            <?php if($reference): ?><a class="ckm-sales-btn" href="<?php echo esc_url(self::url(['sales_scenario'=>(string)$reference['slug'],'sales_format'=>'training'])); ?>">Сравнить с типовым ИИ-клиентом</a><?php endif; ?>
            <?php if((string)$script['status']!=='approved'): ?><form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'scripts','sales_script'=>(string)$script['id']])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="approve"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><button class="ckm-sales-btn" type="submit">Утвердить скрипт</button></form><?php endif; ?>
            <form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'scripts'])); ?>" onsubmit="return confirm('Удалить этот скрипт?');"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="delete"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><button class="ckm-sales-btn" type="submit">Удалить</button></form>
          </div>
          <?php if($reference):$m=self::cardMeta($reference); ?><div class="ckm-sales-format-note"><strong>Два уровня проверки</strong><span>Типовой ИИ-клиент проверяет общую методику для класса «<?php echo esc_html((string)$script['situation_class']); ?>». Собственный ИИ-клиент использует именно ваш продукт, клиента, цель и ограничения и хранит отдельный скрытый профиль для разговора.</span></div><?php endif; ?>
        </section>
        <?php return (string)ob_get_clean();
    }

    private static function renderAiSellerWorkspace(array $script): string {
        $notice=self::takeScriptNotice();$w=SalesAiSellerWorkspaceService::workspace($script);$telegram=SalesTelegramService::statusForScript($script);$max=SalesMaxService::statusForScript($script);$whatsapp=SalesWhatsAppService::statusForScript($script);$telephony=SalesTelephonyService::statusForScript($script);$tenantIntegrations=SalesPartnerIntegrationService::listCurrentTenant();$integrationBindings=SalesPartnerIntegrationService::bindingsForCurrentScript($script);$canManageIntegrations=SalesPartnerIntegrationService::canManageSecrets();$availableTelegram=array_values(array_filter($tenantIntegrations,static fn(array $r): bool=>(string)($r['provider']??'')==='telegram'&&((string)($r['assigned_script_id']??'')===''||(string)($r['assigned_script_id']??'')===(string)$script['id'])));$availableMax=array_values(array_filter($tenantIntegrations,static fn(array $r): bool=>(string)($r['provider']??'')==='max'&&((string)($r['assigned_script_id']??'')===''||(string)($r['assigned_script_id']??'')===(string)$script['id'])));$availableWhatsApp=array_values(array_filter($tenantIntegrations,static fn(array $r): bool=>(string)($r['provider']??'')==='whatsapp'&&((string)($r['assigned_script_id']??'')===''||(string)($r['assigned_script_id']??'')===(string)$script['id'])));$availableSip=array_values(array_filter($tenantIntegrations,static fn(array $r): bool=>(string)($r['provider']??'')==='sip'&&((string)($r['assigned_script_id']??'')===''||(string)($r['assigned_script_id']??'')===(string)$script['id'])));$analytics=SalesAiSellerWorkspaceService::analytics((string)$script['id']);$dialogs=SalesAiSellerWorkspaceService::recentDialogs((string)$script['id'],30);$dialogId=sanitize_text_field((string)($_GET['sales_dialog']??''));$selectedDialog=$dialogId!==''?SalesAiSellerWorkspaceService::findDialog((string)$script['id'],$dialogId):null;$pipeline=SalesAiSellerWorkspaceService::pipelineStages();$outcomeLabels=SalesAiSellerWorkspaceService::outcomeLabels();$scenarioTriggers=SalesAiSellerWorkspaceService::scenarioTriggers();$scenarioActions=SalesAiSellerWorkspaceService::scenarioActions();$practice=SalesPracticeFeedbackService::canManage()?SalesPracticeFeedbackService::dashboard((string)$script['id']):['candidates'=>[],'count'=>0,'created'=>0,'pending'=>0];$teamForPractice=SalesPracticeFeedbackService::canManage()?SalesTeamDevelopmentService::dashboard():['employees'=>[]];$practiceEmployees=array_values(array_filter((array)($teamForPractice['employees']??[]),static fn(array $e): bool=>(int)($e['user_id']??0)>0));
        $on=!empty($script['ai_seller_enabled']);$sellerUrl=$on?self::url(['sales_view'=>'ai-seller','sales_seller'=>(string)$script['ai_seller_token']]):'';
        ob_start(); ?>
        <a class="ckm-sales-back" href="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers'])); ?>">← Все ИИ-продавцы</a>
        <?php if($notice): ?><div class="ckm-sales-card ckm-sales-workspace-notice <?php echo !empty($notice['error'])?'is-error':''; ?>"><?php echo esc_html((string)($notice['message']??'')); ?></div><?php endif; ?>
        <section class="ckm-sales-card ckm-sales-script-hero ckm-sales-agent-hero">
          <div class="ckm-sales-card-top"><div><div class="ckm-sales-kicker">ИИ-ПРОДАВЕЦ · РАБОЧЕЕ МЕСТО</div><h1><?php echo esc_html((string)$script['title']); ?></h1></div><span class="ckm-sales-status <?php echo $on?'is-approved':''; ?>"><?php echo $on?'Активен':'Не подключён'; ?></span></div>
          <p class="ckm-sales-lead">Настройте агента: профиль → база знаний → тестирование → корректировки → реальные диалоги. Скрипт остаётся ядром поведения, а база знаний — источником конкретных фактов.</p>
          <div class="ckm-sales-script-flow"><span><b>1</b>Настроить</span><span>→</span><span><b>2</b>Добавить знания</span><span>→</span><span><b>3</b>Протестировать</span><span>→</span><span><b>4</b>Подключить клиентам</span></div>
        </section>
        <section class="ckm-sales-agent-metrics">
          <div class="ckm-sales-card"><small>Конверсия в цель</small><strong><?php echo esc_html((string)($analytics['conversion_rate']??0)); ?>%</strong></div>
          <div class="ckm-sales-card"><small>Диалоги</small><strong><?php echo esc_html((string)$analytics['dialogs']); ?></strong></div>
          <div class="ckm-sales-card"><small>Успешные</small><strong><?php echo esc_html((string)($analytics['successful']??0)); ?></strong></div>
          <div class="ckm-sales-card"><small>Застопорены</small><strong><?php echo esc_html((string)($analytics['stalled']??0)); ?></strong></div>
        </section>
        <section class="ckm-sales-agent-grid">
          <div class="ckm-sales-card ckm-sales-agent-panel">
            <div class="ckm-sales-kicker">01 · ПРОФИЛЬ АГЕНТА</div><h2>Как он продаёт</h2>
            <form class="ckm-sales-script-form" method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id']])); ?>">
              <?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_profile"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>">
              <label>Имя агента<input name="seller_name" value="<?php echo esc_attr((string)$w['name']); ?>" maxlength="80"></label>
              <label>Стиль общения<input name="seller_tone" value="<?php echo esc_attr((string)$w['tone']); ?>" maxlength="180"></label>
              <label>Основная цель продажи<input name="seller_primary_goal" value="<?php echo esc_attr((string)$w['primary_goal']); ?>" maxlength="300" placeholder="Например: назначить демонстрацию или получить оплату" required></label>
              <label>Когда передавать человеку<textarea name="seller_handoff" rows="4"><?php echo esc_textarea((string)$w['handoff']); ?></textarea></label>
              <button class="ckm-sales-btn ckm-sales-primary" type="submit">Сохранить профиль</button>
            </form>
          </div>
          <div class="ckm-sales-card ckm-sales-agent-panel">
            <div class="ckm-sales-kicker">02 · БАЗА ЗНАНИЙ</div><h2>Что агент имеет право утверждать</h2>
            <form class="ckm-sales-script-form ckm-sales-agent-inline-form" method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id']])); ?>">
              <?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_add_url"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>">
              <label>Ссылка на сайт / страницу<input type="url" name="knowledge_url" placeholder="https://example.ru/product" required></label><button class="ckm-sales-btn" type="submit">Прочитать страницу</button>
            </form>
            <details class="ckm-sales-agent-details"><summary>Добавить текст вручную</summary><form class="ckm-sales-script-form" method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id']])); ?>">
              <?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_add_text"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>">
              <label>Название<input name="knowledge_title" placeholder="Прайс, условия, FAQ"></label><label>Текст<textarea name="knowledge_text" rows="6" placeholder="Цены, условия, характеристики, ответы на частые вопросы…" required></textarea></label><button class="ckm-sales-btn" type="submit">Добавить материал</button>
            </form></details>
            <div class="ckm-sales-agent-list"><?php if(!$w['knowledge']): ?><p class="ckm-sales-muted">Источников пока нет. Без базы знаний агент использует только данные утверждённого скрипта.</p><?php else: foreach($w['knowledge'] as $src):if(!is_array($src))continue; ?>
              <article><div><strong><?php echo esc_html((string)($src['title']??'Источник')); ?></strong><small><?php echo esc_html((string)($src['type']??'text')==='url'?(string)($src['url']??''):'Текстовый материал'); ?></small></div><form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id']])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_delete_kb"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><input type="hidden" name="source_id" value="<?php echo esc_attr((string)($src['id']??'')); ?>"><button class="ckm-sales-link-button" type="submit">Удалить</button></form></article>
            <?php endforeach; endif; ?></div>
          </div>
          <div class="ckm-sales-card ckm-sales-agent-panel">
            <div class="ckm-sales-kicker">03 · ТЕСТИРОВАНИЕ</div><h2>Проверьте как клиент</h2>
            <p>Задайте вопросы о цене, условиях, возражениях и специально попробуйте вывести агента за границы базы знаний. После неудачного ответа добавьте корректировку обычным языком.</p>
            <div class="ckm-sales-script-actions">
              <?php if($on): ?><a class="ckm-sales-btn ckm-sales-primary" href="<?php echo esc_url($sellerUrl); ?>" target="_blank" rel="noopener">Открыть тестовый диалог</a><?php elseif((string)$script['status']==='approved'): ?><form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'scripts','sales_script'=>(string)$script['id']])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="connect_seller"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><button class="ckm-sales-btn ckm-sales-primary" type="submit">Подключить для теста</button></form><?php else: ?><a class="ckm-sales-btn" href="<?php echo esc_url(self::url(['sales_view'=>'scripts','sales_script'=>(string)$script['id']])); ?>">Сначала утвердить скрипт</a><?php endif; ?>
            </div>
          </div>
          <div class="ckm-sales-card ckm-sales-agent-panel">
            <div class="ckm-sales-kicker">04 · КОРРЕКТИРОВКИ</div><h2>Обучение простым языком</h2>
            <form class="ckm-sales-script-form" method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id']])); ?>">
              <?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_add_correction"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>">
              <label>Что изменить в поведении<textarea name="correction_text" rows="4" placeholder="Например: если клиент спрашивает о внедрении, сначала уточни размер команды и только потом предлагай демо." required></textarea></label><button class="ckm-sales-btn" type="submit">Добавить корректировку</button>
            </form>
            <div class="ckm-sales-agent-list"><?php if(!$w['corrections']): ?><p class="ckm-sales-muted">Корректировок пока нет.</p><?php else: foreach(array_reverse($w['corrections']) as $fix):if(!is_array($fix))continue; ?>
              <article><div><strong><?php echo esc_html((string)($fix['text']??'')); ?></strong><small><?php echo esc_html((string)($fix['created_at']??'')); ?></small></div><form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id']])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_delete_correction"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><input type="hidden" name="correction_id" value="<?php echo esc_attr((string)($fix['id']??'')); ?>"><button class="ckm-sales-link-button" type="submit">Удалить</button></form></article>
            <?php endforeach; endif; ?></div>
          </div>
        </section>
        <section class="ckm-sales-card ckm-sales-agent-panel ckm-sales-agent-channels">
          <div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">КАНАЛЫ</div><h2>Назначьте агенту подключения партнёра</h2></div><a class="ckm-sales-btn" href="<?php echo esc_url(self::url(['sales_view'=>'integrations'])); ?>">Каналы и интеграции</a></div>
          <p class="ckm-sales-muted">Секреты больше не вводятся в карточке ИИ-продавца. Владелец площадки подключает Telegram, MAX, WhatsApp и телефонию один раз в разделе «Интеграции», а здесь выбирает готовый канал для конкретного агента.</p>
          <div class="ckm-sales-telegram-connect">
            <?php $telegramBinding=$integrationBindings['telegram']??null; ?>
            <?php if(is_array($telegramBinding)): ?>
              <div class="ckm-sales-channel-status"><strong>Telegram назначен агенту</strong><span><?php echo esc_html((string)($telegramBinding['account_username']!==''?'@'.ltrim((string)$telegramBinding['account_username'],'@'):(string)$telegramBinding['label'])); ?></span></div>
              <div class="ckm-sales-script-actions"><?php if((string)($telegram['deep_link']??'')!==''): ?><a class="ckm-sales-btn ckm-sales-primary" target="_blank" rel="noopener" href="<?php echo esc_url((string)$telegram['deep_link']); ?>">Открыть бота</a><?php endif; ?><?php if($canManageIntegrations): ?><form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id']])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_integration_unbind"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><input type="hidden" name="integration_id" value="<?php echo esc_attr((string)$telegramBinding['id']); ?>"><button class="ckm-sales-btn" type="submit">Отвязать от агента</button></form><?php endif; ?></div>
              <small class="ckm-sales-muted">Bot Token хранится только в tenant-хранилище. Отвязка от агента не удаляет Telegram-подключение партнёра.</small>
            <?php elseif($canManageIntegrations&&$availableTelegram&&$on): ?>
              <form class="ckm-sales-script-form" method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id']])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_integration_bind"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><label>Telegram партнёра<select name="integration_id" required><option value="">Выберите подключение</option><?php foreach($availableTelegram as $row): ?><option value="<?php echo esc_attr((string)$row['id']); ?>"><?php echo esc_html((string)$row['label'].((string)$row['account_username']!==''?' · @'.ltrim((string)$row['account_username'],'@'):'')); ?></option><?php endforeach; ?></select></label><button class="ckm-sales-btn ckm-sales-primary" type="submit">Подключить к этому агенту</button></form>
            <?php else: ?>
              <div class="ckm-sales-format-note"><strong>Telegram пока не назначен</strong><span><?php echo !$on?'Сначала подключите утверждённый скрипт к ИИ-продавцу.':($canManageIntegrations?'Сначала добавьте Telegram партнёра в разделе «Интеграции», затем вернитесь сюда и выберите его из списка.':'Подключение канала выполняет владелец партнёрской площадки.'); ?></span></div>
            <?php endif; ?>
          </div>
          <div class="ckm-sales-telegram-connect ckm-sales-max-connect">
            <?php $maxBinding=$integrationBindings['max']??null; ?>
            <?php if(is_array($maxBinding)): ?>
              <div class="ckm-sales-channel-status"><strong>MAX назначен агенту</strong><span><?php echo esc_html((string)($maxBinding['account_username']!==''?'@'.ltrim((string)$maxBinding['account_username'],'@'):(string)$maxBinding['label'])); ?></span></div>
              <div class="ckm-sales-script-actions"><?php if($canManageIntegrations): ?><form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id']])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_integration_unbind"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><input type="hidden" name="integration_id" value="<?php echo esc_attr((string)$maxBinding['id']); ?>"><button class="ckm-sales-btn" type="submit">Отвязать MAX от агента</button></form><?php endif; ?></div>
              <small class="ckm-sales-muted">API token MAX хранится только в tenant-хранилище. Входящие личные сообщения этого бота направляются данному ИИ-продавцу.</small>
            <?php elseif($canManageIntegrations&&$availableMax&&$on): ?>
              <form class="ckm-sales-script-form" method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id']])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_integration_bind"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><label>MAX партнёра<select name="integration_id" required><option value="">Выберите подключение</option><?php foreach($availableMax as $row): ?><option value="<?php echo esc_attr((string)$row['id']); ?>"><?php echo esc_html((string)$row['label'].((string)$row['account_username']!==''?' · @'.ltrim((string)$row['account_username'],'@'):'')); ?></option><?php endforeach; ?></select></label><button class="ckm-sales-btn ckm-sales-primary" type="submit">Подключить MAX к этому агенту</button></form>
            <?php else: ?>
              <div class="ckm-sales-format-note"><strong>MAX пока не назначен</strong><span><?php echo !$on?'Сначала подключите утверждённый скрипт к ИИ-продавцу.':($canManageIntegrations?'Сначала добавьте MAX партнёра в разделе «Интеграции», затем вернитесь сюда и выберите его из списка.':'Подключение канала выполняет владелец партнёрской площадки.'); ?></span></div>
            <?php endif; ?>
          </div>
          <div class="ckm-sales-telegram-connect ckm-sales-whatsapp-connect">
            <?php $whatsappBinding=$integrationBindings['whatsapp']??null; ?>
            <?php if(is_array($whatsappBinding)): ?>
              <div class="ckm-sales-channel-status"><strong>WhatsApp назначен агенту</strong><span><?php echo esc_html((string)($whatsappBinding['account_username']!==''?$whatsappBinding['account_username']:$whatsappBinding['label'])); ?></span></div>
              <div class="ckm-sales-script-actions"><?php if($canManageIntegrations): ?><form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id']])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_integration_unbind"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><input type="hidden" name="integration_id" value="<?php echo esc_attr((string)$whatsappBinding['id']); ?>"><button class="ckm-sales-btn" type="submit">Отвязать WhatsApp от агента</button></form><?php endif; ?></div>
              <small class="ckm-sales-muted">Access Token и App Secret хранятся только в tenant-хранилище. Входящие сообщения этого номера WhatsApp направляются данному ИИ-продавцу.</small>
            <?php elseif($canManageIntegrations&&$availableWhatsApp&&$on): ?>
              <form class="ckm-sales-script-form" method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id']])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_integration_bind"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><label>WhatsApp партнёра<select name="integration_id" required><option value="">Выберите подключение</option><?php foreach($availableWhatsApp as $row): ?><option value="<?php echo esc_attr((string)$row['id']); ?>"><?php echo esc_html((string)$row['label'].((string)$row['account_username']!==''?' · '.$row['account_username']:'')); ?></option><?php endforeach; ?></select></label><button class="ckm-sales-btn ckm-sales-primary" type="submit">Подключить WhatsApp к этому агенту</button></form>
            <?php else: ?>
              <div class="ckm-sales-format-note"><strong>WhatsApp пока не назначен</strong><span><?php echo !$on?'Сначала подключите утверждённый скрипт к ИИ-продавцу.':($canManageIntegrations?'Сначала добавьте WhatsApp партнёра в разделе «Интеграции», затем вернитесь сюда и выберите его из списка.':'Подключение канала выполняет владелец партнёрской площадки.'); ?></span></div>
            <?php endif; ?>
          </div>
          <div class="ckm-sales-telegram-connect ckm-sales-telephony-connect">
            <?php $sipBinding=$integrationBindings['sip']??null;$sipMeta=is_array($sipBinding)&&is_array($sipBinding['public_meta']??null)?$sipBinding['public_meta']:[];$directSip=is_array($sipBinding)&&(string)($sipMeta['adapter']??'')==='direct_sip';$phonePlatform=SalesPhoneGatewayService::platformStatus();$phoneReady=$directSip&&!empty($phonePlatform['configured'])&&!empty($phonePlatform['sergey_ready'])&&!empty($phonePlatform['worker_online']); ?>
            <?php if(is_array($sipBinding)): $sipMeta=is_array($sipBinding['public_meta']??null)?$sipBinding['public_meta']:[]; ?>
              <div class="ckm-sales-channel-status"><strong>Телефония назначена агенту</strong><span><?php echo esc_html((string)($sipBinding['account_name']!==''?$sipBinding['account_name']:$sipBinding['label'])); ?></span></div>
              <div class="ckm-sales-script-actions"><?php if($canManageIntegrations): ?><form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id']])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_integration_unbind"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><input type="hidden" name="integration_id" value="<?php echo esc_attr((string)$sipBinding['id']); ?>"><button class="ckm-sales-btn" type="submit">Отвязать телефонию от агента</button></form><?php endif; ?></div>
              <small class="ckm-sales-muted"><?php echo $phoneReady?'Телефонный канал готов: речь клиента распознаёт Deepgram Nova-3, ответ озвучивает Сергей через CKM Voice Gateway.':($directSip?(!empty($phonePlatform['sergey_ready'])?'SIP trunk сохранён; Сергей готов, но phone media worker пока офлайн.':'SIP trunk сохранён, но Сергей/Voice Gateway ещё не готов.'):'Для AI-голоса назначьте SIP trunk и запустите phone media worker.'); ?></small>
            <?php elseif($canManageIntegrations&&$availableSip&&$on): ?>
              <form class="ckm-sales-script-form" method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id']])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_integration_bind"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><label>Телефония партнёра<select name="integration_id" required><option value="">Выберите подключение</option><?php foreach($availableSip as $row): ?><option value="<?php echo esc_attr((string)$row['id']); ?>"><?php echo esc_html((string)$row['label'].((string)$row['account_name']!==''?' · '.$row['account_name']:'')); ?></option><?php endforeach; ?></select></label><button class="ckm-sales-btn ckm-sales-primary" type="submit">Назначить телефонию агенту</button></form>
            <?php else: ?>
              <div class="ckm-sales-format-note"><strong>Телефония пока не назначена</strong><span><?php echo !$on?'Сначала подключите утверждённый скрипт к ИИ-продавцу.':($canManageIntegrations?'Сначала подключите Direct SIP, MANGO, UIS или другого провайдера в разделе «Интеграции».':'Подключение телефонии выполняет владелец партнёрской площадки.'); ?></span></div>
            <?php endif; ?>
          </div>
          <div class="ckm-sales-channel-grid"><span class="is-ready"><b>Веб-чат</b><small>Готов</small></span><span class="is-ready"><b>Голос в браузере</b><small>Готов</small></span><span class="<?php echo $phoneReady?'is-ready':(is_array($sipBinding)?'is-ready':''); ?>"><b>PSTN / IP-телефония</b><small><?php echo $phoneReady?'Сергей · телефон + Inbox':(is_array($sipBinding)?($directSip?'SIP · ждёт media worker':'API подключён · нужен SIP trunk'):'Не назначена'); ?></small></span><span class="<?php echo is_array($telegramBinding)?'is-ready':''; ?>"><b>Telegram</b><small><?php echo is_array($telegramBinding)?esc_html('@'.ltrim((string)$telegramBinding['account_username'],'@')):'Не назначен'; ?></small></span><span class="<?php echo is_array($whatsappBinding)?'is-ready':''; ?>"><b>WhatsApp</b><small><?php echo is_array($whatsappBinding)?esc_html((string)($whatsappBinding['account_username']!==''?$whatsappBinding['account_username']:$whatsappBinding['label'])):'Не назначен'; ?></small></span><span class="<?php echo is_array($maxBinding)?'is-ready':''; ?>"><b>MAX</b><small><?php echo is_array($maxBinding)?esc_html((string)($maxBinding['account_username']!==''?'@'.ltrim((string)$maxBinding['account_username'],'@'):$maxBinding['label'])):'Не назначен'; ?></small></span></div>
        </section>
        <section class="ckm-sales-card ckm-sales-agent-panel ckm-sales-automations">
          <div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">СЦЕНАРИИ И АВТОДОЖИМЫ</div><h2>Реагируйте на паузу и стадию лида</h2></div><span class="ckm-sales-muted">Web — сразу · Telegram/MAX/WhatsApp — фоновая проверка примерно раз в минуту</span></div>
          <p class="ckm-sales-muted">Один сценарий может содержать цепочку до 8 последовательных шагов. Первый шаг ждёт выбранный триггер, каждый следующий — свою задержку после предыдущего. Если клиент отвечает в цепочке «Нет ответа клиента», оставшиеся автодожимы этой цепочки не выполняются. При перехвате человеком автоматизации ставятся на паузу.</p>
          <details class="ckm-sales-agent-details" open><summary>+ Создать сценарий</summary>
            <form class="ckm-sales-script-form ckm-sales-automation-form" method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id'],'sales_dialog'=>$dialogId])); ?>">
              <?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_scenario_add"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>">
              <label>Название<input name="scenario_name" maxlength="120" placeholder="Например: Дожим после паузы" required></label>
              <label>Триггер<select name="scenario_trigger"><?php foreach($scenarioTriggers as $k=>$label): ?><option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
              <label>Стадия для триггера<select name="scenario_trigger_stage"><?php foreach($pipeline as $k=>$label): ?><option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
              <label>Шаг 1 · задержка, минут<input type="number" min="0" max="10080" step="1" name="scenario_delay_minutes" value="15"></label>
              <label>Шаг 1 · действие<select name="scenario_action"><?php foreach($scenarioActions as $k=>$label): ?><option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
              <label>Шаг 1 · новая стадия<select name="scenario_target_stage"><?php foreach($pipeline as $k=>$label): ?><option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
              <label class="ckm-sales-automation-message">Шаг 1 · сообщение клиенту<textarea name="scenario_message" rows="3" placeholder="Например: Если вопрос ещё актуален, могу коротко показать два варианта следующего шага."></textarea></label>
              <button class="ckm-sales-btn ckm-sales-primary" type="submit">Добавить сценарий</button>
            </form>
          </details>
          <div class="ckm-sales-automation-list">
          <?php if(!$w['scenarios']): ?><p class="ckm-sales-muted">Сценариев пока нет. Добавьте первый автодожим или правило движения по воронке.</p><?php else: foreach($w['scenarios'] as $flow):if(!is_array($flow))continue;$triggerKey=(string)($flow['trigger']??'idle');$flowId=(string)($flow['id']??'');$enabled=!empty($flow['enabled']);$flowSteps=is_array($flow['steps']??null)?array_values($flow['steps']):[]; ?>
            <article class="ckm-sales-automation-item <?php echo $enabled?'is-enabled':'is-disabled'; ?>">
              <div class="ckm-sales-automation-main">
                <strong><?php echo esc_html((string)($flow['name']??'Сценарий')); ?></strong>
                <small><?php echo esc_html((string)($scenarioTriggers[$triggerKey]??$triggerKey)); ?><?php if($triggerKey==='stage'): ?>: <?php echo esc_html((string)($pipeline[(string)($flow['trigger_stage']??'new')]??'Новый')); ?><?php endif; ?> · <?php echo esc_html((string)count($flowSteps)); ?> шаг(а/ов)</small>
                <div class="ckm-sales-automation-steps">
                  <?php foreach($flowSteps as $stepIndex=>$step):if(!is_array($step))continue;$stepAction=(string)($step['action']??'message');$stepId=(string)($step['id']??''); ?>
                    <div class="ckm-sales-automation-step">
                      <span class="ckm-sales-automation-step-no"><?php echo (int)($stepIndex+1); ?></span>
                      <div><b><?php echo esc_html((string)($scenarioActions[$stepAction]??$stepAction)); ?></b><small>через <?php echo esc_html((string)(int)($step['delay_minutes']??0)); ?> мин после <?php echo $stepIndex===0?'триггера':'предыдущего шага'; ?><?php if($stepAction==='change_stage'): ?> · стадия: <?php echo esc_html((string)($pipeline[(string)($step['target_stage']??'qualified')]??'Квалификация')); ?><?php endif; ?></small><?php if((string)($step['message']??'')!==''): ?><p><?php echo esc_html((string)$step['message']); ?></p><?php endif; ?></div>
                      <?php if(count($flowSteps)>1): ?><form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id'],'sales_dialog'=>$dialogId])); ?>" onsubmit="return confirm('Удалить этот шаг?');"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_scenario_step_delete"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><input type="hidden" name="scenario_id" value="<?php echo esc_attr($flowId); ?>"><input type="hidden" name="scenario_step_id" value="<?php echo esc_attr($stepId); ?>"><button class="ckm-sales-link-button" type="submit">Удалить шаг</button></form><?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
                <?php if(count($flowSteps)<8): ?><details class="ckm-sales-agent-details ckm-sales-add-step"><summary>+ Добавить следующий шаг</summary>
                  <form class="ckm-sales-script-form ckm-sales-automation-step-form" method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id'],'sales_dialog'=>$dialogId])); ?>">
                    <?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_scenario_step_add"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><input type="hidden" name="scenario_id" value="<?php echo esc_attr($flowId); ?>">
                    <label>Пауза после предыдущего шага, минут<input type="number" min="0" max="10080" step="1" name="scenario_step_delay_minutes" value="1440"></label>
                    <label>Действие<select name="scenario_step_action"><?php foreach($scenarioActions as $k=>$label): ?><option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
                    <label>Новая стадия<select name="scenario_step_target_stage"><?php foreach($pipeline as $k=>$label): ?><option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
                    <label class="ckm-sales-automation-message">Сообщение клиенту<textarea name="scenario_step_message" rows="2" placeholder="Например: Напоминаю о нашем предложении. Если удобнее, подключу специалиста."></textarea></label>
                    <button class="ckm-sales-btn" type="submit">Добавить шаг</button>
                  </form>
                </details><?php endif; ?>
              </div>
              <div class="ckm-sales-automation-actions">
                <form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id'],'sales_dialog'=>$dialogId])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_scenario_toggle"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><input type="hidden" name="scenario_id" value="<?php echo esc_attr($flowId); ?>"><button class="ckm-sales-link-button" type="submit"><?php echo $enabled?'Выключить':'Включить'; ?></button></form>
                <?php if($dialogId!==''&&$selectedDialog&&(string)($selectedDialog['status']??'')!=='ended'): ?><form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id'],'sales_dialog'=>$dialogId])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_scenario_run"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><input type="hidden" name="dialog_id" value="<?php echo esc_attr($dialogId); ?>"><input type="hidden" name="scenario_id" value="<?php echo esc_attr($flowId); ?>"><button class="ckm-sales-link-button" type="submit">Выполнить следующий шаг</button></form><?php endif; ?>
                <form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id'],'sales_dialog'=>$dialogId])); ?>" onsubmit="return confirm('Удалить сценарий?');"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_scenario_delete"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><input type="hidden" name="scenario_id" value="<?php echo esc_attr($flowId); ?>"><button class="ckm-sales-link-button" type="submit">Удалить</button></form>
              </div>
            </article>
          <?php endforeach; endif; ?>
          </div>
        </section>
        <section class="ckm-sales-card ckm-sales-agent-panel ckm-sales-analytics">
          <div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">АНАЛИТИКА ЦЕЛИ</div><h2>Каждый диалог измерен</h2></div>
            <form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id']])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_export_analytics"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><button class="ckm-sales-btn" type="submit">Экспорт CSV</button></form>
          </div>
          <div class="ckm-sales-goal-banner"><small>Основная цель агента</small><strong><?php echo esc_html((string)$w['primary_goal']); ?></strong></div>
          <div class="ckm-sales-analytics-grid">
            <div><small>Успешный</small><strong><?php echo esc_html((string)($analytics['successful']??0)); ?></strong></div>
            <div><small>Неуспешный</small><strong><?php echo esc_html((string)($analytics['unsuccessful']??0)); ?></strong></div>
            <div><small>Активный</small><strong><?php echo esc_html((string)($analytics['active']??0)); ?></strong></div>
            <div><small>Застопорен</small><strong><?php echo esc_html((string)($analytics['stalled']??0)); ?></strong></div>
            <div><small>Сообщений / диалог</small><strong><?php echo esc_html((string)($analytics['avg_messages']??0)); ?></strong></div>
            <div><small>Ответ ИИ</small><strong><?php echo ((int)($analytics['ai_messages']??0)>0)?esc_html((string)($analytics['avg_ai_response_seconds']??0)).' с':'—'; ?></strong></div>
            <div><small>Ответ оператора</small><strong><?php echo ((int)($analytics['operator_messages']??0)>0)?esc_html((string)($analytics['avg_operator_response_seconds']??0)).' с':'—'; ?></strong></div>
            <div><small>Передано человеку</small><strong><?php echo esc_html((string)($analytics['handoffs']??0)); ?></strong></div>
          </div>
          <p class="ckm-sales-muted ckm-sales-analytics-note">Результат определяется по воронке: «Успех» = цель достигнута, «Отказ» или завершённый без успеха = неуспешный. Активный диалог без активности более 24 часов отмечается как «Застопорен».</p>
        </section>
        <section class="ckm-sales-card ckm-sales-agent-panel ckm-sales-inbox">
          <div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">INBOX / CRM</div><h2>Лиды и разговоры</h2></div><span class="ckm-sales-muted">ИИ и человек работают в одном диалоге</span></div>
          <?php if(!$dialogs): ?><p class="ckm-sales-muted">Диалогов пока нет. Откройте тестовый или публичный разговор — новый лид появится здесь автоматически.</p><?php else: ?>
          <div class="ckm-sales-inbox-layout">
            <div class="ckm-sales-inbox-list">
              <?php foreach($dialogs as $d):$msgs=(array)($d['messages']??[]);$last='';for($i=count($msgs)-1;$i>=0;$i--){if(is_array($msgs[$i])&&(string)($msgs[$i]['role']??'')==='user'){$last=(string)($msgs[$i]['content']??'');break;}}$sid=(string)($d['session_id']??'');$stageKey=(string)($d['pipeline_stage']??'new');$lead=(string)($d['lead_name']??''); ?>
              <a class="ckm-sales-inbox-item <?php echo $sid===$dialogId?'is-active':''; ?>" href="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id'],'sales_dialog'=>$sid])); ?>">
                <span><strong><?php echo esc_html($lead!==''?$lead:'Новый лид'); ?></strong><small><?php $goalKey=(string)($d['goal_status']??'active'); echo esc_html((string)($pipeline[$stageKey]??'Новый')); ?> · <?php echo esc_html((string)($outcomeLabels[$goalKey]??'Активный')); ?> · <?php $dialogChannel=(string)($d['channel']??'web');echo $dialogChannel==='telegram'?'Telegram':($dialogChannel==='max'?'MAX':($dialogChannel==='whatsapp'?'WhatsApp':($dialogChannel==='phone'?'Телефон':'Web'))); ?> · <?php echo ((string)($d['control_mode']??'ai')==='human')?'человек':'ИИ'; ?></small></span>
                <p><?php echo esc_html(wp_html_excerpt($last!==''?$last:'Диалог начат.',110,'…')); ?></p>
              </a>
              <?php endforeach; ?>
            </div>
            <div class="ckm-sales-inbox-detail">
            <?php if(!$selectedDialog): ?><div class="ckm-sales-inbox-empty"><strong>Выберите диалог</strong><p>Справа откроется карточка лида, полная переписка и управление «ИИ / человек».</p></div>
            <?php else:$mode=(string)($selectedDialog['control_mode']??'ai');$stageKey=(string)($selectedDialog['pipeline_stage']??'new');$isPhone=(string)($selectedDialog['channel']??'')==='phone';$phoneState=$isPhone?SalesPhoneGatewayService::dialogStatus((string)$script['id'],$dialogId):['phone'=>false]; ?>
              <div class="ckm-sales-inbox-toolbar"><div><strong><?php echo esc_html((string)($selectedDialog['lead_name']??'')?:'Новый лид'); ?></strong><small><?php $selectedOutcome=(string)($selectedDialog['goal_status']??'active'); echo esc_html((string)($selectedDialog['lead_contact']??'')?:'Контакт не указан'); ?> · <?php echo esc_html((string)($outcomeLabels[$selectedOutcome]??'Активный')); ?></small></div><span class="ckm-sales-status <?php echo $mode==='human'?'is-approved':''; ?>"><?php echo $mode==='human'?'Ведёт человек':'Ведёт ИИ'; ?></span></div>
              <form class="ckm-sales-lead-form" method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id'],'sales_dialog'=>$dialogId])); ?>">
                <?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_lead_update"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><input type="hidden" name="dialog_id" value="<?php echo esc_attr($dialogId); ?>">
                <label>Имя / компания<input name="lead_name" value="<?php echo esc_attr((string)($selectedDialog['lead_name']??'')); ?>" placeholder="Например: Анна · ООО Альфа"></label>
                <label>Контакт<input name="lead_contact" value="<?php echo esc_attr((string)($selectedDialog['lead_contact']??'')); ?>" placeholder="Телефон, email, @username"></label>
                <label>Стадия<select name="pipeline_stage"><?php foreach($pipeline as $k=>$label): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($stageKey,$k); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
                <button class="ckm-sales-btn" type="submit">Сохранить лид</button>
              </form>
              <?php if((string)($selectedDialog['status']??'')!=='ended'): ?><div class="ckm-sales-human-control">
                <?php if($isPhone): ?>
                  <?php if(!empty($phoneState['can_transfer'])): ?><form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id'],'sales_dialog'=>$dialogId])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_phone_refer"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><input type="hidden" name="dialog_id" value="<?php echo esc_attr($dialogId); ?>"><button class="ckm-sales-btn ckm-sales-primary" type="submit">Перевести звонок оператору</button></form><?php else: ?><span class="ckm-sales-muted">В SIP-подключении не задан адрес перевода оператору.</span><?php endif; ?>
                  <form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id'],'sales_dialog'=>$dialogId])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_phone_hangup"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><input type="hidden" name="dialog_id" value="<?php echo esc_attr($dialogId); ?>"><button class="ckm-sales-btn" type="submit">Завершить звонок</button></form>
                <?php elseif($mode==='human'): ?>
                  <form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id'],'sales_dialog'=>$dialogId])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_return_ai"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><input type="hidden" name="dialog_id" value="<?php echo esc_attr($dialogId); ?>"><button class="ckm-sales-btn" type="submit">Вернуть управление ИИ</button></form>
                <?php else: ?>
                  <form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id'],'sales_dialog'=>$dialogId])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_takeover"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><input type="hidden" name="dialog_id" value="<?php echo esc_attr($dialogId); ?>"><button class="ckm-sales-btn ckm-sales-primary" type="submit">Перехватить разговор человеком</button></form>
                <?php endif; ?>
              </div><?php endif; ?>
              <div class="ckm-sales-inbox-thread"><?php foreach((array)($selectedDialog['messages']??[]) as $m):if(!is_array($m))continue;$role=(string)($m['role']??'');$label=$role==='user'?'Клиент':($role==='operator'?'Специалист':($role==='system'?'Система':'ИИ-продавец')); ?><div class="ckm-sales-inbox-message is-<?php echo esc_attr($role); ?>"><small><?php echo esc_html($label); ?></small><p><?php echo esc_html((string)($m['content']??'')); ?></p></div><?php endforeach; ?></div>
              <?php if(!$isPhone&&$mode==='human'&&(string)($selectedDialog['status']??'')!=='ended'): ?><form class="ckm-sales-operator-reply" method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id'],'sales_dialog'=>$dialogId])); ?>">
                <?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="seller_operator_message"><input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>"><input type="hidden" name="dialog_id" value="<?php echo esc_attr($dialogId); ?>"><textarea name="operator_message" rows="3" placeholder="Ответ специалиста клиенту…" required></textarea><button class="ckm-sales-btn ckm-sales-primary" type="submit">Отправить от человека</button>
              </form><?php endif; ?>
            <?php endif; ?>
            </div>
          </div><?php endif; ?>
        </section>
        <section class="ckm-sales-card ckm-sales-agent-panel ckm-sales-practice-feedback">
          <div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">ПРАКТИКА → ПОЛИГОН</div><h2>Трудные реальные ситуации становятся тренировками</h2></div><span class="ckm-sales-muted"><?php echo (int)($practice['pending']??0); ?> ждут разбора · <?php echo (int)($practice['created']??0); ?> уже превращены в кейсы</span></div>
          <p class="ckm-sales-muted">CKM отбирает неуспешные, застопоренные и переданные человеку диалоги. В тренировочный сценарий не копируются имя, контакт и исходные реплики клиента: используется только обезличенный паттерн ситуации и навык, который нужно отработать.</p>
          <?php if(empty($practice['candidates'])): ?>
            <div class="ckm-sales-format-note"><strong>Трудных ситуаций пока нет</strong><span>Когда реальный диалог завершится отказом, застопорится или потребует передачи человеку, он появится здесь как кандидат для обучения.</span></div>
          <?php else: ?><div class="ckm-sales-practice-grid">
            <?php foreach((array)$practice['candidates'] as $row):$case=is_array($row['case']??null)?$row['case']:null;$goal=(string)($row['goal_status']??'active');$goalLabel=$goal==='unsuccessful'?'Неуспешный':($goal==='stalled'?'Застопорен':(!empty($row['handoff'])?'Передан человеку':'Требует разбора'));$channel=(string)($row['channel']??'web');$channelLabel=$channel==='telegram'?'Telegram':($channel==='max'?'MAX':($channel==='whatsapp'?'WhatsApp':($channel==='phone'?'Телефон':'Web'))); ?>
              <article class="ckm-sales-practice-card">
                <div class="ckm-sales-card-top"><div><span class="ckm-sales-pill"><?php echo esc_html($goalLabel); ?></span><span class="ckm-sales-pill"><?php echo esc_html($channelLabel); ?></span></div><span class="ckm-sales-status <?php echo $case?'is-approved':''; ?>"><?php echo $case?'Кейс создан':'Нужен кейс'; ?></span></div>
                <h3><?php echo esc_html((string)$row['focus_title']); ?></h3>
                <p><?php echo esc_html((string)$row['excerpt']); ?></p>
                <div class="ckm-sales-meta"><span><?php echo esc_html((string)$row['last_at']); ?></span><span>Приоритет: <?php echo (int)$row['priority']; ?></span><?php if($case): ?><span>scenario #<?php echo (int)($case['scenario_id']??0); ?></span><?php endif; ?></div>
                <div class="ckm-sales-practice-actions">
                  <?php if($case&&(int)($case['scenario_id']??0)>0): ?><a class="ckm-sales-btn" href="<?php echo esc_url(self::url(['sales_custom_scenario'=>(int)$case['scenario_id'],'sales_format'=>'training','sales_methodology'=>(string)$script['id']])); ?>">Открыть кейс</a><?php endif; ?>
                  <form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id']])); ?>">
                    <?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?>
                    <input type="hidden" name="ckm_sales_script_action" value="seller_practice_case">
                    <input type="hidden" name="script_id" value="<?php echo esc_attr((string)$script['id']); ?>">
                    <input type="hidden" name="dialog_id" value="<?php echo esc_attr((string)$row['session_id']); ?>">
                    <?php if($practiceEmployees): ?><select name="participant_key"><option value="">Только создать кейс</option><?php foreach($practiceEmployees as $employee): ?><option value="<?php echo esc_attr((string)$employee['participant_key']); ?>"><?php echo esc_html((string)$employee['label']); ?></option><?php endforeach; ?></select><?php endif; ?>
                    <button class="ckm-sales-btn ckm-sales-primary" type="submit"><?php echo $case?'Назначить сотруднику':'Создать'.($practiceEmployees?' и при необходимости назначить':' кейс'); ?></button>
                  </form>
                </div>
              </article>
            <?php endforeach; ?>
          </div><?php endif; ?>
        </section>
        <?php return (string)ob_get_clean();
    }

    private static function renderIntegrations(): string {
        $notice=self::takeScriptNotice();$canManage=SalesPartnerIntegrationService::canManageSecrets();$phonePlatform=SalesPhoneGatewayService::platformStatus();$rows=SalesPartnerIntegrationService::listCurrentTenant();$providers=SalesPartnerIntegrationService::providers();$grouped=[];foreach($rows as $row){$grouped[(string)$row['provider']][]=$row;}
        ob_start(); ?>
        <?php if($notice): ?><div class="ckm-sales-card ckm-sales-workspace-notice <?php echo !empty($notice['error'])?'is-error':''; ?>"><?php echo esc_html((string)($notice['message']??'')); ?></div><?php endif; ?>
        <section class="ckm-sales-card ckm-sales-script-hero ckm-sales-integration-hero">
          <div class="ckm-sales-kicker">КАНАЛЫ И ИНТЕГРАЦИИ · <?php echo esc_html(SalesPartnerIntegrationService::tenantLabel()); ?></div><h1>Подключения принадлежат партнёру, а не платформе</h1>
          <p class="ckm-sales-lead">Владелец площадки один раз вводит свои Telegram, MAX, WhatsApp или SIP-данные. Секреты сохраняются зашифрованно и привязаны к текущему <code>tenant_id</code>. Затем готовое подключение назначается конкретному ИИ-продавцу — Bot Token и SIP-пароль в карточку агента больше не вводятся.</p>
          <div class="ckm-sales-script-flow"><span><b>1</b>Партнёр подключает канал</span><span>→</span><span><b>2</b>CKM хранит секрет</span><span>→</span><span><b>3</b>Канал назначается агенту</span><span>→</span><span><b>4</b>Лиды идут только в эту площадку</span></div>
        </section>
        <?php if(current_user_can('manage_options')): ?><section class="ckm-sales-card ckm-sales-agent-panel">
          <div class="ckm-sales-kicker">ТЕЛЕФОННЫЙ МЕДИАШЛЮЗ ПЛАТФОРМЫ · <?php echo !empty($phonePlatform['configured'])?(!empty($phonePlatform['worker_online'])?'РАБОТАЕТ':'НАСТРОЕН'):'НАСТРОЙКА'; ?></div><h2>Телефонный ИИ-продавец · Сергей</h2>
          <p class="ckm-sales-muted">Телефон использует тот же голос Сергея, что уже настроен в CKM Voice Gateway через Cartesia. Речь клиента распознаёт Deepgram Nova-3 (русский), текст обрабатывает обычная логика ИИ-продавца, а ответ снова озвучивает Сергей. OpenAI Project ID, GPT-Live voice и webhook для этой схемы не нужны.</p>
          <div class="ckm-sales-meta"><span>Голос: <?php echo esc_html((string)$phonePlatform['voice_label']); ?></span><span>Распознавание: <?php echo esc_html((string)$phonePlatform['stt_label']); ?></span><span>Сергей: <?php echo !empty($phonePlatform['sergey_ready'])?'готов':'не готов'; ?></span><span>Phone media worker: <?php echo !empty($phonePlatform['worker_online'])?'онлайн':(!empty($phonePlatform['configured'])?'офлайн':'не настроен'); ?></span></div>
          <p class="ckm-sales-muted"><strong>Важно:</strong> ключ Deepgram хранится только в <code>.env</code> постоянного phone media worker и не сохраняется в WordPress. Cartesia API key и Voice ID Сергея остаются на существующем CKM Voice Gateway.</p>
          <?php if(!empty($phonePlatform['configured'])): ?><p class="ckm-sales-muted"><strong>Heartbeat:</strong> <code><?php echo esc_html((string)$phonePlatform['heartbeat_url']); ?></code><br><strong>Открытие звонка:</strong> <code><?php echo esc_html((string)$phonePlatform['open_url']); ?></code><?php if(!empty($phonePlatform['worker_last_seen'])): ?><br><strong>Последний heartbeat:</strong> <?php echo esc_html((string)$phonePlatform['worker_last_seen']); ?><?php endif; ?></p><?php endif; ?>
          <form class="ckm-sales-script-form" method="post" action="<?php echo esc_url(self::url(['sales_view'=>'integrations'])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="integration_phone_gateway_save"><label>Phone gateway secret<input type="password" name="phone_gateway_secret" autocomplete="new-password" placeholder="24–190 случайных символов · пусто = сохранить текущий"></label><button class="ckm-sales-btn ckm-sales-primary" type="submit">Сохранить медиашлюз</button></form>
          <?php if(!empty($phonePlatform['configured'])): ?><form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'integrations'])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="integration_phone_gateway_test"><button class="ckm-sales-btn" type="submit">Проверить Сергея</button></form><?php endif; ?>
        </section><?php endif; ?>
        <?php if(!$canManage): ?><div class="ckm-sales-card ckm-sales-workspace-notice"><strong>Режим просмотра.</strong> Подключения и их статусы видны организатору, но секреты может добавлять, менять и удалять только владелец партнёрской площадки.</div><?php endif; ?>
        <section class="ckm-sales-section"><div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">ПОДКЛЮЧЕНИЯ ПЛОЩАДКИ</div><h2>Сохранённые каналы</h2></div><a class="ckm-sales-btn" href="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers'])); ?>">К ИИ-продавцам</a></div>
          <?php if(!$rows): ?><div class="ckm-sales-card ckm-sales-agent-panel"><p>Подключений пока нет. Владелец площадки может добавить первый канал ниже.</p></div><?php else: ?><div class="ckm-sales-integration-list"><?php foreach($rows as $row):$provider=(string)$row['provider'];$assigned=(string)($row['assigned_script_id']??'')!=='';$assignedScript=$assigned&&((int)($row['assigned_user_id']??0)===get_current_user_id())?SalesScriptService::find((string)$row['assigned_script_id']):null; ?>
            <article class="ckm-sales-card ckm-sales-integration-item">
              <div class="ckm-sales-card-top"><div><span class="ckm-sales-pill"><?php echo esc_html((string)($row['provider_label']??$provider)); ?></span><h3><?php echo esc_html((string)$row['label']); ?></h3></div><span class="ckm-sales-status <?php echo (string)$row['status']==='connected'?'is-approved':''; ?>"><?php echo esc_html((string)$row['status']==='connected'?'Подключено':'Данные сохранены'); ?></span></div>
              <p class="ckm-sales-muted"><?php echo esc_html((string)$row['credentials_hint']); ?><?php if((string)$row['account_username']!==''): ?> · <?php echo esc_html(in_array($provider,['telegram','max'],true)?'@'.ltrim((string)$row['account_username'],'@'):(string)$row['account_username']); ?><?php endif; ?></p>
              <div class="ckm-sales-meta"><span><?php echo $assigned?'Назначено агенту':'Свободно для назначения'; ?></span><?php if($assigned): ?><span><?php echo esc_html((string)($assignedScript['title']??'ИИ-продавец')); ?></span><?php endif; ?><?php if((string)$row['last_checked_at']!==''): ?><span>Проверено: <?php echo esc_html((string)$row['last_checked_at']); ?></span><?php endif; ?></div>
              <?php if($canManage): ?><div class="ckm-sales-card-actions">
                <?php if(in_array($provider,['telegram','max','whatsapp','sip'],true)): $testAction=$provider==='telegram'?'integration_telegram_test':($provider==='max'?'integration_max_test':($provider==='whatsapp'?'integration_whatsapp_test':'integration_telephony_test')); ?><form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'integrations'])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="<?php echo esc_attr($testAction); ?>"><input type="hidden" name="integration_id" value="<?php echo esc_attr((string)$row['id']); ?>"><button class="ckm-sales-btn" type="submit">Проверить</button></form><?php endif; ?>
                <form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'integrations'])); ?>" onsubmit="return confirm('Удалить подключение и зашифрованные данные?');"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="integration_delete"><input type="hidden" name="integration_id" value="<?php echo esc_attr((string)$row['id']); ?>"><button class="ckm-sales-btn" type="submit">Удалить подключение</button></form>
              </div><?php endif; ?>
            </article>
          <?php endforeach; ?></div><?php endif; ?>
        </section>
        <?php if($canManage): ?><section class="ckm-sales-section"><div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">ДОБАВИТЬ КАНАЛ</div><h2>Данные вашего бизнеса</h2></div></div>
          <div class="ckm-sales-integration-grid">
            <article class="ckm-sales-card ckm-sales-agent-panel"><div class="ckm-sales-kicker">TELEGRAM · РАБОТАЕТ</div><h3>Telegram Bot API</h3><p class="ckm-sales-muted">Создайте бота через @BotFather. CKM проверит Bot Token через <code>getMe</code> и сам зарегистрирует HTTPS webhook.</p><form class="ckm-sales-script-form" method="post" action="<?php echo esc_url(self::url(['sales_view'=>'integrations'])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="integration_telegram_connect"><label>Название подключения<input name="integration_label" value="Telegram продаж" maxlength="120"></label><label>Bot Token от BotFather<input type="password" name="telegram_bot_token" autocomplete="new-password" placeholder="123456789:AA..." required></label><button class="ckm-sales-btn ckm-sales-primary" type="submit">Подключить Telegram</button></form></article>
            <article class="ckm-sales-card ckm-sales-agent-panel"><div class="ckm-sales-kicker">MAX · РАБОТАЕТ</div><h3>MAX Bot API</h3><p class="ckm-sales-muted">Вставьте API token своего MAX-бота. CKM проверит его через <code>/me</code>, определит ID и имя бота и зарегистрирует HTTPS webhook через <code>/subscriptions</code>.</p><form class="ckm-sales-script-form" method="post" action="<?php echo esc_url(self::url(['sales_view'=>'integrations'])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="integration_max_connect"><label>Название подключения<input name="integration_label" value="MAX продаж" maxlength="120"></label><label>API token MAX<input type="password" name="max_api_token" autocomplete="new-password" placeholder="Токен бота из MAX для бизнеса" required></label><button class="ckm-sales-btn ckm-sales-primary" type="submit">Подключить MAX</button></form></article>
            <article class="ckm-sales-card ckm-sales-agent-panel"><div class="ckm-sales-kicker">WHATSAPP · РАБОТАЕТ</div><h3>WhatsApp Cloud API</h3><p class="ckm-sales-muted">Подключите номер из WhatsApp Business Platform. CKM проверит Phone Number ID, подпишет приложение на WABA и настроит отдельный HTTPS callback этой площадки. Используется Graph API <code>v26.0</code>.</p><form class="ckm-sales-script-form" method="post" action="<?php echo esc_url(self::url(['sales_view'=>'integrations'])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="integration_save_whatsapp"><label>Название подключения<input name="integration_label" value="WhatsApp продаж" maxlength="120"></label><label>Phone Number ID<input name="wa_phone_number_id" maxlength="32" inputmode="numeric" required></label><label>WhatsApp Business Account ID<input name="wa_business_account_id" maxlength="32" inputmode="numeric" required></label><label>Access Token<input type="password" name="wa_access_token" autocomplete="new-password" required></label><label>App Secret приложения Meta<input type="password" name="wa_app_secret" autocomplete="new-password" required></label><button class="ckm-sales-btn ckm-sales-primary" type="submit">Подключить WhatsApp</button></form></article>
            <article class="ckm-sales-card ckm-sales-agent-panel"><div class="ckm-sales-kicker">IP-ТЕЛЕФОНИЯ · ГОЛОС СЕРГЕЯ</div><h3>SIP trunk → CKM Media Gateway → Сергей</h3><p class="ckm-sales-muted">SIP/PSTN завершается на телефонном шлюзе (рекомендуется Asterisk). Аудио передаётся в AudioSocket: русский STT — Deepgram Nova-3, логика — ваш ИИ-продавец, озвучивание — существующий Cartesia-голос Сергей через CKM Voice Gateway. WordPress аудио не проксирует и не хранит.</p><form class="ckm-sales-script-form" method="post" action="<?php echo esc_url(self::url(['sales_view'=>'integrations'])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="integration_telephony_connect"><input type="hidden" name="telephony_adapter" value="direct_sip"><label>Название подключения<input name="integration_label" value="Телефонный Сергей" maxlength="120"></label><label>Провайдер<input name="direct_sip_provider_name" placeholder="Название оператора" maxlength="120" required></label><label>SIP trunk URL<input name="direct_sip_provider_url" placeholder="sips:sip.provider.ru:5061" maxlength="240" required></label><label>SIP login<input name="direct_sip_username" maxlength="256" required></label><label>SIP password<input type="password" name="direct_sip_password" autocomplete="new-password" required></label><label>Caller ID<input name="direct_sip_caller_id" placeholder="+74951234567" maxlength="24" required></label><label>Входящий DID<input name="direct_sip_inbound_did" placeholder="+74951234567 · если пусто, используется Caller ID" maxlength="24"></label><label>Перевод живому оператору<input name="direct_sip_handoff_target" placeholder="tel:+74951234567 или sip:100@pbx.example.ru" maxlength="240"></label><button class="ckm-sales-btn ckm-sales-primary" type="submit">Сохранить SIP trunk</button></form></article>
            <article class="ckm-sales-card ckm-sales-agent-panel"><div class="ckm-sales-kicker">IP-ТЕЛЕФОНИЯ · АДАПТЕРЫ ГОТОВЫ</div><h3>MANGO OFFICE</h3><p class="ckm-sales-muted">CKM проверяет API ВАТС через чтение сотрудника и сохраняет ключи только в tenant-хранилище. Это подключает управление звонками. Для голоса Сергея SIP/PSTN должен быть маршрутизирован через CKM phone media worker (Asterisk AudioSocket).</p><form class="ckm-sales-script-form" method="post" action="<?php echo esc_url(self::url(['sales_view'=>'integrations'])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="integration_telephony_connect"><input type="hidden" name="telephony_adapter" value="mango"><label>Название подключения<input name="integration_label" value="MANGO OFFICE продаж" maxlength="120"></label><label>API key ВАТС<input type="password" name="mango_api_key" autocomplete="new-password" required></label><label>API salt / ключ подписи<input type="password" name="mango_api_salt" autocomplete="new-password" required></label><label>Внутренний номер сотрудника / SIP-точки<input name="mango_extension" placeholder="101" maxlength="32" required></label><label>Исходящий номер / Caller ID<input name="mango_caller_id" placeholder="74951234567" maxlength="24"></label><button class="ckm-sales-btn ckm-sales-primary" type="submit">Подключить MANGO</button></form></article>
            <article class="ckm-sales-card ckm-sales-agent-panel"><div class="ckm-sales-kicker">IP-ТЕЛЕФОНИЯ · АДАПТЕРЫ ГОТОВЫ</div><h3>UIS / CoMagic</h3><p class="ckm-sales-muted">Используется Call API v4.0. Перед подключением добавьте IP вашего сайта в белый список UIS и выдайте пользователю доступ к Call API.</p><form class="ckm-sales-script-form" method="post" action="<?php echo esc_url(self::url(['sales_view'=>'integrations'])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="integration_telephony_connect"><input type="hidden" name="telephony_adapter" value="uis"><label>Название подключения<input name="integration_label" value="UIS продаж" maxlength="120"></label><label>Логин UIS<input name="uis_login" maxlength="190" required></label><label>Пароль UIS<input type="password" name="uis_password" autocomplete="new-password" required></label><label>Виртуальный номер<input name="uis_virtual_number" placeholder="74951234567" maxlength="24" required></label><button class="ckm-sales-btn ckm-sales-primary" type="submit">Подключить UIS</button></form></article>
            <article class="ckm-sales-card ckm-sales-agent-panel"><div class="ckm-sales-kicker">IP-ТЕЛЕФОНИЯ · ДРУГОЙ ПРОВАЙДЕР</div><h3>Свой SIP / API</h3><p class="ckm-sales-muted">Для другого оператора безопасно сохраняются его HTTPS API/SIP-данные. Сам адаптер вызовов добавляется отдельно под документацию выбранного провайдера.</p><form class="ckm-sales-script-form" method="post" action="<?php echo esc_url(self::url(['sales_view'=>'integrations'])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?><input type="hidden" name="ckm_sales_script_action" value="integration_telephony_connect"><input type="hidden" name="telephony_adapter" value="custom"><label>Название подключения<input name="integration_label" value="Телефония продаж" maxlength="120"></label><label>Провайдер<input name="sip_provider" placeholder="Билайн, МегаФон, другой оператор" maxlength="120" required></label><label>HTTPS API / SIP gateway URL<input name="sip_server" placeholder="https://api.example.ru" maxlength="190" required></label><label>Логин / ID<input name="sip_login" maxlength="120" required></label><label>Пароль / API token<input type="password" name="sip_password" autocomplete="new-password" required></label><label>Исходящий номер / Caller ID<input name="sip_caller_id" maxlength="80"></label><button class="ckm-sales-btn" type="submit">Сохранить другой провайдер</button></form></article>
          </div>
          <div class="ckm-sales-format-note"><strong>Как хранятся секреты</strong><span>Токены и пароли зашифрованы AES-256-GCM и привязаны к текущему tenant_id. После сохранения они не выводятся обратно в HTML; интерфейс показывает только безопасную маску и статус подключения.</span></div>
        </section><?php endif; ?>
        <?php return (string)ob_get_clean();
    }

    private static function renderAiSellers(): string {
        $agentId=sanitize_text_field((string)($_GET['sales_agent']??''));if($agentId!==''){$script=SalesScriptService::find($agentId);if(!$script)return '<section class="ckm-sales-card ckm-sales-gate"><h2>ИИ-продавец не найден</h2></section>';return self::renderAiSellerWorkspace($script);}
        $notice=self::takeScriptNotice();$scripts=SalesScriptService::all();$enabled=array_values(array_filter($scripts,static fn(array $s): bool=>!empty($s['ai_seller_enabled'])));
        $knowledgeCount=0;$telegramCount=0;$maxCount=0;$whatsappCount=0;foreach($scripts as $s){$knowledgeCount+=count((array)($s['ai_seller_knowledge']??[]));if(!empty(SalesTelegramService::statusForScript($s)['connected']))$telegramCount++;if(!empty(SalesMaxService::statusForScript($s)['connected']))$maxCount++;if(!empty(SalesWhatsAppService::statusForScript($s)['connected']))$whatsappCount++;}
        ob_start(); ?>
        <?php if($notice): ?><div class="ckm-sales-card ckm-sales-workspace-notice <?php echo !empty($notice['error'])?'is-error':''; ?>"><?php echo esc_html((string)($notice['message']??'')); ?></div><?php endif; ?>
        <section class="ckm-sales-card ckm-sales-script-hero ckm-sales-agent-hero">
          <div class="ckm-sales-kicker">МУЛЬТИКАНАЛЬНЫЙ ИИ-ПРОДАВЕЦ</div><h1>От скрипта до работающего ИИ-продавца</h1>
          <p class="ckm-sales-lead">Один агент использует утверждённый скрипт, собственную базу знаний и корректировки поведения. Сначала протестируйте его как клиент, затем передавайте реальную ссылку.</p>
          <div class="ckm-sales-script-flow"><span><b>1</b>Собрать агента</span><span>→</span><span><b>2</b>Добавить знания</span><span>→</span><span><b>3</b>Обучить диалогами</span><span>→</span><span><b>4</b>Подключить канал</span></div>
        </section>
        <section class="ckm-sales-agent-metrics"><div class="ckm-sales-card"><small>Агенты</small><strong><?php echo esc_html((string)count($scripts)); ?></strong></div><div class="ckm-sales-card"><small>Активны</small><strong><?php echo esc_html((string)count($enabled)); ?></strong></div><div class="ckm-sales-card"><small>Источники знаний</small><strong><?php echo esc_html((string)$knowledgeCount); ?></strong></div><div class="ckm-sales-card"><small>Рабочие каналы</small><strong><?php $channels=['Web','Voice'];if($telegramCount>0)$channels[]='Telegram';if($maxCount>0)$channels[]='MAX';if($whatsappCount>0)$channels[]='WhatsApp';echo esc_html(implode(' + ',$channels)); ?></strong></div></section>
        <section class="ckm-sales-section"><div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">АГЕНТЫ</div><h2><?php echo $scripts?'Настройте и запустите':'Сначала создайте скрипт'; ?></h2></div><div class="ckm-sales-card-actions"><a class="ckm-sales-btn" href="<?php echo esc_url(self::url(['sales_view'=>'integrations'])); ?>">Каналы и интеграции</a><a class="ckm-sales-btn" href="<?php echo esc_url(self::url(['sales_view'=>'scripts'])); ?>">+ Новый скрипт</a></div></div>
          <?php if(!$scripts): ?><div class="ckm-sales-card ckm-sales-agent-panel"><p>Создайте адаптивный скрипт — он станет ядром будущего ИИ-продавца.</p><a class="ckm-sales-btn ckm-sales-primary" href="<?php echo esc_url(self::url(['sales_view'=>'scripts'])); ?>">Создать скрипт</a></div>
          <?php else: ?><div class="ckm-sales-scenario-grid"><?php foreach($scripts as $script):$on=!empty($script['ai_seller_enabled']);$w=SalesAiSellerWorkspaceService::workspace($script);$a=SalesAiSellerWorkspaceService::analytics((string)$script['id']); ?>
            <article class="ckm-sales-card ckm-sales-scenario-card"><div class="ckm-sales-card-top"><span class="ckm-sales-pill"><?php echo esc_html((string)($w['name']??'ИИ-продавец')); ?></span><span class="ckm-sales-status <?php echo $on?'is-approved':''; ?>"><?php echo $on?'Активен':'Настройка'; ?></span></div><h3><?php echo esc_html((string)$script['title']); ?></h3><p><?php echo esc_html((string)$script['goal']); ?></p><div class="ckm-sales-meta"><span><?php echo esc_html((string)count((array)$w['knowledge'])); ?> источников</span><span><?php echo esc_html((string)$a['dialogs']); ?> диалогов</span></div><div class="ckm-sales-card-actions"><a class="ckm-sales-btn ckm-sales-primary" href="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers','sales_agent'=>(string)$script['id']])); ?>">Настроить агента</a></div></article>
          <?php endforeach; ?></div><?php endif; ?>
        </section>
        <?php return (string)ob_get_clean();
    }

    private static function renderAiSeller(string $sellerToken): string {
        try{$info=SalesAiSellerService::publicInfo($sellerToken);}catch(\Throwable $e){return '<section class="ckm-sales-card ckm-sales-gate"><div class="ckm-sales-kicker">ИИ-ПРОДАВЕЦ</div><h1>Ссылка недоступна</h1><p>'.esc_html($e->getMessage()).'</p></section>';}
        ob_start(); ?>
        <section class="ckm-sales-ai-seller" id="ckm-sales-ai-seller" data-seller-token="<?php echo esc_attr($sellerToken); ?>">
          <div class="ckm-sales-card ckm-sales-ai-seller-hero">
            <div class="ckm-sales-kicker">ИИ-ПРОДАВЕЦ · <?php echo esc_html((string)($info['agent_name']??'ИИ-консультант')); ?></div><h1><?php echo esc_html((string)$info['title']); ?></h1>
            <p class="ckm-sales-lead">Вы разговариваете с искусственным интеллектом. Он работает по утверждённому скрипту продавца, отвечает на вопросы и при нехватке подтверждённых данных не должен их придумывать.</p>
            <div class="ckm-sales-script-client-meta"><span id="ckm-sales-seller-voice-label"><?php echo esc_html((string)$info['voice_label']); ?></span><span>Микрофон клиента</span><span>Аудио не сохраняется в WordPress</span></div>
            <div class="ckm-sales-format-note"><strong>Приватность разговора</strong><span>Для работы диалога временно сохраняется текст текущей сессии. Аудиозапись микрофона не сохраняется в WordPress.</span></div>
            <button class="ckm-sales-btn ckm-sales-primary" id="ckm-sales-seller-start" type="button">Начать разговор</button>
          </div>
          <div class="ckm-sales-card ckm-sales-ai-seller-chat" id="ckm-sales-seller-chat" hidden>
            <div class="ckm-sales-ai-seller-status"><strong id="ckm-sales-seller-state">ИИ-продавец готов</strong><span id="ckm-sales-seller-stage">Разговор</span></div>
            <div class="ckm-sales-messages ckm-sales-ai-seller-messages" id="ckm-sales-seller-messages" aria-live="polite"></div>
            <div class="ckm-sales-ai-seller-voicebar">
              <button class="ckm-sales-btn ckm-sales-seller-mic" id="ckm-sales-seller-mic" type="button">🎙 Говорить</button>
              <span class="ckm-sales-muted" id="ckm-sales-seller-mic-status">Можно говорить голосом или написать сообщение.</span>
            </div>
            <div class="ckm-sales-composer"><textarea id="ckm-sales-seller-input" rows="3" placeholder="Ваш вопрос или ответ…"></textarea><div class="ckm-sales-composer-actions"><button class="ckm-sales-btn" id="ckm-sales-seller-end" type="button">Завершить разговор</button><button class="ckm-sales-btn ckm-sales-primary" id="ckm-sales-seller-send" type="button">Отправить</button></div></div>
            <div class="ckm-sales-inline-status" id="ckm-sales-seller-error" aria-live="polite"></div>
          </div>
        </section>
        <?php return (string)ob_get_clean();
    }

    private static function renderResults(array $library): string {
        $cards=self::sortSalesCards(ProductCatalog::libraryCards((int)$library['id']));
        ob_start(); ?><section class="ckm-sales-card ckm-sales-results-list"><div class="ckm-sales-kicker">РЕЗУЛЬТАТЫ</div><h1>Мои тренировки</h1><p class="ckm-sales-lead">Последние индивидуальные тренировочные попытки по сценариям «Эффективного продажника».</p><?php foreach($cards as $card):$attempts=ProductCatalog::attempts((int)$card['id'],8); ?><div class="ckm-sales-result-group"><h3><?php echo esc_html((string)$card['title']); ?></h3><?php if(!$attempts): ?><p class="ckm-sales-muted">Пока нет попыток.</p><?php else: foreach($attempts as $a): ?><div class="ckm-sales-attempt"><span><?php echo esc_html((string)($a['completed_at']?:$a['last_activity_at']?:$a['started_at'])); ?></span><strong><?php echo $a['final_score']!==null?esc_html((string)round((float)$a['final_score']).'/100'):esc_html((string)$a['status']); ?></strong><?php if(str_starts_with((string)$a['status'],'completed_')): ?><a href="<?php echo esc_url(self::url(['sales_scenario'=>(string)$card['slug'],'sales_session'=>(int)$a['id'],'sales_format'=>'training'])); ?>">Открыть</a><?php endif; ?></div><?php endforeach; endif; ?></div><?php endforeach; ?></section><?php return (string)ob_get_clean();
    }

    private static function requestedSession(int $sessionId,int $scenarioId): ?array {
        if($sessionId<1)return null;
        $session=Access::session($sessionId);
        if((int)($session['scenario_id']??0)!==$scenarioId)return null;
        if(!in_array((string)($session['session_kind']??'player'),['player','assignment'],true))return null;
        SalesDomain::versionForSession($sessionId);
        return $session;
    }

    public static function render(): string {
        try {
            $view=(string)($_GET['sales_view']??'');
            if($view==='ai-seller'){$sellerToken=sanitize_text_field((string)wp_unslash($_GET['sales_seller']??''));return '<div class="ckm-sales-shell">'.self::renderAiSeller($sellerToken).'</div>';}
            if($gate=self::accessGate())return '<div class="ckm-sales-shell">'.$gate.'</div>';
            $library=self::library();
            $waitingCompetition=absint($_GET['sales_waiting_competition']??0);
            $bareTeamLink=$view==='' && empty($_GET['sales_scenario']) && empty($_GET['sales_custom_scenario']) && empty($_GET['sales_session']);
            if($waitingCompetition<=0 && $bareTeamLink)$waitingCompetition=SalesCompetitionService::currentTeamAssignmentId();
            if($waitingCompetition>0)return '<div class="ckm-sales-shell">'.self::renderCompetitionWaiting($waitingCompetition).'</div>';
            if($view==='scripts'){
                $scriptId=sanitize_text_field((string)($_GET['sales_script']??''));
                if($scriptId!==''){$script=SalesScriptService::find($scriptId);if(!$script)throw new \InvalidArgumentException('Скрипт не найден.');return '<div class="ckm-sales-shell">'.self::renderScriptDetail($script,$library).'</div>';}
                return '<div class="ckm-sales-shell">'.self::renderScripts($library).'</div>';
            }
            if($view==='ai-sellers')return '<div class="ckm-sales-shell">'.self::renderAiSellers().'</div>';
            if($view==='integrations')return '<div class="ckm-sales-shell">'.self::renderIntegrations().'</div>';
            if($view==='results')return '<div class="ckm-sales-shell">'.(SalesTeamDevelopmentService::canManage()?SalesTeamDevelopmentPage::render():self::renderResults($library)).'</div>';
            if($view==='polygon')return '<div class="ckm-sales-shell">'.self::renderHome($library).'</div>';
            if($view==='center')return '<div class="ckm-sales-shell">'.SalesDevelopmentCenterPage::render($library).'</div>';
            if($view==='competition-host'){
                $competitionId=absint($_GET['sales_competition']??0);
                if($competitionId<1)throw new \InvalidArgumentException('Соревнование не выбрано.');
                return '<div class="ckm-sales-shell">'.self::renderCompetitionHost($competitionId).'</div>';
            }
            if($view==='competitions'){
                $competitionId=absint($_GET['sales_competition']??0);
                return '<div class="ckm-sales-shell">'.($competitionId>0?self::renderCompetitionDetail($competitionId):self::renderCompetitions()).'</div>';
            }
            $slug=sanitize_key((string)($_GET['sales_scenario']??''));$customScenarioId=absint($_GET['sales_custom_scenario']??0);
            if($slug===''&&$customScenarioId<1)return '<div class="ckm-sales-shell">'.SalesDevelopmentCenterPage::render($library).'</div>';
            [$scenario,$version]=$customScenarioId>0?self::customScenario($customScenarioId):self::scenario($slug,$library);
            $sessionId=absint($_GET['sales_session']??0);
            $format=(string)($_GET['sales_format']??'training');
            if($sessionId<=0){
                return '<div class="ckm-sales-shell">'.($format==='competition'?self::renderCompetitionCreate($scenario,$version):self::renderBrief($scenario,$version)).'</div>';
            }
            $session=self::requestedSession($sessionId,(int)$scenario['id']);
            if(!$session)throw new \InvalidArgumentException('Попытка недоступна.');
            if((string)($session['status']??'')==='abandoned'){
                if(SalesCompetitionService::isCompetitionAssignment((int)($session['assignment_id']??0))){
                    return '<div class="ckm-sales-shell"><div class="ckm-sales-card ckm-sales-gate"><h2>Командная попытка закрыта</h2><p>Эту попытку уже нельзя продолжить. Вернитесь в соревнование и запустите доступную попытку команды.</p><a class="ckm-sales-btn ckm-sales-primary" href="'.esc_url(self::url(['sales_view'=>'competitions'])).'">К соревнованиям</a></div></div>';
                }
                if((string)($session['mode']??'training')==='exam'){
                    $assignmentId=(int)($session['assignment_id']??0);
                    $back=$assignmentId>0?\CKM\NegotiationMaster\AssignmentPage::url(['assignment'=>$assignmentId]):self::url(['sales_view'=>'results']);
                    return '<div class="ckm-sales-shell"><div class="ckm-sales-card ckm-sales-gate"><h2>Проверка закрыта</h2><p>Эту контрольную попытку уже нельзя продолжить. Вернитесь к назначению проверки.</p><a class="ckm-sales-btn ckm-sales-primary" href="'.esc_url($back).'">К проверке</a></div></div>';
                }
                return '<div class="ckm-sales-shell">'.self::renderBrief($scenario,$version,'Эта попытка была закрыта или заменена новой. Начните тренировку заново.',true).'</div>';
            }
            return '<div class="ckm-sales-shell">'.self::renderSession($scenario,$version,$session).'</div>';
        } catch(\Throwable $e){return '<div class="ckm-sales-shell"><div class="ckm-sales-card ckm-sales-gate"><h2>Эффективный продажник</h2><p>'.esc_html($e->getMessage()).'</p><a class="ckm-sales-btn" href="'.esc_url(self::url()).'">К сценариям</a></div></div>';}
    }
}
