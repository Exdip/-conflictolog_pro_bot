<?php
namespace CKM\EffectiveSales;
if (!defined('ABSPATH')) { exit; }

final class SalesAdaptivePolygonService {
    private const PASS_FOCUS=75;
    private const TRANSFER_AFTER=3;

    public static function focusCatalog(): array {
        return [
            'customer_understanding'=>[
                'title'=>'Понимание клиента',
                'brief'=>'Клиент сообщает неполную картину и не называет главную проблему напрямую. Значимые ограничения и критерии решения раскрываются только после точных вопросов.',
                'behavior'=>'Не подсказывай продавцу истинную проблему. Внешняя формулировка должна быть шире или немного отличаться от реального мотива.',
            ],
            'question_quality'=>[
                'title'=>'Качество вопросов',
                'brief'=>'Клиент отвечает неоднозначно, иногда уходит в детали и даёт смешанные сигналы. Продавцу нужно выбирать следующий вопрос по предыдущему ответу, а не идти по заготовленному списку.',
                'behavior'=>'Давай реалистичные, но не идеально структурированные ответы. Если продавец задаёт несколько вопросов сразу, отвечай только на часть.',
            ],
            'value_proposition'=>[
                'title'=>'Ценность предложения',
                'brief'=>'Клиент не принимает общие преимущества. У него есть конкретный критерий результата, и ценность нужно связать именно с подтверждённой задачей или эффектом.',
                'behavior'=>'Отвергай общие рекламные аргументы. Реагируй только на ценность, связанную с теми фактами, которые продавец реально выяснил.',
            ],
            'objection_handling'=>[
                'title'=>'Работа с сомнениями',
                'brief'=>'Первое возражение клиента не полностью раскрывает настоящую причину сомнения. За внешней формулировкой скрывается другой риск, сравнение или ограничение.',
                'behavior'=>'Не снимай возражение после первого аргумента. Соглашайся двигаться дальше только если продавец выяснил источник сомнения и ответил именно на него.',
            ],
            'next_step'=>[
                'title'=>'Продвижение сделки',
                'brief'=>'Клиент заинтересован, но сам не организует продолжение. Есть несколько участников или ограничение по времени, поэтому продавцу нужно предложить конкретный уместный следующий шаг.',
                'behavior'=>'Не предлагай за продавца дату, формат или участников следующего контакта. На расплывчатое «будем на связи» не соглашайся как на реальный шаг.',
            ],
        ];
    }

