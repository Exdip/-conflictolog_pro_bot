<?php
namespace CKM\EffectiveSales;
if (!defined('ABSPATH')) { exit; }

final class SalesAiSellerWorkspaceService {
    private const LOG_META_KEY='ckm_sales_ai_seller_logs_v1';
    private const MAX_LOGS=80;
    private const MAX_LOG_MESSAGES=80;
    private const MAX_KNOWLEDGE=24;
    private const MAX_CORRECTIONS=30;
    private const MAX_SCENARIOS=20;
    private const MAX_SCENARIO_STEPS=8;
    private const SCENARIO_TRIGGERS=['idle'=>'Нет ответа клиента','stage'=>'Стадия воронки'];
    private const SCENARIO_ACTIONS=['message'=>'Отправить сообщение','handoff'=>'Передать человеку','change_stage'=>'Сменить стадию','close_won'=>'Закрыть как успех','close_lost'=>'Закрыть как отказ'];
    private const PIPELINE_STAGES=['new'=>'Новый','qualified'=>'Квалификация','proposal'=>'Предложение','decision'=>'Решение','won'=>'Успех','lost'=>'Отказ'];
    private const OUTCOME_LABELS=['active'=>'Активный','successful'=>'Успешный','unsuccessful'=>'Неуспешный','stalled'=>'Застопорен'];
    private const STALLED_AFTER=86400;

    private static function clean(string $v,int $max=4000): string {
        $v=trim(wp_strip_all_tags($v));
        return function_exists('mb_substr')?mb_substr($v,0,$max,'UTF-8'):substr($v,0,$max);
    }
    private static function lower(string $v): string { return function_exists('mb_strtolower')?mb_strtolower($v,'UTF-8'):strtolower($v); }
    public static function pipelineStages(): array { return self::PIPELINE_STAGES; }
    public static function outcomeLabels(): array { return self::OUTCOME_LABELS; }
    private static function stage(string $v): string { return array_key_exists($v,self::PIPELINE_STAGES)?$v:'new'; }
    private static function messageTs(array $message): int {
        $ts=(int)($message['at_ts']??0);if($ts>0)return $ts;$raw=(string)($message['at']??'');$parsed=$raw!==''?strtotime($raw):false;return $parsed===false?0:(int)$parsed;
    }
    private static function goalStatus(array $log,?int $now=null): string {
        if((string)($log['source_kind']??'')==='human_import'){
            $forced=(string)($log['practice_outcome']??'');
            if(in_array($forced,['successful','unsuccessful','stalled'],true))return $forced;
        }
        $stage=self::stage((string)($log['pipeline_stage']??'new'));if($stage==='won')return 'successful';if($stage==='lost')return 'unsuccessful';
        $now=$now??time();$last=(string)($log['last_at']??'');$lastTs=$last!==''?strtotime($last):false;
        if((string)($log['status']??'')==='ended')return 'unsuccessful';
        if($lastTs!==false&&$lastTs>0&&($now-(int)$lastTs)>=self::STALLED_AFTER)return 'stalled';
        return 'active';
    }
    private static function normalizeLog(array $log): array {
        $log['lead_name']=self::clean((string)($log['lead_name']??''),120);
        $log['lead_contact']=self::clean((string)($log['lead_contact']??''),180);
        $log['pipeline_stage']=self::stage((string)($log['pipeline_stage']??'new'));
        $log['control_mode']=((string)($log['control_mode']??'ai')==='human')?'human':'ai';
        $log['channel']=in_array((string)($log['channel']??'web'),['web','telegram','max','whatsapp','phone'],true)?(string)($log['channel']??'web'):'web';
        $log['goal_status']=self::goalStatus($log);
        return $log;
    }

    public static function workspace(array $script): array {
        $knowledge=is_array($script['ai_seller_knowledge']??null)?array_values($script['ai_seller_knowledge']):[];
        $corrections=is_array($script['ai_seller_corrections']??null)?array_values($script['ai_seller_corrections']):[];
        return [
            'name'=>self::clean((string)($script['ai_seller_name']??'ИИ-продавец'),80)?:'ИИ-продавец',
            'tone'=>self::clean((string)($script['ai_seller_tone']??'Деловой, спокойный, доброжелательный'),180)?:'Деловой, спокойный, доброжелательный',
            'handoff'=>self::clean((string)($script['ai_seller_handoff']??'Если вопрос нельзя надёжно закрыть по базе знаний или клиент просит человека — предложить подключить специалиста.'),500),
            'primary_goal'=>self::clean((string)($script['ai_seller_primary_goal']??$script['goal']??''),300),
            'knowledge'=>$knowledge,
            'corrections'=>$corrections,
            'scenarios'=>self::scenarios($script),
        ];
    }

    public static function saveProfile(string $scriptId,array $data): array {
        $name=self::clean((string)($data['name']??''),80);
        $tone=self::clean((string)($data['tone']??''),180);
        $handoff=self::clean((string)($data['handoff']??''),500);
        $primaryGoal=self::clean((string)($data['primary_goal']??''),300);
        if($name==='')throw new \InvalidArgumentException('Укажите имя ИИ-продавца.');
        if($tone==='')throw new \InvalidArgumentException('Укажите стиль общения.');
        if($primaryGoal==='')throw new \InvalidArgumentException('Укажите основную цель ИИ-продавца.');
        return SalesScriptService::mutate($scriptId,static function(array $item) use($name,$tone,$handoff,$primaryGoal): array {
            $item['ai_seller_name']=$name;$item['ai_seller_tone']=$tone;$item['ai_seller_handoff']=$handoff;$item['ai_seller_primary_goal']=$primaryGoal;return $item;
        });
    }

