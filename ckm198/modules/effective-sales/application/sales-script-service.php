<?php
namespace CKM\EffectiveSales;
if (!defined('ABSPATH')) { exit; }

final class SalesScriptService {
    private const META_KEY='ckm_sales_scripts_v1';
    private const MAX_SCRIPTS=100;

    public static function classes(): array {
        return ['Первичный контакт','Диагностика','Ценность','Цена','Отсрочка','Конкурент','Сложное решение','Риск и недоверие','Особые условия','Продвижение сделки','Сложный клиент','Экспертная продажа'];
    }

    private static function userId(): int { return get_current_user_id(); }
    private static function scopeKey(): string {
        $scope=function_exists('ckmqp_scope_id')?(string)ckmqp_scope_id():'default';
        return $scope!==''?$scope:'default';
    }
    public static function currentScopeKey(): string { return self::scopeKey(); }
    public static function findForOwner(int $userId,string $scope,string $id): ?array {
        if($userId<1||$id==='')return null;
        $all=get_user_meta($userId,self::META_KEY,true);
        if(!is_array($all))return null;
        $items=$all[$scope!==''?$scope:'default']??[];
        if(!is_array($items))return null;
        foreach($items as $item)if(is_array($item)&&(string)($item['id']??'')===$id)return $item;
        return null;
    }
    private static function readAllScopes(): array {
        $uid=self::userId(); if($uid<1)return [];
        $v=get_user_meta($uid,self::META_KEY,true);
        return is_array($v)?$v:[];
    }
    private static function writeAllScopes(array $all): void {
        $uid=self::userId(); if($uid<1)throw new \RuntimeException('Для сохранения скрипта необходимо войти.');
        update_user_meta($uid,self::META_KEY,$all);
    }
    public static function all(): array {
        $all=self::readAllScopes();$items=$all[self::scopeKey()]??[];
        if(!is_array($items))return [];
        usort($items,static fn(array $a,array $b): int=>strcmp((string)($b['updated_at']??''),(string)($a['updated_at']??'')));
        return array_values($items);
    }
    public static function find(string $id): ?array {
        foreach(self::all() as $item)if((string)($item['id']??'')===$id)return $item;
        return null;
    }
    private static function clean(string $v,int $max=1200): string {
        $v=trim(wp_strip_all_tags($v));
        return function_exists('mb_substr')?mb_substr($v,0,$max):substr($v,0,$max);
    }
    public static function create(array $data): array {
        $title=self::clean((string)($data['title']??''),160);
        $product=self::clean((string)($data['product']??''),700);
        $client=self::clean((string)($data['client']??''),700);
        $class=self::clean((string)($data['situation_class']??''),80);
        $goal=self::clean((string)($data['goal']??''),700);
        $constraints=self::clean((string)($data['constraints']??''),900);
        if($title===''||$product===''||$client===''||$goal==='')throw new \InvalidArgumentException('Заполните название, продукт, клиента и цель разговора.');
        if(!in_array($class,self::classes(),true))throw new \InvalidArgumentException('Выберите класс ситуации продаж.');
        $all=self::readAllScopes();$key=self::scopeKey();$items=is_array($all[$key]??null)?array_values($all[$key]):[];
        if(count($items)>=self::MAX_SCRIPTS)throw new \RuntimeException('Достигнут лимит сохранённых скриптов.');
        $now=current_time('mysql');
        $item=['id'=>wp_generate_uuid4(),'title'=>$title,'product'=>$product,'client'=>$client,'situation_class'=>$class,'goal'=>$goal,'constraints'=>$constraints,'status'=>'draft','created_at'=>$now,'updated_at'=>$now];
        $items[]=$item;$all[$key]=$items;self::writeAllScopes($all);return $item;
    }
    public static function createAdaptiveSalesTemplate(): array {
        foreach(self::all() as $existing){
            if((string)($existing['template_key']??'')==='adaptive-sales-script')return $existing;
        }
        $item=self::create([
            'title'=>'Продажа адаптивных скриптов продаж',
            'product'=>'Разработка адаптивных скриптов продаж: сценарий меняет следующий вопрос, аргументацию и следующий шаг по ответам клиента, сохраняет контекст и может работать как подсказчик менеджеру или как ИИ-продавец. Первичный аудит текущих диалогов бесплатный. Пилот и полная разработка платные; точная стоимость зависит от объёма, каналов и интеграций и не должна выдумываться заранее.',
            'client'=>'Собственник, коммерческий директор или руководитель отдела продаж, у которого уже есть менеджеры, скрипты, CRM или ИИ-инструменты и который хочет повысить управляемость реальных продаж.',
            'situation_class'=>'Диагностика',
            'goal'=>'Выявить, где текущий линейный скрипт или свободная импровизация перестают работать; показать адаптивность прямо на текущем разговоре; при подтверждённой ценности договориться о бесплатном аудите нескольких реальных диалогов или о пилоте.',
            'constraints'=>'Не выдумывать цену, скидку, сроки, ROI, гарантии и возможности интеграций. Не обесценивать существующий скрипт клиента: предлагать локальное усиление, если проблема локальная. Бесплатным является только первичный аудит; пилот и разработка — платные. Сначала отвечать на прямой вопрос клиента, затем возвращаться к диагностике без повторения уже известных вопросов.',
        ]);
        return self::mutate((string)$item['id'],static function(array $row): array {
            $row['template_key']='adaptive-sales-script';
            $row['adaptive_seller_mode']=true;
            $row['ai_seller_name']='ИИ-методолог продаж';
            $row['ai_seller_tone']='Деловой, спокойный, диагностический; без давления и рекламных штампов';
            $row['ai_seller_primary_goal']='Диагностировать реальную проблему продаж, продемонстрировать адаптивность на самом разговоре и согласовать следующий проверяемый шаг.';
            $row['ai_seller_knowledge']=[
                ['id'=>'kb_adaptive_sales_commercial','type'=>'text','title'=>'Коммерческая логика','url'=>'','content'=>'Первичный аудит текущих диалогов клиента бесплатный. Пилот и полная разработка адаптивного скрипта платные. Если точная цена не задана отдельным подтверждённым источником, ИИ-продавец не называет число и объясняет, что стоимость определяется после оценки объёма, каналов и интеграций.','created_at'=>current_time('mysql')],
                ['id'=>'kb_adaptive_sales_product','type'=>'text','title'=>'Что такое адаптивный скрипт','url'=>'','content'=>'Адаптивный скрипт — не заученный текст. Он хранит известные факты о клиенте, различает прямой вопрос, возражение и диагностический ответ, выбирает следующий уместный шаг и не повторяет вопрос, на который клиент уже ответил. Его можно использовать как подсказчик менеджеру или как основу ИИ-продавца.','created_at'=>current_time('mysql')],
            ];
            return $row;
        });
    }