    public static function caseSeed(string $focus,int $level=1,bool $transfer=false): array {
        $level=max(1,min(4,$level));
        $fact=static function(string $code,string $title,string $content,string $partial,string $revealed,float $importance=1.6): array {
            return ['code'=>$code,'title'=>$title,'content'=>$content,'importance'=>$importance,'initial_level'=>0,
                'reveal_rules'=>['partial'=>$partial,'revealed'=>$revealed,'automatic_reveal'=>false]];
        };
        if($transfer){
            $seed=match($focus){
                'question_quality'=>[
                    'opening'=>'Снаружи всё выглядит как несколько разных проблем: сроки плавают, сотрудники жалуются на нагрузку, а качество иногда проседает. Я пока не уверен, что причина одна.',
                    'facts'=>[
                        $fact('adaptive_root_cause','Что связывает симптомы','У нас большая часть сбоев начинается в момент передачи задачи между этапами: часть контекста теряется, и дальше люди компенсируют это ручной работой.','Вопрос о связи между симптомами, этапах процесса или моменте появления проблемы','Прямой вопрос о том, где начинается сбой, что происходит перед ним и какие симптомы имеют общий источник'),
                        $fact('adaptive_priority','Что важнее исправить','Для нас важнее убрать повторную ручную работу и возвраты, чем просто ускорить отдельный этап.','Вопрос о приоритете, главном результате или том, что важнее исправить','Прямой вопрос о приоритетном результате и критерии, по которому мы поймём, что проблема решена'),
                    ]],
                'value_proposition'=>[
                    'opening'=>'Пока я слышу много полезных возможностей, но не понимаю, почему именно сейчас это должно стать для нас приоритетом.',
                    'facts'=>[
                        $fact('adaptive_value_metric','Какой результат имеет ценность','Для нас предложение будет ценным, если оно заметно сократит повторную ручную работу и возвраты задачи на доработку.','Вопрос о ценности, результате, эффекте или критерии успеха','Прямой вопрос о том, какой измеримый или наблюдаемый результат сделает решение ценным для нас'),
                        $fact('adaptive_proof','Как мы готовы проверять ценность','Нас убедит не общий кейс, а ограниченная проверка на нашем процессе с заранее понятным критерием результата.','Вопрос о доказательстве, доверии, пилоте или способе проверить эффект','Прямой вопрос о том, каким способом мы готовы проверить заявленную ценность до большого решения'),
                    ]],
                'objection_handling'=>[
                    'opening'=>'Честно говоря, я не уверен, что хочу снова рисковать. Прошлая попытка что-то изменить дала больше хлопот, чем пользы.',
                    'facts'=>[
                        $fact('adaptive_real_objection','Настоящая причина сомнения','Для нас главная проблема не цена, а риск повторить прошлое неудачное внедрение, когда новое решение быстро перестали использовать.','Вопрос о причине сомнения, прошлом опыте, риске или том, что именно пугает','Прямой вопрос о том, что произошло в прошлый раз и какой риск мы боимся повторить'),
                        $fact('adaptive_risk_condition','Что снизит риск','Мы готовы продолжать, если можно начать с ограниченного пилота и заранее договориться, по каким признакам решим, что он удался или не удался.','Вопрос о снижении риска, безопасном шаге, пилоте или критериях проверки','Прямой вопрос о том, какой формат проверки снизит риск и позволит продолжить без большого обязательства'),
                    ]],
                'next_step'=>[
                    'opening'=>'В целом интересно, но ещё одна встреча просто ради продолжения разговора мне не нужна.',
                    'facts'=>[
                        $fact('adaptive_next_participant','Кого нужно подключить','Мне нужно подключить руководителя процесса: без него мы не сможем оценить, насколько предложение реально внедряемо.','Вопрос о том, кто принимает участие в решении, кого подключить или кто согласует','Прямой вопрос о конкретных участниках следующего шага и их роли в решении'),
                        $fact('adaptive_next_material','Что нужно подготовить','Перед следующей встречей нам нужен короткий план проверки: что тестируем, какой результат считаем успешным и что потребуется от нашей стороны.','Вопрос о подготовке, материалах, повестке или содержании следующего шага','Прямой вопрос о том, что нужно подготовить до следующего контакта, чтобы он имел смысл'),
                    ]],
                default=>[
                    'opening'=>'Мы думаем, что нам просто не хватает возможностей текущего решения, но я не уверен, что это действительно главная проблема.',
                    'facts'=>[
                        $fact('adaptive_manifestation','Где проявляется реальная проблема','У нас сбои возникают прежде всего в нестандартных случаях: когда процесс выходит за привычный шаблон, сотрудникам приходится останавливать работу и подключать руководителя.','Вопрос о том, что именно не устраивает в результате, где проявляется проблема или в каких ситуациях возникает сбой','Прямой вопрос о конкретной ситуации, этапе, исключении, частоте или последствиях нестабильного результата'),
                        $fact('adaptive_criterion','Что будет означать улучшение','Для нас хорошим результатом будет меньше ручных вмешательств руководителя без полной перестройки всего процесса.','Вопрос о критерии решения, хорошем результате, желаемом изменении или том, что должно стать лучше','Прямой вопрос о наблюдаемом критерии, по которому мы поймём, что ситуация действительно улучшилась'),
                    ]],
            };
            return self::withMatchCues($seed);
        }
        $seed=match($focus){
            'question_quality'=>[
                'opening'=>'У нас одновременно есть задержки, лишняя ручная работа и жалобы на качество. Не уверен, что это одна проблема.',
                'facts'=>[
                    $fact('adaptive_root_cause','Где начинается общий сбой','У нас основные проблемы начинаются на передаче задачи между двумя этапами: часть информации теряется, и дальше сотрудники восстанавливают её вручную.','Вопрос о том, где начинается проблема, что происходит между этапами или что объединяет симптомы','Прямой вопрос о конкретном этапе, передаче информации или общем источнике нескольких симптомов'),
                    $fact('adaptive_priority','Главный приоритет','Для нас важнее убрать повторную ручную работу и возвраты, чем просто ускорить каждый этап по отдельности.','Вопрос о приоритете, главной боли или том, что важнее исправить','Прямой вопрос о приоритетном результате и критерии успеха'),
                    $fact('adaptive_disagreement','Почему ответы противоречат друг другу','Одна команда считает причиной инструмент, а другая — регламент, поэтому внутри компании пока нет общего диагноза.','Вопрос о разных версиях проблемы, противоречиях в ответах или позициях участников','Прямой вопрос о том, кто по-разному объясняет проблему и в чём именно расходятся версии'),
                ]],
            'value_proposition'=>[
                'opening'=>'Функции понятны, но пока это звучит как общий список преимуществ. Почему это должно быть важно именно для нас?',
                'facts'=>[
                    $fact('adaptive_value_metric','Какой эффект для нас важен','Для нас реальная ценность — сократить повторную ручную работу и число возвратов задачи на доработку.','Вопрос о ценности, эффекте, результате или том, что изменится для нас','Прямой вопрос о конкретном результате, который сделает предложение полезным именно в нашей ситуации'),
                    $fact('adaptive_proof','Как мы поверим в эффект','Нас убедит небольшой пилот на нашем процессе с заранее согласованным критерием результата, а не общий пример другой компании.','Вопрос о доказательстве, пилоте, кейсе или способе проверить заявленный эффект','Прямой вопрос о том, каким способом мы готовы проверить ценность до большого решения'),
                    $fact('adaptive_tradeoff','Чем можно пожертвовать','Мы готовы отказаться от части дополнительных функций, если решение даёт нужный результат без сложного внедрения.','Вопрос о приоритетах, обязательных функциях, компромиссе или том, что действительно необходимо','Прямой вопрос о том, что обязательно, а чем мы готовы пожертвовать ради более простого решения'),
                ]],
            'objection_handling'=>[
                'opening'=>'Мне кажется, это дорого и рискованно. Мы уже пробовали менять процесс и получили больше проблем, чем пользы.',
                'facts'=>[
                    $fact('adaptive_real_objection','Настоящая причина возражения','Для нас цена — не главная причина сомнения; мы боимся повторить неудачное внедрение, когда сотрудники быстро перестали пользоваться новым решением.','Вопрос о причине сомнения, прошлом опыте, риске или том, что именно стоит за словом «дорого»','Прямой вопрос о том, что произошло в прошлый раз и какой риск мы не хотим повторить'),
                    $fact('adaptive_risk_condition','Что снимет ключевой риск','Мы готовы рассматривать продолжение через ограниченный пилот с понятными критериями успеха и возможностью остановиться без большого перехода.','Вопрос о том, что снизит риск, безопасном формате, пилоте или условиях проверки','Прямой вопрос о формате следующего шага, который позволит проверить решение без большого обязательства'),
                    $fact('adaptive_budget_position','Отношение к бюджету','Если пилот подтвердит полезность и использование сотрудниками, бюджет сам по себе не будет главным препятствием.','Вопрос о бюджете после снятия риска, при каких условиях цена перестанет быть главным препятствием','Прямой вопрос о том, зависит ли решение о деньгах от подтверждения эффекта и снижения риска'),
                ]],
            'next_step'=>[
                'opening'=>'В целом предложение интересно, но я не хочу назначать ещё одну встречу просто ради встречи.',
                'facts'=>[
                    $fact('adaptive_next_participant','Кого нужно подключить','Мне нужно подключить руководителя процесса: без него мы не сможем оценить, насколько предложение реально внедряемо.','Вопрос о том, кто участвует в решении, кого подключить, кто согласует или принимает решение','Прямой вопрос о конкретном участнике следующего шага и его роли'),
                    $fact('adaptive_next_material','Что должно быть готово','Перед следующей встречей нам нужен короткий план проверки: что тестируем, какой результат считаем успешным и что потребуется от нашей стороны.','Вопрос о том, что подготовить, какой должна быть повестка или что нужно до следующей встречи','Прямой вопрос о конкретном материале или результате подготовки, без которого следующий контакт не имеет смысла'),
                    $fact('adaptive_next_commitment','Когда следующий шаг имеет смысл','Я готов подтвердить следующую встречу после того, как увижу план проверки и согласую участие руководителя процесса.','Вопрос о конкретном условии, после которого мы готовы зафиксировать встречу или продолжение','Прямой вопрос о том, что должно произойти, чтобы следующий шаг стал подтверждённым, а не формальным'),
                ]],
            default=>[
                'opening'=>'В целом текущий способ у нас работает, но в нестандартных случаях результат заметно проседает. Пока не уверен, проблема в процессе, людях или самом решении.',
                'facts'=>[
                    $fact('adaptive_manifestation','Где проявляется нестабильный результат','У нас сбои чаще всего возникают, когда ситуация выходит за привычный шаблон: сотрудникам приходится останавливать процесс и подключать руководителя, поэтому часть задач затягивается.','Вопрос о том, что именно не устраивает в результате, где проявляется проблема или в каких ситуациях возникает сбой','Прямой вопрос о конкретной ситуации, этапе, исключении, частоте или последствиях нестабильного результата'),
                    $fact('adaptive_impact','Каковы последствия проблемы','Для нас главный ущерб не в единичных ошибках, а в постоянном ручном вмешательстве руководителя и потере темпа в нестандартных случаях.','Вопрос о последствиях, ущербе, влиянии, ручной работе или том, что происходит из-за проблемы','Прямой вопрос о практических последствиях проблемы для процесса, людей или результата'),
                    $fact('adaptive_criterion','Что будет означать хороший результат','Нам важно сократить ручные вмешательства без полной перестройки всего процесса; безопасная проверка на одном участке для нас приемлемее большой замены сразу.','Вопрос о критерии выбора, хорошем результате, желаемом изменении или допустимом формате проверки','Прямой вопрос о наблюдаемом результате и ограничениях, при которых решение будет для нас приемлемым'),
                ]],
        };
        if($level>=2){
            $seed['opening'].=' При этом мы не готовы сразу менять весь процесс — сначала нужно понять, где именно находится причина.';
            $seed['facts'][]=$fact('adaptive_level2_constraint','Ограничение на изменение','Мы не готовы одновременно менять весь процесс и рабочие правила; сначала нужно доказать эффект на одном ограниченном участке.','Вопрос об ограничениях, допустимом масштабе изменений, пилоте или том, что нельзя менять сразу','Прямой вопрос о границах изменения и формате ограниченной проверки, который для нас приемлем');
        }
        if($level>=3){
            $seed['opening'].=' И внутри компании на проблему смотрят по-разному, поэтому простого ответа от меня не ждите.';
            $seed['facts'][]=$fact('adaptive_level3_view','Вторая точка зрения','Руководитель процесса и сотрудники по-разному объясняют причину проблемы, поэтому решение придётся проверять на фактах, а не выбирать по первой версии.','Вопрос о разных позициях участников, противоречиях, кто как объясняет проблему','Прямой вопрос о том, какие участники по-разному видят причину и как проверить, какая версия верна');
        }
        return self::withMatchCues($seed);
    }

