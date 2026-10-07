<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class ScenarioBuilderAccessDeniedException extends \RuntimeException {}
final class ScenarioBuilderValidationException extends \InvalidArgumentException {
    private array $issues;
    public function __construct(array $issues) {
        $this->issues = array_values($issues);
        parent::__construct($this->issues ? implode(' ', $this->issues) : 'Сценарий не прошёл проверку.');
    }
    public function issues(): array { return $this->issues; }
}

/**
 * Frontend scenario constructor. Published versions are immutable; editing a published
 * scenario creates a new draft version and switches current_version_id only on publish.
 */
final class ScenarioBuilderService {
    private const ITEM_TYPES = ['money','number','decimal','integer','int','percent','term','date','select','bool','boolean','free','text'];
    private const DIRECTIONS = ['higher_better','lower_better','target_value','categorical','custom_rule',''];
    private const REOPEN = ['never','with_reason','free_until_final','explicit_mutual_confirmation',''];
    private const EVAL_TYPES = ['php','ai','hybrid','rubric'];
    private const RULE_TYPES = ['boundary','package_constraint','completeness','walkaway','confirmation','hard_constraint','process','red_line','dependency','agreement_requirement','validation'];

    public static function canUse(): bool {
        if (!is_user_logged_in()) { return false; }
        if (current_user_can('manage_options')) { return true; }
        if (!function_exists('ckm_quiz_pro_can_use_front_constructor') || !ckm_quiz_pro_can_use_front_constructor()) { return false; }
        if (function_exists('ckm_quiz_pro_can_access_format')) {
            $uid = function_exists('ckm_quiz_pro_effective_organizer_user_id') ? (int)ckm_quiz_pro_effective_organizer_user_id() : (int)get_current_user_id();
            if (!ckm_quiz_pro_can_access_format($uid, 'negotiation_duel_v1')) { return false; }
        }
        return true;
    }

    public static function assertUse(): array {
        if (!self::canUse()) { throw new ScenarioBuilderAccessDeniedException('Конструктор сценариев недоступен для этого аккаунта.'); }
        return Access::context();
    }