    public static function approve(string $id): array {
        $all=self::readAllScopes();$key=self::scopeKey();$items=is_array($all[$key]??null)?array_values($all[$key]):[];
        foreach($items as &$item){if((string)($item['id']??'')!==$id)continue;$item['status']='approved';$item['updated_at']=current_time('mysql');$all[$key]=$items;self::writeAllScopes($all);return $item;}unset($item);
        throw new \InvalidArgumentException('Скрипт не найден.');
    }
    public static function attachAiClient(string $id,array $client): array {
        $all=self::readAllScopes();$key=self::scopeKey();$items=is_array($all[$key]??null)?array_values($all[$key]):[];
        foreach($items as &$item){
            if((string)($item['id']??'')!==$id)continue;
            $item['ai_client_scenario_id']=(int)($client['scenario_id']??0);
            $item['ai_client_version_id']=(int)($client['scenario_version_id']??0);
            $item['ai_client_version_number']=(int)($client['version_number']??0);
            $item['ai_client_name']=self::clean((string)($client['client_name']??''),120);
            $item['ai_client_role']=self::clean((string)($client['client_role']??''),220);
            $item['ai_client_opening']=self::clean((string)($client['opening_message']??''),700);
            $item['ai_client_hidden_fact_count']=max(0,(int)($client['hidden_fact_count']??0));
            $item['ai_client_generation_source']=self::clean((string)($client['generation_source']??''),40);
            $item['ai_client_generated_at']=self::clean((string)($client['generated_at']??''),40);
            $item['updated_at']=current_time('mysql');
            $all[$key]=$items;self::writeAllScopes($all);return $item;
        }unset($item);
        throw new \InvalidArgumentException('Скрипт не найден.');
    }

    public static function mutate(string $id,callable $mutator): array { return self::updateItem($id,$mutator); }