    private static function withMatchCues(array $seed): array {
        $map=[
            'adaptive_manifestation'=>['не устра','прояв','нестандарт','сбой','остан','затяг'],
            'adaptive_impact'=>['последств','ущерб','влия','ручн','вмеш','потер','из-за','остан'],
            'adaptive_criterion'=>['критер','результ','улучш','пойм','успех','что измен'],
            'adaptive_root_cause'=>['причин','начина','источник','связыва','почему'],
            'adaptive_priority'=>['приоритет','важнее','главн','первую очередь'],
            'adaptive_disagreement'=>['разн','противореч','кто счита','верс'],
            'adaptive_value_metric'=>['ценност','эффект','результ','важн','измен'],
            'adaptive_proof'=>['доказ','провер','пилот','убед','подтверд'],
            'adaptive_tradeoff'=>['отказ','пожертв','обязат','необходим','компромисс'],
            'adaptive_real_objection'=>['сомнен','возраж','прошл','опыт','риск','дорог','цена'],
            'adaptive_risk_condition'=>['сниз','риск','безопас','пилот','услов'],
            'adaptive_budget_position'=>['бюджет','цена','деньг','стоим','плат'],
            'adaptive_next_participant'=>['кто','участ','подключ','соглас','лпр','руковод'],
            'adaptive_next_material'=>['подготов','материал','повест','до встреч','план'],
            'adaptive_next_commitment'=>['подтверд','встреч','услов','когда','зафикс'],
            'adaptive_level2_constraint'=>['огранич','масштаб','нельзя','не готовы','пилот','участок'],
            'adaptive_level3_view'=>['разн','позици','кто как','верс','противореч','провер'],
        ];
        foreach((array)($seed['facts']??[]) as $i=>$fact){
            if(!is_array($fact))continue;$code=(string)($fact['code']??'');
            if(!isset($map[$code]))continue;
            $rules=is_array($fact['reveal_rules']??null)?$fact['reveal_rules']:[];
            $rules['match_cues']=$map[$code];$seed['facts'][$i]['reveal_rules']=$rules;
        }
        return $seed;
    }