    private static function knowledgeId(): string { return 'kb_'.str_replace('-','',wp_generate_uuid4()); }

    public static function addTextKnowledge(string $scriptId,string $title,string $content): array {
        $title=self::clean($title,140);$content=self::clean($content,12000);
        if($content==='')throw new \InvalidArgumentException('Добавьте текст в базу знаний.');
        if($title==='')$title='Заметка';
        return SalesScriptService::mutate($scriptId,static function(array $item) use($title,$content): array {
            $list=is_array($item['ai_seller_knowledge']??null)?array_values($item['ai_seller_knowledge']):[];
            if(count($list)>=self::MAX_KNOWLEDGE)throw new \RuntimeException('Достигнут лимит источников базы знаний.');
            $list[]=['id'=>self::knowledgeId(),'type'=>'text','title'=>$title,'url'=>'','content'=>$content,'created_at'=>current_time('mysql')];
            $item['ai_seller_knowledge']=$list;return $item;
        });
    }

    private static function urlTitle(string $html,string $url): string {
        if(preg_match('/<title[^>]*>(.*?)<\/title>/isu',$html,$m)){
            $t=html_entity_decode(wp_strip_all_tags((string)$m[1]),ENT_QUOTES|ENT_HTML5,'UTF-8');$t=self::clean($t,140);if($t!=='')return $t;
        }
        $host=(string)wp_parse_url($url,PHP_URL_HOST);return $host!==''?$host:'Веб-страница';
    }
    private static function htmlToText(string $html): string {
        $html=preg_replace('/<(script|style|noscript|svg|canvas|iframe)\b[^>]*>.*?<\/\1>/isu',' ',$html)??$html;
        $html=preg_replace('/<(br|p|div|li|h[1-6]|tr|section|article|header|footer)\b[^>]*>/iu',"\n",$html)??$html;
        $text=html_entity_decode(wp_strip_all_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $text=preg_replace('/[ \t]+/u',' ',$text)??$text;$text=preg_replace('/\n{3,}/u',"\n\n",$text)??$text;
        return self::clean($text,12000);
    }
    public static function addUrlKnowledge(string $scriptId,string $url): array {
        $url=trim($url);if($url===''||!wp_http_validate_url($url))throw new \InvalidArgumentException('Укажите корректную публичную ссылку http/https.');
        $scheme=self::lower((string)wp_parse_url($url,PHP_URL_SCHEME));if(!in_array($scheme,['http','https'],true))throw new \InvalidArgumentException('Поддерживаются только http/https ссылки.');
        $response=wp_safe_remote_get($url,['timeout'=>12,'redirection'=>3,'limit_response_size'=>180000,'user-agent'=>'CKM AI Seller Knowledge/1.0']);
        if(is_wp_error($response))throw new \RuntimeException('Не удалось прочитать страницу: '.$response->get_error_message());
        $code=(int)wp_remote_retrieve_response_code($response);if($code<200||$code>=300)throw new \RuntimeException('Страница вернула HTTP '.$code.'.');
        $body=(string)wp_remote_retrieve_body($response);$content=self::htmlToText($body);if(strlen($content)<40)throw new \RuntimeException('На странице не найдено достаточно текста для базы знаний.');
        $title=self::urlTitle($body,$url);
        return SalesScriptService::mutate($scriptId,static function(array $item) use($title,$url,$content): array {
            $list=is_array($item['ai_seller_knowledge']??null)?array_values($item['ai_seller_knowledge']):[];
            if(count($list)>=self::MAX_KNOWLEDGE)throw new \RuntimeException('Достигнут лимит источников базы знаний.');
            foreach($list as $x)if(is_array($x)&&(string)($x['url']??'')===$url)throw new \RuntimeException('Эта ссылка уже добавлена в базу знаний.');
            $list[]=['id'=>self::knowledgeId(),'type'=>'url','title'=>$title,'url'=>$url,'content'=>$content,'created_at'=>current_time('mysql')];
            $item['ai_seller_knowledge']=$list;return $item;
        });
    }
    public static function removeKnowledge(string $scriptId,string $sourceId): array {
        $sourceId=self::clean($sourceId,80);return SalesScriptService::mutate($scriptId,static function(array $item) use($sourceId): array {
            $list=is_array($item['ai_seller_knowledge']??null)?array_values($item['ai_seller_knowledge']):[];
            $item['ai_seller_knowledge']=array_values(array_filter($list,static fn($x): bool=>!is_array($x)||(string)($x['id']??'')!==$sourceId));return $item;
        });
    }
    public static function addCorrection(string $scriptId,string $text): array {
        $text=self::clean($text,700);if($text==='')throw new \InvalidArgumentException('Опишите, что ИИ-продавец должен делать иначе.');
        return SalesScriptService::mutate($scriptId,static function(array $item) use($text): array {
            $list=is_array($item['ai_seller_corrections']??null)?array_values($item['ai_seller_corrections']):[];
            if(count($list)>=self::MAX_CORRECTIONS)throw new \RuntimeException('Достигнут лимит корректировок.');
            $list[]=['id'=>'fix_'.str_replace('-','',wp_generate_uuid4()),'text'=>$text,'created_at'=>current_time('mysql')];$item['ai_seller_corrections']=$list;return $item;
        });
    }
    public static function removeCorrection(string $scriptId,string $correctionId): array {
        $correctionId=self::clean($correctionId,80);return SalesScriptService::mutate($scriptId,static function(array $item) use($correctionId): array {
            $list=is_array($item['ai_seller_corrections']??null)?array_values($item['ai_seller_corrections']):[];
            $item['ai_seller_corrections']=array_values(array_filter($list,static fn($x): bool=>!is_array($x)||(string)($x['id']??'')!==$correctionId));return $item;
        });
    }

    public static function scenarioTriggers(): array { return self::SCENARIO_TRIGGERS; }
    public static function scenarioActions(): array { return self::SCENARIO_ACTIONS; }

    private static function scenarioStep(array $row,int $index=0): array {
        $action=array_key_exists((string)($row['action']??''),self::SCENARIO_ACTIONS)?(string)$row['action']:'message';
        $id=self::clean((string)($row['id']??''),80);
        if($id==='')$id='step_'.($index+1);
        return [
            'id'=>$id,
            'delay_minutes'=>max(0,min(10080,(int)($row['delay_minutes']??0))),
            'action'=>$action,
            'message'=>self::clean((string)($row['message']??''),1200),
            'target_stage'=>self::stage((string)($row['target_stage']??'qualified')),
        ];
    }
    private static function rawScenarioSteps(array $row): array {
        $steps=is_array($row['steps']??null)?array_values($row['steps']):[];
        if(!$steps){
            $steps=[[
                'id'=>'step_1',
                'delay_minutes'=>(int)($row['delay_minutes']??0),
                'action'=>(string)($row['action']??'message'),
                'message'=>(string)($row['message']??''),
                'target_stage'=>(string)($row['target_stage']??'qualified'),
            ]];
        }
        $out=[];foreach(array_slice($steps,0,self::MAX_SCENARIO_STEPS) as $i=>$step)if(is_array($step))$out[]=self::scenarioStep($step,(int)$i);
        return $out?:[self::scenarioStep([],0)];
    }
    private static function syncLegacyScenarioFields(array $row): array {
        $steps=self::rawScenarioSteps($row);$first=$steps[0];
        $row['steps']=$steps;
        $row['delay_minutes']=$first['delay_minutes'];
        $row['action']=$first['action'];
        $row['message']=$first['message'];
        $row['target_stage']=$first['target_stage'];
        return $row;
    }
    public static function scenarios(array $script): array {
        $rows=is_array($script['ai_seller_scenarios']??null)?array_values($script['ai_seller_scenarios']):[];$out=[];
        foreach($rows as $row){
            if(!is_array($row))continue;$trigger=array_key_exists((string)($row['trigger']??''),self::SCENARIO_TRIGGERS)?(string)$row['trigger']:'idle';
            $stage=self::stage((string)($row['trigger_stage']??'new'));$row=self::syncLegacyScenarioFields($row);$first=$row['steps'][0];
            $out[]=['id'=>self::clean((string)($row['id']??''),80),'name'=>self::clean((string)($row['name']??'Сценарий'),120)?:'Сценарий','enabled'=>!array_key_exists('enabled',$row)||!empty($row['enabled']),'trigger'=>$trigger,'trigger_stage'=>$stage,'steps'=>$row['steps'],'delay_minutes'=>$first['delay_minutes'],'action'=>$first['action'],'message'=>$first['message'],'target_stage'=>$first['target_stage'],'created_at'=>self::clean((string)($row['created_at']??''),40)];
        }
        return array_slice($out,-self::MAX_SCENARIOS);
    }
    public static function addScenario(string $scriptId,array $data): array {
        $name=self::clean((string)($data['name']??''),120);$trigger=sanitize_key((string)($data['trigger']??'idle'));$action=sanitize_key((string)($data['action']??'message'));$triggerStage=self::stage(sanitize_key((string)($data['trigger_stage']??'new')));$targetStage=self::stage(sanitize_key((string)($data['target_stage']??'qualified')));$delay=max(0,min(10080,(int)($data['delay_minutes']??0)));$message=self::clean((string)($data['message']??''),1200);
        if($name==='')throw new \InvalidArgumentException('Укажите название сценария.');if(!array_key_exists($trigger,self::SCENARIO_TRIGGERS))throw new \InvalidArgumentException('Выберите корректный триггер сценария.');if(!array_key_exists($action,self::SCENARIO_ACTIONS))throw new \InvalidArgumentException('Выберите корректное действие сценария.');if(in_array($action,['message','handoff'],true)&&$message==='')throw new \InvalidArgumentException('Для сообщения или передачи человеку укажите текст клиенту.');
        return SalesScriptService::mutate($scriptId,static function(array $item) use($name,$trigger,$triggerStage,$delay,$action,$message,$targetStage): array {
            $list=is_array($item['ai_seller_scenarios']??null)?array_values($item['ai_seller_scenarios']):[];if(count($list)>=self::MAX_SCENARIOS)throw new \RuntimeException('Достигнут лимит сценариев.');
            $step=['id'=>'step_'.str_replace('-','',wp_generate_uuid4()),'delay_minutes'=>$delay,'action'=>$action,'message'=>$message,'target_stage'=>$targetStage];
            $list[]=['id'=>'flow_'.str_replace('-','',wp_generate_uuid4()),'name'=>$name,'enabled'=>true,'trigger'=>$trigger,'trigger_stage'=>$triggerStage,'steps'=>[$step],'delay_minutes'=>$delay,'action'=>$action,'message'=>$message,'target_stage'=>$targetStage,'created_at'=>current_time('mysql')];$item['ai_seller_scenarios']=$list;return $item;
        });
    }
    public static function addScenarioStep(string $scriptId,string $scenarioId,array $data): array {
        $scenarioId=self::clean($scenarioId,80);$action=sanitize_key((string)($data['action']??'message'));$targetStage=self::stage(sanitize_key((string)($data['target_stage']??'qualified')));$delay=max(0,min(10080,(int)($data['delay_minutes']??0)));$message=self::clean((string)($data['message']??''),1200);
        if(!array_key_exists($action,self::SCENARIO_ACTIONS))throw new \InvalidArgumentException('Выберите корректное действие шага.');if(in_array($action,['message','handoff'],true)&&$message==='')throw new \InvalidArgumentException('Для сообщения или передачи человеку укажите текст клиенту.');
        return SalesScriptService::mutate($scriptId,static function(array $item) use($scenarioId,$action,$targetStage,$delay,$message): array {
            $list=is_array($item['ai_seller_scenarios']??null)?array_values($item['ai_seller_scenarios']):[];$found=false;
            foreach($list as &$row){
                if(!is_array($row)||(string)($row['id']??'')!==$scenarioId)continue;$row=self::syncLegacyScenarioFields($row);$steps=array_values((array)$row['steps']);if(count($steps)>=self::MAX_SCENARIO_STEPS)throw new \RuntimeException('В одном сценарии можно создать не более '.self::MAX_SCENARIO_STEPS.' шагов.');
                $steps[]=['id'=>'step_'.str_replace('-','',wp_generate_uuid4()),'delay_minutes'=>$delay,'action'=>$action,'message'=>$message,'target_stage'=>$targetStage];$row['steps']=$steps;$row=self::syncLegacyScenarioFields($row);$found=true;break;
            }unset($row);if(!$found)throw new \InvalidArgumentException('Сценарий не найден.');$item['ai_seller_scenarios']=$list;return $item;
        });
    }
    public static function deleteScenarioStep(string $scriptId,string $scenarioId,string $stepId): array {
        $scenarioId=self::clean($scenarioId,80);$stepId=self::clean($stepId,80);
        return SalesScriptService::mutate($scriptId,static function(array $item) use($scenarioId,$stepId): array {
            $list=is_array($item['ai_seller_scenarios']??null)?array_values($item['ai_seller_scenarios']):[];$found=false;
            foreach($list as &$row){
                if(!is_array($row)||(string)($row['id']??'')!==$scenarioId)continue;$row=self::syncLegacyScenarioFields($row);$steps=array_values((array)$row['steps']);if(count($steps)<=1)throw new \RuntimeException('В сценарии должен остаться хотя бы один шаг.');
                $next=array_values(array_filter($steps,static fn($x): bool=>!is_array($x)||(string)($x['id']??'')!==$stepId));if(count($next)===count($steps))throw new \InvalidArgumentException('Шаг сценария не найден.');$row['steps']=$next;$row=self::syncLegacyScenarioFields($row);$found=true;break;
            }unset($row);if(!$found)throw new \InvalidArgumentException('Сценарий не найден.');$item['ai_seller_scenarios']=$list;return $item;
        });
    }
    public static function deleteScenario(string $scriptId,string $scenarioId): array {
        $scenarioId=self::clean($scenarioId,80);return SalesScriptService::mutate($scriptId,static function(array $item) use($scenarioId): array {$list=is_array($item['ai_seller_scenarios']??null)?array_values($item['ai_seller_scenarios']):[];$item['ai_seller_scenarios']=array_values(array_filter($list,static fn($x): bool=>!is_array($x)||(string)($x['id']??'')!==$scenarioId));return $item;});
    }
    public static function toggleScenario(string $scriptId,string $scenarioId): array {
        $scenarioId=self::clean($scenarioId,80);return SalesScriptService::mutate($scriptId,static function(array $item) use($scenarioId): array {$list=is_array($item['ai_seller_scenarios']??null)?array_values($item['ai_seller_scenarios']):[];$found=false;foreach($list as &$row){if(!is_array($row)||(string)($row['id']??'')!==$scenarioId)continue;$row['enabled']=empty($row['enabled']);$found=true;break;}unset($row);if(!$found)throw new \InvalidArgumentException('Сценарий не найден.');$item['ai_seller_scenarios']=$list;return $item;});
    }

    public static function promptContext(array $script): string {
        $w=self::workspace($script);$parts=[];
        $parts[]='ИМЯ АГЕНТА: '.$w['name'];$parts[]='СТИЛЬ ОБЩЕНИЯ: '.$w['tone'];
        if($w['primary_goal']!=='')$parts[]='ОСНОВНАЯ ЦЕЛЬ ПРОДАЖИ: '.$w['primary_goal'];
        if($w['handoff']!=='')$parts[]='ПРАВИЛО ПЕРЕДАЧИ ЧЕЛОВЕКУ: '.$w['handoff'];
        $kb=[];$used=0;foreach($w['knowledge'] as $src){if(!is_array($src))continue;$content=self::clean((string)($src['content']??''),4500);if($content==='')continue;$piece='['.self::clean((string)($src['title']??'Источник'),120)."]\n".$content;$remain=9000-$used;if($remain<=0)break;if(strlen($piece)>$remain)$piece=substr($piece,0,$remain);$kb[]=$piece;$used+=strlen($piece);}
        if($kb)$parts[]="БАЗА ЗНАНИЙ (единственный источник конкретных фактов сверх скрипта):\n".implode("\n\n",$kb);
        $fix=[];foreach(array_slice($w['corrections'],-12) as $c)if(is_array($c)&&trim((string)($c['text']??''))!=='')$fix[]='- '.self::clean((string)$c['text'],700);
        $practiceRules=SalesPracticeFeedbackService::activeRulesText($script);
        if($practiceRules!=='')$parts[]="ДЕЙСТВУЮЩИЕ ПРАВИЛА МЕТОДИКИ (принципы поведения, не источник новых фактов):\n".$practiceRules;
        if($fix)$parts[]="КОРРЕКТИРОВКИ ПОВЕДЕНИЯ (последние имеют приоритет, если не противоречат фактам):\n".implode("\n",$fix);
        return implode("\n\n",$parts);
    }

    private static function readLogs(int $userId): array {$v=get_user_meta($userId,self::LOG_META_KEY,true);return is_array($v)?array_values($v):[];}
    private static function writeLogs(int $userId,array $logs): void {update_user_meta($userId,self::LOG_META_KEY,array_slice(array_values($logs),-self::MAX_LOGS));}
    private static function upsertLog(int $userId,string $sessionId,callable $mutator): void {
        if($userId<1||$sessionId==='')return;$logs=self::readLogs($userId);$found=false;
        foreach($logs as &$log){if(!is_array($log)||(string)($log['session_id']??'')!==$sessionId)continue;$next=$mutator($log);$log=is_array($next)?$next:$log;$found=true;break;}unset($log);
        if(!$found){$base=['session_id'=>$sessionId,'messages'=>[]];$next=$mutator($base);$logs[]=is_array($next)?$next:$base;}
        self::writeLogs($userId,$logs);
    }
    public static function recordStart(array $session,string $greeting): void {
        $uid=(int)($session['owner_user_id']??0);$sid=(string)($session['id']??'');self::upsertLog($uid,$sid,static function(array $log) use($session,$greeting): array {
            $log+=['script_id'=>(string)($session['script_id']??''),'scope'=>(string)($session['scope']??'default'),'status'=>'active','started_at'=>gmdate('c'),'last_at'=>gmdate('c'),'handoff'=>false,'lead_name'=>'','lead_contact'=>'','pipeline_stage'=>'new','control_mode'=>(string)($session['control_mode']??'ai'),'channel'=>(string)($session['channel']??'web'),'messages'=>[]];
            $log['messages']=[['role'=>'assistant','content'=>self::clean($greeting,1200),'at'=>gmdate('c'),'at_ts'=>time()]];return $log;
        });
    }
    public static function recordExternalStart(array $session,string $leadName,string $leadContact,string $channel): void {
        $uid=(int)($session['owner_user_id']??0);$sid=(string)($session['id']??'');$leadName=self::clean($leadName,120);$leadContact=self::clean($leadContact,180);$channel=in_array($channel,['telegram','max','whatsapp','phone'],true)?$channel:'web';self::upsertLog($uid,$sid,static function(array $log) use($session,$leadName,$leadContact,$channel): array {
            $log+=['script_id'=>(string)($session['script_id']??''),'scope'=>(string)($session['scope']??'default'),'status'=>'active','started_at'=>gmdate('c'),'last_at'=>gmdate('c'),'handoff'=>false,'pipeline_stage'=>'new','control_mode'=>(string)($session['control_mode']??'ai'),'messages'=>[]];$log['lead_name']=$leadName;$log['lead_contact']=$leadContact;$log['channel']=$channel;$log['messages']=[];return $log;
        });
    }

    private static function importedRole(string $role): string {
        $role=self::lower(trim($role));
        if(in_array($role,['user','client','customer','buyer','клиент','покупатель'],true))return 'user';
        if(in_array($role,['operator','seller','employee','manager','agent','продавец','сотрудник','менеджер'],true))return 'operator';
        return '';
    }

    public static function importHumanDialog(string $scriptId,array $data): array {
        if(!SalesPracticeFeedbackService::canManage())throw new \RuntimeException('Импорт реальных диалогов доступен организатору или партнёру.');
        $script=SalesScriptService::find($scriptId);if(!$script)throw new \InvalidArgumentException('Методика не найдена.');
        $uid=get_current_user_id();if($uid<1)throw new \RuntimeException('Требуется вход.');
        $scope=SalesScriptService::currentScopeKey();
        $channel=sanitize_key((string)($data['channel']??'web'));
        if(!in_array($channel,['web','telegram','max','whatsapp','phone'],true))throw new \InvalidArgumentException('Неизвестный канал реального диалога.');
        $externalId=self::clean((string)($data['external_id']??''),220);
        $sessionId=$externalId!==''
            ? 'real_'.substr(hash('sha256',$uid.'|'.$scope.'|'.$scriptId.'|'.$channel.'|'.$externalId),0,32)
            : 'real_'.str_replace('-','',wp_generate_uuid4());

        $rows=is_array($data['messages']??null)?array_values($data['messages']):[];
        $messages=[];$clientCount=0;$sellerCount=0;
        foreach(array_slice($rows,0,self::MAX_LOG_MESSAGES) as $row){
            if(!is_array($row))continue;
            $role=self::importedRole((string)($row['role']??''));if($role==='')continue;
            $content=self::clean((string)($row['content']??''),1200);if($content==='')continue;
            $m=['role'=>$role,'content'=>$content,'imported_real_dialog'=>true];
            $at=self::clean((string)($row['at']??''),50);$ts=$at!==''?strtotime($at):false;
            if($ts!==false&&$ts>0){$m['at']=gmdate('c',(int)$ts);$m['at_ts']=(int)$ts;}
            $messages[]=$m;
            if($role==='user')$clientCount++;else $sellerCount++;
        }
        if($clientCount<1||$sellerCount<1)throw new \InvalidArgumentException('В реальном диалоге нужна хотя бы одна реплика клиента и одна реплика сотрудника.');

        $outcome=sanitize_key((string)($data['outcome']??'unsuccessful'));
        if(!in_array($outcome,['active','successful','unsuccessful','stalled'],true))throw new \InvalidArgumentException('Неизвестный исход реального диалога.');
        $stage=self::stage(sanitize_key((string)($data['pipeline_stage']??'new')));
        if($outcome==='successful')$stage='won';elseif($outcome==='unsuccessful')$stage='lost';
        $leadName=self::clean((string)($data['lead_name']??''),120);
        $leadContact=self::clean((string)($data['lead_contact']??''),180);
        $now=gmdate('c');$logs=self::readLogs($uid);$reused=false;
        foreach($logs as $row)if(is_array($row)&&(string)($row['session_id']??'')===$sessionId){$reused=true;break;}
        self::upsertLog($uid,$sessionId,static function(array $log) use($scriptId,$scope,$channel,$outcome,$stage,$leadName,$leadContact,$messages,$externalId,$now): array {
            return [
                'session_id'=>(string)($log['session_id']??''),
                'script_id'=>$scriptId,'scope'=>$scope,
                'status'=>$outcome==='active'?'active':'ended',
                'started_at'=>(string)($log['started_at']??$now),'last_at'=>$now,
                'handoff'=>false,'lead_name'=>$leadName,'lead_contact'=>$leadContact,
                'pipeline_stage'=>$stage,'control_mode'=>'human','channel'=>$channel,
                'messages'=>$messages,'source_kind'=>'human_import','practice_outcome'=>$outcome,
                'external_source_hash'=>$externalId!==''?hash('sha256',$externalId):'',
                'imported_at'=>$now,
            ];
        });
        $dialog=self::findDialog($scriptId,$sessionId);
        if(!$dialog)throw new \RuntimeException('Не удалось сохранить реальный диалог.');
        return ['session_id'=>$sessionId,'reused'=>$reused,'dialog'=>$dialog];
    }
    public static function recordTurn(array $session,string $userText,array $out): void {
        $uid=(int)($session['owner_user_id']??0);$sid=(string)($session['id']??'');self::upsertLog($uid,$sid,static function(array $log) use($userText,$out): array {
            $messages=is_array($log['messages']??null)?array_values($log['messages']):[];
            $messages[]=['role'=>'user','content'=>self::clean($userText,1200),'at'=>gmdate('c'),'at_ts'=>time()];
            $messages[]=['role'=>'assistant','content'=>self::clean((string)($out['reply']??''),1200),'intent'=>(string)($out['intent']??'continue'),'stage'=>(string)($out['stage']??''),'response_seconds'=>(float)($out['response_seconds']??0),'at'=>gmdate('c'),'at_ts'=>time()];
            $log['messages']=array_slice($messages,-self::MAX_LOG_MESSAGES);$log['last_at']=gmdate('c');$intent=(string)($out['intent']??'continue');
            if($intent==='handoff'){$log['handoff']=true;$log['status']='handoff';}elseif($intent==='end')$log['status']='ended';else $log['status']='active';return $log;
        });
    }
    public static function recordEnd(array $session): void {
        $uid=(int)($session['owner_user_id']??0);$sid=(string)($session['id']??'');self::upsertLog($uid,$sid,static function(array $log): array {$log['status']='ended';$log['last_at']=gmdate('c');return $log;});
    }
    public static function recordClientOnly(array $session,string $userText): void {
        $uid=(int)($session['owner_user_id']??0);$sid=(string)($session['id']??'');self::upsertLog($uid,$sid,static function(array $log) use($userText): array {
            $messages=is_array($log['messages']??null)?array_values($log['messages']):[];$messages[]=['role'=>'user','content'=>self::clean($userText,1200),'at'=>gmdate('c'),'at_ts'=>time()];
            $log['messages']=array_slice($messages,-self::MAX_LOG_MESSAGES);$log['last_at']=gmdate('c');$log['control_mode']='human';$log['handoff']=true;$log['status']='active';return $log;
        });
    }
    public static function recordControl(array $session,string $mode): void {
        $uid=(int)($session['owner_user_id']??0);$sid=(string)($session['id']??'');$mode=$mode==='human'?'human':'ai';self::upsertLog($uid,$sid,static function(array $log) use($mode): array {
            $log['control_mode']=$mode;$log['last_at']=gmdate('c');if($mode==='human'){$log['handoff']=true;$log['status']='active';}return $log;
        });
    }
    public static function recordOperatorMessage(array $session,string $text): void {
        $uid=(int)($session['owner_user_id']??0);$sid=(string)($session['id']??'');self::upsertLog($uid,$sid,static function(array $log) use($text): array {
            $messages=is_array($log['messages']??null)?array_values($log['messages']):[];$messages[]=['role'=>'operator','content'=>self::clean($text,1200),'at'=>gmdate('c'),'at_ts'=>time()];
            $log['messages']=array_slice($messages,-self::MAX_LOG_MESSAGES);$log['last_at']=gmdate('c');$log['control_mode']='human';$log['handoff']=true;$log['status']='active';return $log;
        });
    }

    public static function recordLiveTranscriptDelta(array $session,string $role,string $delta,int $startMs=0,int $endMs=0,string $eventId=''): void {
        $uid=(int)($session['owner_user_id']??0);$sid=(string)($session['id']??'');$role=$role==='assistant'?'assistant':'user';$delta=wp_strip_all_tags($delta);if($delta==='')return;if(function_exists('mb_substr'))$delta=mb_substr($delta,0,1200,'UTF-8');else $delta=substr($delta,0,1200);
        self::upsertLog($uid,$sid,static function(array $log) use($role,$delta,$startMs,$endMs,$eventId): array {
            $messages=is_array($log['messages']??null)?array_values($log['messages']):[];$last=count($messages)-1;$merged=false;
            if($last>=0&&is_array($messages[$last])&&(string)($messages[$last]['role']??'')===$role&&!empty($messages[$last]['live_transcript'])){
                $prevEnd=(int)($messages[$last]['live_end_ms']??0);if($startMs===0||$prevEnd===0||$startMs<=$prevEnd+1600){$messages[$last]['content']=(string)($messages[$last]['content']??'').$delta;$messages[$last]['live_end_ms']=max($prevEnd,$endMs);if($eventId!==''){$ids=is_array($messages[$last]['event_ids']??null)?$messages[$last]['event_ids']:[];$ids[]=$eventId;$messages[$last]['event_ids']=array_slice(array_values(array_unique($ids)),-30);}$merged=true;}
            }
            if(!$merged)$messages[]=['role'=>$role,'content'=>$delta,'live_transcript'=>true,'live_start_ms'=>$startMs,'live_end_ms'=>$endMs,'event_ids'=>$eventId!==''?[$eventId]:[],'at'=>gmdate('c'),'at_ts'=>time()];
            $log['messages']=array_slice($messages,-self::MAX_LOG_MESSAGES);$log['last_at']=gmdate('c');$log['channel']='phone';$log['status']='active';return $log;
        });
    }
    public static function recordLiveCallState(array $session,string $callStatus,bool $ended=false,array $usage=[]): void {
        $uid=(int)($session['owner_user_id']??0);$sid=(string)($session['id']??'');$callStatus=self::clean($callStatus,50);self::upsertLog($uid,$sid,static function(array $log) use($callStatus,$ended,$usage): array {$log['channel']='phone';$log['call_status']=$callStatus;if($usage)$log['call_usage']=$usage;$log['last_at']=gmdate('c');if($ended)$log['status']='ended';return $log;});
    }
    public static function recordLiveTransfer(array $session,string $targetUri): void {
        $uid=(int)($session['owner_user_id']??0);$sid=(string)($session['id']??'');self::upsertLog($uid,$sid,static function(array $log) use($targetUri): array {$messages=is_array($log['messages']??null)?array_values($log['messages']):[];$messages[]=['role'=>'system','content'=>'Звонок переведён живому оператору.','live_transfer'=>true,'target_uri'=>self::clean($targetUri,240),'at'=>gmdate('c'),'at_ts'=>time()];$log['messages']=array_slice($messages,-self::MAX_LOG_MESSAGES);$log['channel']='phone';$log['control_mode']='human';$log['handoff']=true;$log['call_status']='transferred';$log['status']='ended';$log['last_at']=gmdate('c');return $log;});
    }

    public static function recordAutomation(array $session,array $result): void {
        $uid=(int)($session['owner_user_id']??0);$sid=(string)($session['id']??'');self::upsertLog($uid,$sid,static function(array $log) use($session,$result): array {
            $message=self::clean((string)($result['message']??''),1200);if($message!==''){$messages=is_array($log['messages']??null)?array_values($log['messages']):[];$messages[]=['role'=>'assistant','content'=>$message,'automation'=>true,'scenario_id'=>self::clean((string)($result['scenario_id']??''),80),'scenario_step_id'=>self::clean((string)($result['step_id']??''),80),'scenario_step_number'=>(int)($result['step_number']??0),'at'=>gmdate('c'),'at_ts'=>time()];$log['messages']=array_slice($messages,-self::MAX_LOG_MESSAGES);}
            if(isset($result['pipeline_stage']))$log['pipeline_stage']=self::stage((string)$result['pipeline_stage']);if(isset($result['control_mode'])){$log['control_mode']=(string)$result['control_mode']==='human'?'human':'ai';if($log['control_mode']==='human')$log['handoff']=true;}if(isset($result['status']))$log['status']=self::clean((string)$result['status'],30);
            $log['last_at']=gmdate('c');return $log;
        });
    }
    public static function findDialog(string $scriptId,string $sessionId): ?array {
        if($sessionId==='')return null;foreach(self::recentDialogs($scriptId,self::MAX_LOGS) as $row)if((string)($row['session_id']??'')===$sessionId)return self::normalizeLog($row);return null;
    }
    public static function updateLead(string $scriptId,string $sessionId,string $name,string $contact,string $stage): array {
        $uid=get_current_user_id();if($uid<1)throw new \RuntimeException('Требуется вход.');$scope=SalesScriptService::currentScopeKey();$name=self::clean($name,120);$contact=self::clean($contact,180);$stage=self::stage($stage);$found=false;$logs=self::readLogs($uid);
        foreach($logs as &$log){if(!is_array($log)||(string)($log['session_id']??'')!==$sessionId||(string)($log['script_id']??'')!==$scriptId||(string)($log['scope']??'default')!==$scope)continue;$log['lead_name']=$name;$log['lead_contact']=$contact;$log['pipeline_stage']=$stage;$log['last_at']=gmdate('c');$found=true;break;}unset($log);
        if(!$found)throw new \InvalidArgumentException('Диалог лида не найден.');self::writeLogs($uid,$logs);return self::findDialog($scriptId,$sessionId)??[];
    }
    public static function recentDialogs(string $scriptId,int $limit=8): array {
        $uid=get_current_user_id();if($uid<1)return [];$scope=SalesScriptService::currentScopeKey();$rows=[];
        foreach(self::readLogs($uid) as $log){if(!is_array($log)||(string)($log['script_id']??'')!==$scriptId||(string)($log['scope']??'default')!==$scope)continue;$rows[]=self::normalizeLog($log);}
        usort($rows,static fn($a,$b): int=>strcmp((string)($b['last_at']??''),(string)($a['last_at']??'')));return array_slice($rows,0,max(1,min(self::MAX_LOGS,$limit)));
    }
    public static function analytics(string $scriptId): array {
        $rows=self::recentDialogs($scriptId,self::MAX_LOGS);$messages=0;$handoffs=0;$ended=0;$human=0;$aiMessages=0;$operatorMessages=0;$aiResponse=[];$operatorResponse=[];$outcomes=['active'=>0,'successful'=>0,'unsuccessful'=>0,'stalled'=>0];
        foreach($rows as $r){
            $list=array_values((array)($r['messages']??[]));$messages+=count($list);if(!empty($r['handoff']))$handoffs++;if((string)($r['status']??'')==='ended')$ended++;if((string)($r['control_mode']??'ai')==='human')$human++;
            $goal=self::goalStatus($r);if(isset($outcomes[$goal]))$outcomes[$goal]++;
            $pendingUserTs=0;foreach($list as $m){if(!is_array($m))continue;$role=(string)($m['role']??'');$ts=self::messageTs($m);if($role==='user'){$pendingUserTs=$ts;continue;}if($role==='assistant'){$aiMessages++;$measured=(float)($m['response_seconds']??0);if($measured>0)$aiResponse[]=$measured;elseif($pendingUserTs>0&&$ts>=$pendingUserTs)$aiResponse[]=$ts-$pendingUserTs;$pendingUserTs=0;}elseif($role==='operator'){$operatorMessages++;if($pendingUserTs>0&&$ts>=$pendingUserTs){$operatorResponse[]=$ts-$pendingUserTs;$pendingUserTs=0;}}}
        }
        $dialogs=count($rows);$successful=$outcomes['successful'];$avgMessages=$dialogs>0?round($messages/$dialogs,1):0.0;$conversion=$dialogs>0?round(($successful/$dialogs)*100,1):0.0;
        $avgAi=$aiResponse?round(array_sum($aiResponse)/count($aiResponse),1):0.0;$avgOperator=$operatorResponse?round(array_sum($operatorResponse)/count($operatorResponse),1):0.0;
        return ['dialogs'=>$dialogs,'messages'=>$messages,'handoffs'=>$handoffs,'ended'=>$ended,'human'=>$human,'successful'=>$successful,'unsuccessful'=>$outcomes['unsuccessful'],'active'=>$outcomes['active'],'stalled'=>$outcomes['stalled'],'conversion_rate'=>$conversion,'avg_messages'=>$avgMessages,'avg_ai_response_seconds'=>$avgAi,'avg_operator_response_seconds'=>$avgOperator,'ai_messages'=>$aiMessages,'operator_messages'=>$operatorMessages];
    }
    public static function analyticsRows(string $scriptId): array { return self::recentDialogs($scriptId,self::MAX_LOGS); }
}