    private static function updateItem(string $id,callable $mutator): array {
        $all=self::readAllScopes();$key=self::scopeKey();$items=is_array($all[$key]??null)?array_values($all[$key]):[];
        foreach($items as &$item){
            if((string)($item['id']??'')!==$id)continue;
            $next=$mutator($item);if(!is_array($next))$next=$item;$next['updated_at']=current_time('mysql');$item=$next;
            $all[$key]=$items;self::writeAllScopes($all);return $item;
        }unset($item);
        throw new \InvalidArgumentException('Скрипт не найден.');
    }

    public static function connectAiSeller(string $id): array {
        $script=self::find($id);if(!$script)throw new \InvalidArgumentException('Скрипт не найден.');
        if((string)($script['status']??'draft')!=='approved')throw new \RuntimeException('Сначала утвердите скрипт, затем подключите его к ИИ-продавцу.');
        $token=(string)($script['ai_seller_token']??'');
        if(strlen($token)<32){try{$token=bin2hex(random_bytes(24));}catch(\Throwable){$token=hash('sha256',wp_generate_uuid4().microtime(true));}}
        $scope=self::scopeKey();$uid=self::userId();$hash=hash('sha256',$token);
        $index=['user_id'=>$uid,'scope'=>$scope,'script_id'=>$id,'created_at'=>current_time('mysql')];
        update_option('ckm_sales_ai_seller_'.$hash,$index,false);
        return self::updateItem($id,static function(array $item) use($token): array {
            $item['ai_seller_enabled']=true;$item['ai_seller_token']=$token;$item['ai_seller_connected_at']=current_time('mysql');return $item;
        });
    }

    public static function disconnectAiSeller(string $id): array {
        $script=self::find($id);if(!$script)throw new \InvalidArgumentException('Скрипт не найден.');
        $token=(string)($script['ai_seller_token']??'');if($token!=='')delete_option('ckm_sales_ai_seller_'.hash('sha256',$token));
        return self::updateItem($id,static function(array $item): array {
            $item['ai_seller_enabled']=false;$item['ai_seller_token']='';$item['ai_seller_connected_at']='';return $item;
        });
    }

    public static function resolveAiSellerToken(string $token): ?array {
        $token=trim($token);if(strlen($token)<32||strlen($token)>160)return null;
        $index=get_option('ckm_sales_ai_seller_'.hash('sha256',$token));if(!is_array($index))return null;
        $uid=(int)($index['user_id']??0);$scope=(string)($index['scope']??'default');$id=(string)($index['script_id']??'');
        $script=self::findForOwner($uid,$scope,$id);if(!$script||empty($script['ai_seller_enabled']))return null;
        $stored=(string)($script['ai_seller_token']??'');if($stored===''||!hash_equals($stored,$token))return null;
        return ['user_id'=>$uid,'scope'=>$scope,'script'=>$script];
    }

    public static function remove(string $id): void {
        $all=self::readAllScopes();$key=self::scopeKey();$items=is_array($all[$key]??null)?array_values($all[$key]):[];
        $next=array_values(array_filter($items,static fn(array $x): bool=>(string)($x['id']??'')!==$id));
        if(count($next)===count($items))throw new \InvalidArgumentException('Скрипт не найден.');
        $all[$key]=$next;self::writeAllScopes($all);
    }