    private static function defaultFocus(string $class): string {
        return match($class){
            'Ценность'=>'value_proposition',
            'Цена','Конкурент','Риск и недоверие','Особые условия','Сложный клиент'=>'objection_handling',
            'Отсрочка','Продвижение сделки'=>'next_step',
            'Первичный контакт','Сложное решение'=>'question_quality',
            default=>'customer_understanding'
        };
    }

    public static function cases(array $script): array {
        $rows=is_array($script['adaptive_cases']??null)?array_values($script['adaptive_cases']):[];
        usort($rows,static fn(array $a,array $b): int=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')));
        return $rows;
    }
    private static function latestResultForScenario(int $scenarioId,string $mode='training'): ?array {
        if($scenarioId<1)return null;
        try{
            global $wpdb;
            $ctx=\CKM\NegotiationMaster\Access::context();
            $sessions=\CKM\NegotiationMaster\Schema::table('sessions');
            $evaluations=\CKM\NegotiationMaster\Schema::table('evaluations');
            $assignments=\CKM\NegotiationMaster\Schema::table('assignments');
            // Assigned individual training belongs to the same employee's progression.
            // Team competitions and builder tests must never become personal history.
            $attempts=(array)$wpdb->get_results($wpdb->prepare(
                "SELECT se.id,se.mode,se.status,se.completed_at,ev.status AS evaluation_status
                 FROM `$sessions` se
                 INNER JOIN `$evaluations` ev ON ev.session_id=se.id AND ev.status='completed'
                 LEFT JOIN `$assignments` a ON a.id=se.assignment_id AND a.tenant_id=se.tenant_id
                 WHERE se.tenant_id=%d AND se.participant_key=%s AND se.scenario_id=%d AND se.mode=%s
                   AND (se.session_kind='player' OR (se.session_kind='assignment' AND a.assignment_mode='individual'))
                 ORDER BY se.id DESC LIMIT 20",
                (int)$ctx['tenant_id'],(string)$ctx['participant_key'],$scenarioId,$mode
            ),ARRAY_A);
        }catch(\Throwable){return null;}
        $builder=new \CKM\NegotiationMaster\ResultBuilder();
        foreach($attempts as $attempt){
            if(!is_array($attempt)||(string)($attempt['mode']??'training')!==$mode)continue;
            if((string)($attempt['evaluation_status']??'')!=='completed'&&!str_starts_with((string)($attempt['status']??''),'completed_'))continue;
            try{$result=$builder->build((int)$attempt['id']);if(!empty($result['ready']))return ['attempt'=>$attempt,'result'=>$result];}catch(\Throwable){}
        }
        return null;
    }

    private static function weakest(?array $record): ?array {
        if(!$record)return null;$weak=null;
        foreach((array)($record['result']['criteria']??[]) as $row){
            if(!is_array($row)||!isset($row['code'],$row['raw_score'])||!is_numeric($row['raw_score']))continue;
            if($weak===null||(float)$row['raw_score']<(float)$weak['raw_score'])$weak=$row;
        }
        return $weak;
    }

    public static function history(array $script): array {
        $history=[];
        $base=(int)($script['ai_client_scenario_id']??0);
        $r=self::latestResultForScenario($base,'training');
        if($r)$history[]=['case_id'=>'base','scenario_id'=>$base,'focus_code'=>'','level'=>0,'transfer'=>false]+$r;
        foreach(self::cases($script) as $case){
            if(!self::isProgressionCase($case))continue;
            $mode=!empty($case['transfer'])?'exam':'training';
            $r=self::latestResultForScenario((int)($case['scenario_id']??0),$mode);
            if(!$r)continue;
            $history[]=[
                'case_id'=>(string)($case['id']??''),
                'scenario_id'=>(int)($case['scenario_id']??0),
                'focus_code'=>(string)($case['focus_code']??''),
                'level'=>(int)($case['level']??1),
                'transfer'=>!empty($case['transfer']),
            ]+$r;
        }
        usort($history,static function(array $a,array $b): int {
            $aa=(string)($a['result']['completed_at']??$a['attempt']['completed_at']??'');
            $bb=(string)($b['result']['completed_at']??$b['attempt']['completed_at']??'');
            return strcmp($bb,$aa);
        });
        return $history;
    }

    private static function pendingCase(array $script): ?array {
        foreach(self::cases($script) as $case){
            if(!self::isProgressionCase($case))continue;
            $mode=!empty($case['transfer'])?'exam':'training';
            if(self::latestResultForScenario((int)($case['scenario_id']??0),$mode)===null)return $case;
        }
        return null;
    }

    public static function isProgressionCase(array $case): bool {
        return (int)($case['adaptive_schema']??1)>=3
            && (int)($case['recertification_revision']??0)<1;
    }

    public static function recommendation(array $script): array {
        $pending=self::pendingCase($script);
        if($pending)return ['pending'=>true,'case'=>$pending]+$pending;
        return self::recommendationFromHistory($script,self::history($script));
    }

    /** Pure progression decision; history uses the same newest-first records as history(). */
    public static function recommendationFromHistory(array $script,array $history): array {
        $latest=$history[0]??null;$weak=self::weakest($latest);
        $adaptiveCompleted=array_values(array_filter($history,static fn(array $h): bool=>(string)($h['case_id']??'')!=='base'&&empty($h['transfer'])));
        $focus=(string)($weak['code']??'');$catalog=self::focusCatalog();
        if(!array_key_exists($focus,$catalog))$focus=self::defaultFocus((string)($script['situation_class']??'Диагностика'));
        $meta=$catalog[$focus];
        $weakScore=$weak!==null?(float)($weak['raw_score']??0):null;
        $latestScore=$latest!==null?(float)($latest['result']['final_score']??0):null;
        if($latest!==null&&!empty($latest['transfer'])&&$weakScore!==null&&$weakScore>=self::PASS_FOCUS&&$latestScore!==null&&$latestScore>=self::PASS_FOCUS){
            return [
                'pending'=>false,'mastered'=>true,'focus_code'=>$focus,'focus_title'=>(string)$meta['title'],'level'=>4,'transfer'=>false,
                'why'=>'Контроль переноса пройден: результат устойчив и на незнакомой ситуации.','brief'=>'Навык подтверждён без подсказок и без повторения учебного кейса.',
                'behavior'=>'','history_count'=>count($history),'adaptive_completed'=>count($adaptiveCompleted),'weak_score'=>$weakScore,'latest_score'=>$latestScore,
            ];
        }
        $transfer=count($adaptiveCompleted)>=self::TRANSFER_AFTER&&$weakScore!==null&&$weakScore>=self::PASS_FOCUS&&$latestScore!==null&&$latestScore>=self::PASS_FOCUS;

        $focusCount=0;foreach($adaptiveCompleted as $h)if((string)($h['focus_code']??'')===$focus)$focusCount++;
        $level=$transfer?4:min(3,1+$focusCount);
        $why=$weak!==null
            ? 'В последней завершённой попытке самый низкий результат — «'.(string)($weak['title']??$meta['title']).'»: '.(int)round((float)$weak['raw_score']).'/100.'
            : 'Это стартовый фокус для выбранного класса ситуации продаж.';
        if($transfer)$why='Три адаптивные тренировки пройдены, а слабейший критерий достиг '.(int)round((float)$weakScore).'/100. Пора проверить перенос навыка на незнакомую ситуацию.';
        return [
            'pending'=>false,'focus_code'=>$focus,'focus_title'=>(string)$meta['title'],'level'=>$level,'transfer'=>$transfer,
            'why'=>$why,'brief'=>(string)$meta['brief'],'behavior'=>(string)$meta['behavior'],
            'history_count'=>count($history),'adaptive_completed'=>count($adaptiveCompleted),'weak_score'=>$weakScore,'latest_score'=>$latestScore,
        ];
    }

    private static function levelLabel(int $level,bool $transfer): string {
        if($transfer)return 'Контроль переноса';
        return match($level){1=>'Вариация',2=>'Усложнение',default=>'Комбинированная ситуация'};
    }

    public static function createCaseForFocus(string $scriptId,string $focus,int $level=1,array $context=[]): array {
        $script=SalesScriptService::find($scriptId);if(!$script)throw new \InvalidArgumentException('Методика не найдена.');
        if((string)($script['status']??'draft')!=='approved')throw new \RuntimeException('Сначала утвердите методику.');
        $catalog=self::focusCatalog();if(!isset($catalog[$focus]))throw new \InvalidArgumentException('Неизвестный навык для тренировки.');
        $level=max(1,min(3,$level));$meta=$catalog[$focus];
        $caseId='adaptive_'.substr(hash('sha256',$scriptId.'|assign|'.$focus.'|'.$level.'|'.microtime(true).'|'.wp_generate_uuid4()),0,18);
        $seed=self::caseSeed($focus,$level,false);
        // Practice contributes a situation pattern, never an original client message.
        $fromPractice=trim((string)($context['practice_source_session_id']??''))!=='';
        $customOpening=$fromPractice?'':trim(wp_strip_all_tags((string)($context['opening']??'')));
        if($customOpening!=='')$seed['opening']=function_exists('mb_substr')?mb_substr($customOpening,0,700,'UTF-8'):substr($customOpening,0,700);
        $why=trim(wp_strip_all_tags((string)($context['why']??'')));if($why==='')$why='Назначено руководителем по фактическому слабому критерию сотрудника.';
        $spec=[
            'id'=>$caseId,'focus_code'=>$focus,'focus_title'=>(string)$meta['title'],'level'=>$level,'transfer'=>false,
            'label'=>self::levelLabel($level,false).' · '.(string)$meta['title'],'brief'=>(string)$meta['brief'],'behavior'=>(string)$meta['behavior'],
            'why'=>$why,'opening'=>(string)($seed['opening']??''),'facts'=>(array)($seed['facts']??[]),'adaptive_schema'=>3,
        ];
        foreach(['practice_source_session_id','practice_source_channel','practice_source_outcome','recertification_revision','recertification_focus'] as $contextKey){
            if(isset($context[$contextKey])&&trim((string)$context[$contextKey])!=='')$spec[$contextKey]=trim((string)$context[$contextKey]);
        }
        $client=(new SalesScriptClientService())->generateAdaptiveCase($scriptId,$spec);
        $case=$spec+[
            'scenario_id'=>(int)($client['scenario_id']??0),'scenario_version_id'=>(int)($client['scenario_version_id']??0),
            'generation_source'=>(string)($client['generation_source']??'fallback'),'created_at'=>current_time('mysql'),
        ];
        SalesScriptService::mutate($scriptId,static function(array $row) use($case): array {
            $cases=is_array($row['adaptive_cases']??null)?array_values($row['adaptive_cases']):[];$cases[]=$case;
            $row['adaptive_cases']=array_slice($cases,-24);return $row;
        });
        return $case;
    }

    public static function createNextCase(string $scriptId): array {
        $script=SalesScriptService::find($scriptId);if(!$script)throw new \InvalidArgumentException('Методика не найдена.');
        if((string)($script['status']??'draft')!=='approved')throw new \RuntimeException('Сначала утвердите методику.');
        $pending=self::pendingCase($script);if($pending)return $pending;
        $base=(int)($script['ai_client_scenario_id']??0);
        if($base<1||self::latestResultForScenario($base,'training')===null)throw new \RuntimeException('Сначала завершите базовую тренировку. После неё Полигон подберёт кейс по фактическому слабому месту.');
        $rec=self::recommendation($script);
        if(!empty($rec['mastered']))throw new \RuntimeException('Адаптивный цикл уже подтверждён контролем переноса.');$focus=(string)$rec['focus_code'];$level=(int)$rec['level'];$transfer=!empty($rec['transfer']);
        $caseId='adaptive_'.substr(hash('sha256',$scriptId.'|'.$focus.'|'.$level.'|'.microtime(true).'|'.wp_generate_uuid4()),0,18);
        $seed=self::caseSeed($focus,$level,$transfer);
        $spec=[
            'id'=>$caseId,'focus_code'=>$focus,'focus_title'=>(string)$rec['focus_title'],'level'=>$level,'transfer'=>$transfer,
            'label'=>self::levelLabel($level,$transfer).' · '.(string)$rec['focus_title'],'brief'=>(string)$rec['brief'],'behavior'=>(string)$rec['behavior'],
            'why'=>(string)$rec['why'],'opening'=>(string)($seed['opening']??''),'facts'=>(array)($seed['facts']??[]),'adaptive_schema'=>3,
        ];
        $client=(new SalesScriptClientService())->generateAdaptiveCase($scriptId,$spec);
        $case=$spec+[
            'scenario_id'=>(int)($client['scenario_id']??0),'scenario_version_id'=>(int)($client['scenario_version_id']??0),
            'generation_source'=>(string)($client['generation_source']??'fallback'),'created_at'=>current_time('mysql'),
        ];
        SalesScriptService::mutate($scriptId,static function(array $row) use($case): array {
            $cases=is_array($row['adaptive_cases']??null)?array_values($row['adaptive_cases']):[];$cases[]=$case;
            $row['adaptive_cases']=array_slice($cases,-24);return $row;
        });
        return $case;
    }
}