    private static function now(): string { return current_time('mysql', true); }
    private static function text(mixed $value, int $max = 12000): string {
        $text = trim(wp_unslash((string)$value));
        if (function_exists('mb_substr')) { $text = mb_substr($text, 0, $max); }
        else { $text = substr($text, 0, $max); }
        return sanitize_textarea_field($text);
    }
    private static function short(mixed $value, int $max = 255): string {
        $text = trim(wp_unslash((string)$value));
        if (function_exists('mb_substr')) { $text = mb_substr($text, 0, $max); }
        else { $text = substr($text, 0, $max); }
        return sanitize_text_field($text);
    }
    private static function code(mixed $value, string $fallback = ''): string {
        $code = sanitize_key((string)$value);
        $code = str_replace('-', '_', $code);
        if ($code === '' && $fallback !== '') { $code = sanitize_key($fallback); $code = str_replace('-', '_', $code); }
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code)) { throw new ScenarioBuilderValidationException(['Код должен начинаться с латинской буквы и содержать только a-z, 0-9 и _.']); }
        return $code;
    }
    private static function jsonValue(mixed $value, mixed $default = []): mixed {
        if ($value === null || $value === '') { return $default; }
        if (is_array($value) || is_bool($value) || is_int($value) || is_float($value)) { return $value; }
        if (is_string($value)) {
            try { return json_decode(wp_unslash($value), true, 512, JSON_THROW_ON_ERROR); }
            catch (\Throwable) { throw new ScenarioBuilderValidationException(['Одно из JSON-полей заполнено некорректно.']); }
        }
        throw new ScenarioBuilderValidationException(['Некорректное значение структурированного поля.']);
    }
    private static function json(mixed $value, mixed $default = []): string {
        return wp_json_encode(self::jsonValue($value, $default), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    private static function bool(mixed $value): int { return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0; }

    private function editableScenario(int $scenarioId): array {
        global $wpdb;
        $context = self::assertUse();
        $table = Schema::table('scenarios');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE id=%d", $scenarioId), ARRAY_A);
        if (!$row || $row['tenant_id'] === null || (int)$row['tenant_id'] !== (int)$context['tenant_id']) {
            throw new ScenarioBuilderAccessDeniedException('Можно редактировать только сценарии своей площадки.');
        }
        if (!$context['admin'] && (int)$row['created_by'] !== (int)$context['user_id']) {
            throw new ScenarioBuilderAccessDeniedException('Можно редактировать только собственные сценарии.');
        }
        return $row;
    }

    private function latestDraft(array $scenario): ?array {
        global $wpdb;
        $table = Schema::table('scenario_versions');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE scenario_id=%d AND status='draft' ORDER BY version_number DESC,id DESC LIMIT 1", (int)$scenario['id']), ARRAY_A) ?: null;
    }

    private function currentVersion(array $scenario): ?array {
        global $wpdb;
        $id = (int)($scenario['current_version_id'] ?? 0);
        if ($id <= 0) { return null; }
        $table = Schema::table('scenario_versions');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE id=%d AND scenario_id=%d", $id, (int)$scenario['id']), ARRAY_A) ?: null;
    }

    private function components(int $versionId): array {
        global $wpdb;
        $out = [];
        foreach (['hidden_facts','items','rules','evaluation_rules'] as $key) {
            $table = Schema::table($key);
            $order = in_array($key, ['hidden_facts','items','evaluation_rules'], true) ? 'sort_order ASC,id ASC' : 'priority DESC,id ASC';
            $out[$key] = (array)$wpdb->get_results($wpdb->prepare("SELECT * FROM `$table` WHERE scenario_version_id=%d ORDER BY $order", $versionId), ARRAY_A);
        }
        return $out;
    }

    private function deepCloneVersion(array $scenario, array $source): array {
        global $wpdb;
        $versions = Schema::table('scenario_versions');
        $next = 1 + (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(version_number),0) FROM `$versions` WHERE scenario_id=%d", (int)$scenario['id']));
        $data = $source;
        unset($data['id']);
        $data['scenario_id'] = (int)$scenario['id'];
        $data['version_number'] = $next;
        $data['status'] = 'draft';
        $data['created_at'] = self::now();
        $data['published_at'] = null;
        if ($wpdb->insert($versions, $data) === false) { throw new \RuntimeException('Не удалось создать новую версию сценария.'); }
        $newId = (int)$wpdb->insert_id;
        foreach ($this->components((int)$source['id']) as $key => $rows) {
            $table = Schema::table($key);
            foreach ($rows as $row) {
                unset($row['id']); $row['scenario_version_id'] = $newId;
                if ($wpdb->insert($table, $row) === false) { throw new \RuntimeException('Не удалось скопировать данные сценария.'); }
            }
        }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM `$versions` WHERE id=%d", $newId), ARRAY_A);
    }

    private function ensureDraft(array $scenario): array {
        $draft = $this->latestDraft($scenario);
        if ($draft) { return $draft; }
        $current = $this->currentVersion($scenario);
        if (!$current) { throw new \RuntimeException('У сценария отсутствует версия для редактирования.'); }
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) { throw new \RuntimeException('Не удалось начать создание версии.'); }
        try {
            $draft = $this->deepCloneVersion($scenario, $current);
            if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException('Не удалось сохранить новую версию.'); }
            return $draft;
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
    }

    private function uniqueSlug(string $title, int $tenantId, int $userId): string {
        global $wpdb;
        // Public launch identity must not depend on a localized title. WordPress
        // sanitize_title() percent-encodes Cyrillic, and byte substr() can cut an
        // encoded UTF-8 sequence. Keep new custom slugs ASCII-only and stable.
        $base = 'custom-' . max(1, $userId) . '-scenario';
        $table = Schema::table('scenarios');
        for ($i=0; $i<1000; $i++) {
            $slug = $base . ($i ? '-' . ($i+1) : '');
            $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE tenant_id=%d AND slug=%s", $tenantId, $slug));
            if (!$exists) { return $slug; }
        }
        throw new \RuntimeException('Не удалось сформировать уникальный адрес сценария.');
    }

    public function listOwn(): array {
        global $wpdb;
        $context = self::assertUse();
        $s = Schema::table('scenarios'); $v = Schema::table('scenario_versions');
        $owner = $context['admin'] ? '' : $wpdb->prepare(' AND s.created_by=%d', (int)$context['user_id']);
        $rows = (array)$wpdb->get_results($wpdb->prepare(
            "SELECT s.id,s.slug,s.title,s.status,s.current_version_id,s.source_scenario_id,s.updated_at,
                    (SELECT vv.id FROM `$v` vv WHERE vv.scenario_id=s.id AND vv.status='draft' ORDER BY vv.version_number DESC,vv.id DESC LIMIT 1) AS draft_version_id,
                    (SELECT MAX(vv2.version_number) FROM `$v` vv2 WHERE vv2.scenario_id=s.id) AS latest_version_number
             FROM `$s` s WHERE s.tenant_id=%d $owner ORDER BY s.updated_at DESC,s.id DESC", (int)$context['tenant_id']
        ), ARRAY_A);
        return $rows;
    }

    public function templates(): array {
        global $wpdb;
        self::assertUse();
        $s = Schema::table('scenarios'); $l = Schema::table('libraries');
        $rows = (array)$wpdb->get_results("SELECT s.id,s.title,s.slug,s.library_id,l.title AS library_title FROM `$s` s LEFT JOIN `$l` l ON l.id=s.library_id WHERE s.tenant_id IS NULL AND s.status='published' AND s.current_version_id IS NOT NULL ORDER BY COALESCE(l.sort_order,0),s.id", ARRAY_A);
        $access = new LibraryAccessService(); $out=[];
        foreach ($rows as $row) {
            if ((int)$row['library_id'] > 0) {
                try { if (empty($access->state($access->get((int)$row['library_id']))['allowed'])) { continue; } }
                catch (\Throwable) { continue; }
            }
            $out[] = $row;
        }
        return $out;
    }

    public function create(string $title): array {
        global $wpdb;
        $context = self::assertUse();
        $title = self::short($title);
        if ($title === '') { throw new ScenarioBuilderValidationException(['Укажите название сценария.']); }
        $scenarios = Schema::table('scenarios'); $versions = Schema::table('scenario_versions'); $now=self::now();
        if ($wpdb->query('START TRANSACTION') === false) { throw new \RuntimeException('Не удалось начать создание сценария.'); }
        try {
            $slug=$this->uniqueSlug($title,(int)$context['tenant_id'],(int)$context['user_id']);
            if ($wpdb->insert($scenarios,[
                'tenant_id'=>(int)$context['tenant_id'],'library_id'=>null,'slug'=>$slug,'title'=>$title,'status'=>'draft','source_scenario_id'=>null,'current_version_id'=>null,
                'created_by'=>(int)$context['user_id'],'created_at'=>$now,'updated_at'=>$now,
            ])===false) { throw new \RuntimeException('Не удалось создать сценарий.'); }
            $scenarioId=(int)$wpdb->insert_id;
            if ($wpdb->insert($versions,[
                'scenario_id'=>$scenarioId,'version_number'=>1,'status'=>'draft','player_role'=>'','player_situation'=>'','player_task'=>'',
                'player_known_facts_json'=>'[]','player_ideal_result_json'=>'{}','player_target_result_json'=>'{}','player_alternative_json'=>'{}','player_red_lines_json'=>'{}',
                'opponent_name'=>'','opponent_role'=>'','opponent_persona_json'=>'{}','opponent_external_position_json'=>'{}','opponent_hidden_interests_json'=>'[]','opponent_constraints_json'=>'[]',
                'opponent_alternative_json'=>'{}','opponent_concession_space_json'=>'{}','opponent_walkaway_json'=>'{}',
                'mechanics_json'=>wp_json_encode(['allowed_modes'=>['training','exam'],'default_mode'=>'training','first_turn'=>'player','voice_input'=>true,'hard_timer'=>false,'coach_available_training'=>true,'coach_available_exam'=>false,'opponent_can_walkaway'=>true,'explicit_final_confirmation'=>true,'allow_finish_without_agreement'=>true],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'created_at'=>$now,'published_at'=>null,
            ])===false) { throw new \RuntimeException('Не удалось создать версию сценария.'); }
            if ($wpdb->query('COMMIT')===false) { throw new \RuntimeException('Не удалось сохранить сценарий.'); }
            return $this->detail($scenarioId);
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
    }

    public function duplicate(int $sourceScenarioId): array {
        global $wpdb;
        $context = self::assertUse();
        $source = (new ScenarioRepository())->get($sourceScenarioId);
        if ($source['tenant_id'] !== null) { throw new ScenarioBuilderAccessDeniedException('В этой версии копируются только системные опубликованные сценарии.'); }
        (new LibraryAccessService())->assertScenarioAccess($source);
        if ((string)$source['status'] !== 'published' || empty($source['current_version_id'])) { throw new ScenarioBuilderValidationException(['Исходный сценарий не опубликован.']); }
        $versions=Schema::table('scenario_versions');
        $sourceVersion=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$versions` WHERE id=%d AND scenario_id=%d AND status='published'",(int)$source['current_version_id'],$sourceScenarioId),ARRAY_A);
        if(!$sourceVersion){throw new \RuntimeException('Исходная версия недоступна.');}
        $scenarios=Schema::table('scenarios'); $now=self::now(); $title='Копия — '.(string)$source['title'];
        if ($wpdb->query('START TRANSACTION')===false) { throw new \RuntimeException('Не удалось начать копирование.'); }
        try {
            $slug=$this->uniqueSlug($title,(int)$context['tenant_id'],(int)$context['user_id']);
            if($wpdb->insert($scenarios,['tenant_id'=>(int)$context['tenant_id'],'library_id'=>null,'slug'=>$slug,'title'=>$title,'status'=>'draft','source_scenario_id'=>$sourceScenarioId,'current_version_id'=>null,'created_by'=>(int)$context['user_id'],'created_at'=>$now,'updated_at'=>$now])===false){throw new \RuntimeException('Не удалось создать копию.');}
            $scenario=['id'=>(int)$wpdb->insert_id];
            $draft=$this->deepCloneVersion($scenario,$sourceVersion);
            if($wpdb->query('COMMIT')===false){throw new \RuntimeException('Не удалось завершить копирование.');}
            return $this->detail((int)$scenario['id']);
        }catch(\Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
    }

    public function detail(int $scenarioId): array {
        $scenario=$this->editableScenario($scenarioId);
        $version=$this->latestDraft($scenario) ?: $this->currentVersion($scenario);
        if(!$version){throw new \RuntimeException('Версия сценария отсутствует.');}
        $components=$this->components((int)$version['id']);
        foreach ($version as $k=>$v) { if (str_ends_with((string)$k,'_json')) { $version[$k]=self::jsonValue($v,[]); } }
        foreach($components as $key=>&$rows){foreach($rows as &$row){foreach($row as $k=>$v){if(str_ends_with((string)$k,'_json')){$row[$k]=self::jsonValue($v,[]);}}}unset($row);}unset($rows);
        return ['scenario'=>$scenario,'version'=>$version,'components'=>$components,'is_draft'=>(string)$version['status']==='draft'];
    }

    private function normalizeHidden(array $rows): array {
        $out=[];$seen=[];$i=0;
        foreach($rows as $row){if(!is_array($row))continue;$code=self::code($row['code']??'', 'fact_'.($i+1));if(isset($seen[$code]))throw new ScenarioBuilderValidationException(["Повторяется код скрытого факта: $code."]);$seen[$code]=1;
            $title=self::short($row['title']??'');$content=self::text($row['content']??'');if($title===''&&$content==='')continue;
            $out[]=['code'=>$code,'title'=>$title!==''?$title:$code,'content'=>$content,'importance'=>max(0.1,(float)($row['importance']??1)),'initial_level'=>max(0,min(2,(int)($row['initial_level']??0))),'reveal_rules_json'=>self::json($row['reveal_rules_json']??$row['reveal_rules']??[]),'sort_order'=>$i++];
        } return $out;
    }
    private function normalizeItems(array $rows): array {
        $out=[];$seen=[];$i=0;
        foreach($rows as $row){if(!is_array($row))continue;$code=self::code($row['code']??'', 'item_'.($i+1));if(isset($seen[$code]))throw new ScenarioBuilderValidationException(["Повторяется код предмета переговоров: $code."]);$seen[$code]=1;
            $title=self::short($row['title']??'');if($title==='')continue;$type=sanitize_key((string)($row['value_type']??'select'));if(!in_array($type,self::ITEM_TYPES,true))throw new ScenarioBuilderValidationException(["Неподдерживаемый тип предмета: $type."]);
            $pd=(string)($row['player_preference_direction']??'');$od=(string)($row['opponent_preference_direction']??'');if(!in_array($pd,self::DIRECTIONS,true)||!in_array($od,self::DIRECTIONS,true))throw new ScenarioBuilderValidationException(["Некорректное направление предпочтения у $code."]);
            $reopen=(string)($row['reopen_policy']??'with_reason');if(!in_array($reopen,self::REOPEN,true))$reopen='with_reason';
            $out[]=['code'=>$code,'title'=>$title,'value_type'=>$type,'unit'=>self::short($row['unit']??'',64),'player_target_json'=>self::json($row['player_target_json']??[]),'player_boundary_json'=>self::json($row['player_boundary_json']??[]),'opponent_target_json'=>self::json($row['opponent_target_json']??[]),'opponent_boundary_json'=>self::json($row['opponent_boundary_json']??[]),'player_preference_direction'=>$pd,'opponent_preference_direction'=>$od,'required_for_agreement'=>self::bool($row['required_for_agreement']??false),'reopen_policy'=>$reopen,'importance_weight'=>max(0.1,(float)($row['importance_weight']??1)),'config_json'=>self::json($row['config_json']??[]),'sort_order'=>$i++];
        } return $out;
    }
    private function normalizeRules(array $rows): array {
        $out=[];$seen=[];$i=0;
        foreach($rows as $row){if(!is_array($row))continue;$code=self::code($row['code']??'', 'rule_'.($i+1));if(isset($seen[$code]))throw new ScenarioBuilderValidationException(["Повторяется код правила: $code."]);$seen[$code]=1;
            $type=sanitize_key((string)($row['rule_type']??'validation'));if(!in_array($type,self::RULE_TYPES,true))throw new ScenarioBuilderValidationException(["Неподдерживаемый тип правила: $type."]);
            $out[]=['code'=>$code,'rule_type'=>$type,'priority'=>(int)($row['priority']??0),'condition_json'=>self::json($row['condition_json']??[]),'action_json'=>self::json($row['action_json']??[]),'is_active'=>self::bool($row['is_active']??true)];$i++;
        } return $out;
    }
    private function normalizeEvaluation(array $rows): array {
        $out=[];$seen=[];$i=0;
        foreach($rows as $row){if(!is_array($row))continue;$code=self::code($row['code']??'', 'criterion_'.($i+1));if(isset($seen[$code]))throw new ScenarioBuilderValidationException(["Повторяется код критерия: $code."]);$seen[$code]=1;
            $title=self::short($row['title']??'');if($title==='')continue;$type=sanitize_key((string)($row['evaluation_type']??'hybrid'));if(!in_array($type,self::EVAL_TYPES,true))throw new ScenarioBuilderValidationException(["Неподдерживаемый тип оценки: $type."]);
            $out[]=['code'=>$code,'title'=>$title,'weight'=>max(0,(float)($row['weight']??0)),'evaluation_type'=>$type,'rubric_json'=>self::json($row['rubric_json']??[]),'config_json'=>self::json($row['config_json']??[]),'sort_order'=>$i++];
        } return $out;
    }

    private function normalizedPayload(array $payload): array {
        $version=[
            'player_role'=>self::text($payload['player_role']??''),'player_situation'=>self::text($payload['player_situation']??''),'player_task'=>self::text($payload['player_task']??''),
            'player_known_facts_json'=>self::json($payload['player_known_facts_json']??[]),'player_ideal_result_json'=>self::json($payload['player_ideal_result_json']??[]),'player_target_result_json'=>self::json($payload['player_target_result_json']??[]),'player_alternative_json'=>self::json($payload['player_alternative_json']??[]),'player_red_lines_json'=>self::json($payload['player_red_lines_json']??[]),
            'opponent_name'=>self::short($payload['opponent_name']??''),'opponent_role'=>self::text($payload['opponent_role']??''),'opponent_persona_json'=>self::json($payload['opponent_persona_json']??[]),'opponent_external_position_json'=>self::json($payload['opponent_external_position_json']??[]),'opponent_hidden_interests_json'=>self::json($payload['opponent_hidden_interests_json']??[]),'opponent_constraints_json'=>self::json($payload['opponent_constraints_json']??[]),'opponent_alternative_json'=>self::json($payload['opponent_alternative_json']??[]),'opponent_concession_space_json'=>self::json($payload['opponent_concession_space_json']??[]),'opponent_walkaway_json'=>self::json($payload['opponent_walkaway_json']??[]),
            'mechanics_json'=>self::json($payload['mechanics_json']??['allowed_modes'=>['training','exam'],'default_mode'=>'training','first_turn'=>'player','voice_input'=>true,'hard_timer'=>false,'coach_available_training'=>true,'coach_available_exam'=>false,'opponent_can_walkaway'=>true,'explicit_final_confirmation'=>true,'allow_finish_without_agreement'=>true]),
        ];
        return ['title'=>self::short($payload['title']??''),'version'=>$version,'hidden_facts'=>$this->normalizeHidden((array)($payload['hidden_facts']??[])),'items'=>$this->normalizeItems((array)($payload['items']??[])),'rules'=>$this->normalizeRules((array)($payload['rules']??[])),'evaluation_rules'=>$this->normalizeEvaluation((array)($payload['evaluation_rules']??[]))];
    }

    private function replaceComponents(int $versionId, array $data): void {
        global $wpdb;
        foreach(['hidden_facts','items','rules','evaluation_rules'] as $key){
            $table=Schema::table($key); if($wpdb->query($wpdb->prepare("DELETE FROM `$table` WHERE scenario_version_id=%d",$versionId))===false)throw new \RuntimeException('Не удалось обновить компоненты сценария.');
            foreach($data[$key] as $row){$row=['scenario_version_id'=>$versionId]+$row;if($wpdb->insert($table,$row)===false)throw new \RuntimeException('Не удалось сохранить компонент сценария.');}
        }
    }

    public function save(int $scenarioId,array $payload): array {
        global $wpdb;
        $scenario=$this->editableScenario($scenarioId);$draft=$this->ensureDraft($scenario);$data=$this->normalizedPayload($payload);$title=$data['title'];
        if($title==='')throw new ScenarioBuilderValidationException(['Укажите название сценария.']);
        $versions=Schema::table('scenario_versions');$scenarios=Schema::table('scenarios');$now=self::now();
        if($wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Не удалось начать сохранение.');
        try{
            $update=$data['version'];
            if($wpdb->update($versions,$update,['id'=>(int)$draft['id'],'status'=>'draft'])===false)throw new \RuntimeException('Не удалось сохранить версию сценария.');
            $this->replaceComponents((int)$draft['id'],$data);
            if($wpdb->update($scenarios,['title'=>$title,'updated_at'=>$now],['id'=>$scenarioId])===false)throw new \RuntimeException('Не удалось обновить карточку сценария.');
            if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Не удалось завершить сохранение.');
            $detail=$this->detail($scenarioId);$detail['validation']=$this->validateDetail($detail,false);return $detail;
        }catch(\Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
    }

    public function validateDetail(array $detail,bool $forPublish=true): array {
        $issues=[];$scenario=$detail['scenario'];$v=$detail['version'];$c=$detail['components'];
        if(trim((string)$scenario['title'])==='')$issues[]='Не указано название.';
        if(trim((string)($v['player_role']??''))==='')$issues[]='Не указана роль участника.';
        if(trim((string)($v['player_situation']??''))==='')$issues[]='Не описана ситуация.';
        if(trim((string)($v['player_task']??''))==='')$issues[]='Не сформулирована задача участника.';
        if(trim((string)($v['opponent_name']??''))==='')$issues[]='Не указано имя ИИ-оппонента.';
        if(trim((string)($v['opponent_role']??''))==='')$issues[]='Не указана роль ИИ-оппонента.';
        $alternative=is_array($v['player_alternative_json']??null)?$v['player_alternative_json']:[];$valuation=is_array($alternative['valuation']??null)?$alternative['valuation']:[];if($valuation&&AlternativeValueService::model($alternative)===null)$issues[]='Ценность альтернативы без соглашения должна быть числом от 0 до 100.';
        if(empty($c['items']))$issues[]='Добавьте хотя бы один предмет переговоров.';
        if(empty($c['evaluation_rules']))$issues[]='Добавьте критерии оценки.';
        $sum=0.0;foreach((array)$c['evaluation_rules'] as $r){$sum+=(float)($r['weight']??0);}if($c['evaluation_rules'] && abs($sum-100.0)>0.001)$issues[]='Сумма весов критериев должна быть 100.';
        $required=array_filter((array)$c['items'],fn($r)=>!empty($r['required_for_agreement']));if($forPublish && !$required)$issues[]='Для публикации отметьте хотя бы один обязательный пункт соглашения.';
        return ['ok'=>!$issues,'issues'=>$issues,'evaluation_weight'=>$sum,'item_count'=>count((array)$c['items']),'hidden_fact_count'=>count((array)$c['hidden_facts']),'rule_count'=>count((array)$c['rules']),'evaluation_rule_count'=>count((array)$c['evaluation_rules'])];
    }

    public function validate(int $scenarioId): array { return $this->validateDetail($this->detail($scenarioId),true); }

    public function publish(int $scenarioId): array {
        global $wpdb;
        $scenario=$this->editableScenario($scenarioId);$draft=$this->latestDraft($scenario);if(!$draft)throw new ScenarioBuilderValidationException(['Нет черновой версии для публикации.']);
        $detail=$this->detail($scenarioId);$validation=$this->validateDetail($detail,true);if(!$validation['ok'])throw new ScenarioBuilderValidationException($validation['issues']);
        $versions=Schema::table('scenario_versions');$scenarios=Schema::table('scenarios');$now=self::now();
        if($wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Не удалось начать публикацию.');
        try{
            if($wpdb->update($versions,['status'=>'published','published_at'=>$now],['id'=>(int)$draft['id'],'status'=>'draft'])===false)throw new \RuntimeException('Не удалось опубликовать версию.');
            if($wpdb->update($scenarios,['status'=>'published','current_version_id'=>(int)$draft['id'],'updated_at'=>$now],['id'=>$scenarioId])===false)throw new \RuntimeException('Не удалось переключить опубликованную версию.');
            if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Не удалось завершить публикацию.');
            return $this->detail($scenarioId)+['published_version_id'=>(int)$draft['id'],'validation'=>$validation];
        }catch(\Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
    }

    public function testLaunch(int $scenarioId, string $mode = 'training'): array {
        global $wpdb;
        $scenario = $this->editableScenario($scenarioId);
        $version = $this->latestDraft($scenario) ?: $this->currentVersion($scenario);
        if (!$version) { throw new ScenarioBuilderValidationException(['Сначала сохраните сценарий.']); }
        $detail = $this->detail($scenarioId);
        $validation = $this->validateDetail($detail, true);
        if (!$validation['ok']) { throw new ScenarioBuilderValidationException($validation['issues']); }
        $mechanics = is_array($detail['version']['mechanics_json'] ?? null) ? $detail['version']['mechanics_json'] : [];
        $allowed = array_values(array_intersect(['training','exam'], array_map('strval', (array)($mechanics['allowed_modes'] ?? ['training','exam']))));
        if (!$allowed) { $allowed = ['training']; }
        $mode = sanitize_key($mode);
        if (!in_array($mode, $allowed, true)) { $mode = in_array('training', $allowed, true) ? 'training' : $allowed[0]; }
        $sessions = new SessionRepository();
        $sessions->abandonBuilderTestsForScenario($scenarioId);
        if ($wpdb->query('START TRANSACTION') === false) { throw new \RuntimeException('Не удалось начать тестовый запуск.'); }
        try {
            $sessionId = $sessions->createBuilderTestRuntime((int)$version['id'], $mode, false);
            $sessions->initializeItems($sessionId, (int)$version['id']);
            if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException('Не удалось сохранить тестовую сессию.'); }
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
        return [
            'session_id'=>$sessionId,
            'scenario_id'=>$scenarioId,
            'scenario_version_id'=>(int)$version['id'],
            'mode'=>$mode,
            'url'=>ProductCatalog::url(['neg_test_session'=>$sessionId]),
            'validation'=>$validation,
        ];
    }

    public function archive(int $scenarioId): array {
        global $wpdb;$scenario=$this->editableScenario($scenarioId);$table=Schema::table('scenarios');$sessions=Schema::table('sessions');
        $used=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$sessions` WHERE scenario_id=%d AND session_kind='player'",$scenarioId));
        if($used>0)throw new ScenarioBuilderValidationException(['Сценарий уже использовался в попытках. В этой версии его нельзя архивировать, чтобы не разорвать ссылки на историю.']);
        if($wpdb->update($table,['status'=>'archived','updated_at'=>self::now()],['id'=>$scenarioId])===false)throw new \RuntimeException('Не удалось архивировать сценарий.');
        return ['id'=>$scenarioId,'status'=>'archived'];
    }
}