    public static function playbook(string $class): array {
        $base=[
            'Первичный контакт'=>[
                ['Получить право на диалог','Коротко объясните причину обращения и проверьте, готов ли клиент уделить 1–2 минуты.'],
                ['Проверить актуальность','Задайте 1–2 вопроса о ситуации клиента вместо длинной презентации.'],
                ['Дать микроценность','Свяжите одну выгоду продукта с обнаруженной задачей клиента.'],
                ['Зафиксировать лёгкий следующий шаг','Предложите демо, короткий звонок или отправку конкретного материала с датой возврата к разговору.'],
            ],
            'Диагностика'=>[
                ['Разобраться в текущей ситуации','Выясните, как клиент решает задачу сейчас и что его не устраивает.'],
                ['Углубить проблему','Проясните последствия, частоту, масштаб и стоимость проблемы.'],
                ['Проверить условия решения','Уточните бюджет, участников решения, ограничения и сроки.'],
                ['Сформулировать картину клиента','Кратко резюмируйте услышанное и согласуйте, что именно стоит решать дальше.'],
            ],
            'Ценность'=>[
                ['Подтвердить потребность','Верните клиента к уже выявленной проблеме и критериям выбора.'],
                ['Связать решение с задачей','Показывайте только те свойства, которые дают конкретную выгоду этому клиенту.'],
                ['Оцифровать эффект','Используйте цифры клиента: экономия, выручка, риск, время или производительность.'],
                ['Проверить ценность','Спросите, насколько такой эффект важен, и предложите следующий шаг.'],
            ],
            'Цена'=>[
                ['Не спорить с ценой','Признайте сомнение и выясните, что именно означает «дорого».'],
                ['Найти причину','Разделите бюджетное ограничение, разрыв ценности и сравнение с альтернативой.'],
                ['Перевести цену в экономику','Свяжите стоимость с результатом, ROI или стоимостью бездействия.'],
                ['Договориться о проверке','Зафиксируйте расчёт, встречу или иной шаг без автоматической скидки.'],
            ],
            'Отсрочка'=>[
                ['Принять паузу без давления','Не спорьте с фразой «мне надо подумать».'],
                ['Раскрыть причину','Уточните: неясна ценность, цена, нужен ЛПР или нет срочности.'],
                ['Закрыть информационный пробел','Дайте только ту информацию, которая нужна для решения.'],
                ['Назначить возврат','Зафиксируйте дату, участников и предмет следующего контакта.'],
            ],
            'Конкурент'=>[
                ['Уважить текущий выбор','Не критикуйте действующего поставщика или решение клиента.'],
                ['Найти ограничения','Выясните, что работает хорошо, а где остаются неудобства или риски.'],
                ['Показать различие','Свяжите отличие вашего решения только с выявленным дефицитом.'],
                ['Предложить безопасную проверку','Демо, сравнение, пилот или расчёт без требования немедленной замены.'],
            ],
            'Сложное решение'=>[
                ['Картировать участников','Выясните, кто пользуется, влияет, согласует и окончательно утверждает.'],
                ['Понять критерии каждого','Разделите бизнес-, технические, финансовые и юридические требования.'],
                ['Подготовить общий кейс','Соберите аргументы, которые выдержат обсуждение всеми участниками.'],
                ['Организовать совместный шаг','Назначьте встречу или согласуйте пакет материалов для группы решения.'],
            ],
            'Риск и недоверие'=>[
                ['Назвать опасение','Дайте клиенту спокойно объяснить, чего он боится и какой опыт к этому привёл.'],
                ['Разобрать риск','Уточните вероятность, последствия и критерии безопасного внедрения.'],
                ['Снизить риск доказательствами','Предложите пилот, этапность, гарантии, кейс или техническую проверку.'],
                ['Зафиксировать безопасный шаг','Продвиньте сделку только настолько, насколько клиент готов проверить риск.'],
            ],
            'Особые условия'=>[
                ['Понять мотив требования','Выясните, зачем клиенту скидка, постоплата или дополнительная услуга.'],
                ['Оценить экономику','Не отдавайте уступку автоматически. Определите её цену для компании.'],
                ['Обменять, а не подарить','Используйте принцип «да, но»: встречный объём, предоплата, срок, пакет или обязательство.'],
                ['Зафиксировать пакет','Согласуйте взаимные условия как единое предложение.'],
            ],
            'Продвижение сделки'=>[
                ['Подвести итог','Коротко зафиксируйте, что уже выяснено и в чём клиент видит ценность.'],
                ['Уточнить препятствие','Проверьте, что ещё мешает принять следующий шаг.'],
                ['Предложить конкретное действие','Назовите действие, участников, результат и срок.'],
                ['Получить явное подтверждение','Следующий шаг считается достигнутым только после согласия клиента.'],
            ],
            'Сложный клиент'=>[
                ['Сохранить рабочий тон','Не отвечайте на давление раздражением и не обещайте невыполнимое.'],
                ['Отделить интерес от поведения','Выясните реальную деловую потребность за жёсткой формой общения.'],
                ['Обозначить границы','Предложите допустимые варианты; при уступке требуйте встречное условие.'],
                ['Эскалировать при необходимости','Если границы нарушаются, подключите руководителя или остановите опасную сделку.'],
            ],
            'Экспертная продажа'=>[
                ['Диагностировать без привязки к продукту','Сначала определите реальную задачу и ограничения клиента.'],
                ['Проверить соответствие решения','Сравните запрос с тем, что действительно нужно клиенту.'],
                ['Сказать правду о fit','Если ваш продукт избыточен или не подходит, предложите более разумный вариант.'],
                ['Сохранить доверие и связь','Зафиксируйте полезный следующий контакт, даже если продажи сейчас не будет.'],
            ],
        ];
        return $base[$class]??$base['Диагностика'];
    }
}
