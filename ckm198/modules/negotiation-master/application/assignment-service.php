<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class AssignmentAccessDeniedException extends \RuntimeException {}
final class AssignmentValidationException extends \InvalidArgumentException {}
final class AssignmentUnavailableException extends \RuntimeException {}
final class AssignmentAttemptLimitException extends \RuntimeException {}

/** Organizer assignments pinned to an immutable scenario version. */
final class AssignmentService {
    private SessionRepository $sessions;
    public function __construct(?SessionRepository $sessions = null) { $this->sessions = $sessions ?: new SessionRepository(); }

    public static function canManage(): bool {
        if (!is_user_logged_in()) { return false; }
        if (current_user_can('manage_options')) { return true; }
        return function_exists('ckm_quiz_pro_can_organize') && ckm_quiz_pro_can_organize();
    }

    private static function boolValue(mixed $value): bool {
        if (is_bool($value)) { return $value; }
        return in_array(strtolower(trim((string)$value)), ['1','true','yes','on'], true);
    }

    private static function requireManager(): array {
        if (!self::canManage()) { throw new AssignmentAccessDeniedException('Назначения доступны организатору.'); }
        return Access::context();
    }

    private static function assignmentRow(int $id, bool $manage = false): array {
        global $wpdb;
        if ($id <= 0) { throw new AssignmentValidationException('Назначение не выбрано.'); }
        $context = $manage ? self::requireManager() : Access::context();
        $table = Schema::table('assignments');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE id=%d", $id), ARRAY_A);
        if (!$row || (int)$row['tenant_id'] !== (int)$context['tenant_id']) { throw new AssignmentAccessDeniedException('Назначение недоступно.'); }
        if ($manage && empty($context['admin']) && (int)$row['created_by'] !== (int)$context['user_id']) { throw new AssignmentAccessDeniedException('Можно управлять только своими назначениями.'); }
        return $row;
    }

    private static function participantRow(int $assignmentId, int $userId): ?array {
        global $wpdb;
        $table = Schema::table('assignment_participants');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE assignment_id=%d AND user_id=%d LIMIT 1", $assignmentId, $userId), ARRAY_A) ?: null;
    }

    private static function parseDeadline(mixed $value): ?string {
        $raw = trim((string)$value);
        if ($raw === '') { return null; }
        try {
            $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
            $dt = new \DateTimeImmutable($raw, $tz);
            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) { throw new AssignmentValidationException('Укажите корректный дедлайн.'); }
    }

    private static function labelForStatus(string $status): string {
        return ['draft'=>'Черновик','active'=>'Активно','closed'=>'Закрыто'][$status] ?? $status;
    }

    private static function publicAssignment(array $row): array {
        return [
            'id'=>(int)$row['id'],'scenario_id'=>(int)$row['scenario_id'],'scenario_version_id'=>(int)$row['scenario_version_id'],
            'title'=>(string)$row['title'],'assignment_mode'=>(string)$row['assignment_mode'],'mode'=>(string)$row['mode'],'difficulty'=>DifficultyPolicy::normalize($row['difficulty']??'medium'),'difficulty_label'=>DifficultyPolicy::label((string)($row['difficulty']??'medium')),
            'max_attempts'=>(int)$row['max_attempts'],'voice_enabled'=>(bool)$row['voice_enabled'],'result_visibility'=>(string)$row['result_visibility'],
            'deadline_at'=>$row['deadline_at'],'status'=>(string)$row['status'],'status_label'=>self::labelForStatus((string)$row['status']),
            'created_at'=>(string)$row['created_at'],'updated_at'=>(string)$row['updated_at'],
        ];
    }

    private static function participantKey(array $assignment, array $participant): string {
        if ((string)$assignment['assignment_mode'] === 'team_shared') {
            $teamKey = trim((string)($participant['team_key'] ?? ''));
            if ($teamKey === '') { throw new AssignmentValidationException('Для командного назначения укажите команду участника.'); }
            return 'assignment-team:' . (int)$assignment['id'] . ':' . $teamKey;
        }
        return 'user:' . (int)$participant['user_id'];
    }

    public function availableScenarios(): array {
        global $wpdb;
        self::requireManager();
        $scenarios = Schema::table('scenarios'); $versions = Schema::table('scenario_versions');
        $context = Access::context();
        $rows = (array)$wpdb->get_results($wpdb->prepare(
            "SELECT s.id,s.slug,s.title,s.tenant_id,s.library_id,s.current_version_id FROM `$scenarios` s INNER JOIN `$versions` v ON v.id=s.current_version_id AND v.scenario_id=s.id AND v.status='published' WHERE s.status='published' AND (s.tenant_id IS NULL OR s.tenant_id=%d) ORDER BY CASE WHEN s.tenant_id IS NULL THEN 0 ELSE 1 END,s.title ASC",
            (int)$context['tenant_id']
        ), ARRAY_A);
        $access = new LibraryAccessService(); $out = [];
        foreach ($rows as $row) {
            try { $access->assertScenarioAccess($row); }
            catch (\Throwable) { continue; }
            $out[] = ['id'=>(int)$row['id'],'slug'=>(string)$row['slug'],'title'=>(string)$row['title'],'scenario_version_id'=>(int)$row['current_version_id']];
        }
        return $out;
    }

    public function create(array $data): array {
        global $wpdb;
        $context = self::requireManager();
        $scenarioId = (int)($data['scenario_id'] ?? 0);
        if ($scenarioId <= 0) { throw new AssignmentValidationException('Выберите сценарий.'); }
        $scenario = (new ScenarioRepository())->get($scenarioId);
        if ($scenario['tenant_id'] !== null && (int)$scenario['tenant_id'] !== (int)$context['tenant_id']) { throw new AssignmentAccessDeniedException('Сценарий другого арендатора недоступен для назначения.'); }
        (new LibraryAccessService())->assertScenarioAccess($scenario);
        if ((string)$scenario['status'] !== 'published' || empty($scenario['current_version_id'])) { throw new AssignmentValidationException('Можно назначить только опубликованный сценарий.'); }
        $version = Access::version((int)$scenario['current_version_id']);
        if ((int)$version['scenario_id'] !== $scenarioId) { throw new AssignmentValidationException('Версия не относится к выбранному сценарию.'); }
        if ((string)$version['status'] !== 'published') { throw new AssignmentValidationException('Опубликованная версия сценария недоступна.'); }
        $title = trim(sanitize_text_field((string)($data['title'] ?? '')));
        if ($title === '') { $title = (string)$scenario['title']; }
        if (mb_strlen($title) > 255) { throw new AssignmentValidationException('Название слишком длинное.'); }
        $assignmentMode = (string)($data['assignment_mode'] ?? 'individual');
        if (!in_array($assignmentMode, ['individual','team_shared'], true)) { throw new AssignmentValidationException('Неизвестный формат назначения.'); }
        $mode = (string)($data['mode'] ?? 'training');
        if (!in_array($mode, ['training','exam'], true)) { throw new AssignmentValidationException('Неизвестный режим игры.'); }
        try { $difficulty = DifficultyPolicy::assertAllowed((string)($data['difficulty'] ?? DifficultyPolicy::defaultForVersion($version)), $version); }
        catch (\InvalidArgumentException $e) { throw new AssignmentValidationException($e->getMessage()); }
        $attempts = max(1, min(20, (int)($data['max_attempts'] ?? 1)));
        $visibility = (string)($data['result_visibility'] ?? 'immediate');
        if (!in_array($visibility, ['immediate','after_deadline','organizer_only'], true)) { throw new AssignmentValidationException('Неизвестный режим показа результата.'); }
        $deadline = self::parseDeadline($data['deadline_at'] ?? null);
        if ($deadline !== null && strtotime($deadline.' UTC') <= time()) { throw new AssignmentValidationException('Дедлайн должен быть в будущем.'); }
        $now = current_time('mysql', true);
        $id = $wpdb->insert(Schema::table('assignments'), [
            'tenant_id'=>(int)$context['tenant_id'],'scenario_id'=>$scenarioId,'scenario_version_id'=>(int)$version['id'],'created_by'=>(int)$context['user_id'],
            'title'=>$title,'assignment_mode'=>$assignmentMode,'mode'=>$mode,'difficulty'=>$difficulty,'max_attempts'=>$attempts,'voice_enabled'=>self::boolValue($data['voice_enabled'] ?? true)?1:0,
            'result_visibility'=>$visibility,'deadline_at'=>$deadline,'status'=>'draft','created_at'=>$now,'updated_at'=>$now,
        ]);
        if ($id === false || (int)$wpdb->insert_id <= 0) { throw new \RuntimeException('Не удалось создать назначение.'); }
        return $this->detail((int)$wpdb->insert_id);
    }

    private static function resolveUser(string $identifier): \WP_User {
        $identifier = trim($identifier);
        if ($identifier === '') { throw new AssignmentValidationException('Укажите логин, email или ID участника.'); }
        $user = null;
        if (ctype_digit($identifier)) { $user = get_user_by('id', (int)$identifier); }
        if (!$user && is_email($identifier)) { $user = get_user_by('email', $identifier); }
        if (!$user) { $user = get_user_by('login', $identifier); }
        if (!$user instanceof \WP_User) { throw new AssignmentValidationException('Пользователь «'.$identifier.'» не найден.'); }
        return $user;
    }

    private static function teamKey(string $name): string {
        $name = trim($name);
        if ($name === '') { return ''; }
        $base = sanitize_title($name);
        if ($base === '') { $base = 'team-' . substr(hash('sha256', $name), 0, 12); }
        return substr($base, 0, 80);
    }

    public function addParticipants(int $assignmentId, array $participants): array {
        global $wpdb;
        $assignment = self::assignmentRow($assignmentId, true);
        if ((string)$assignment['status'] === 'closed') { throw new AssignmentUnavailableException('Закрытое назначение нельзя изменять.'); }
        if (!$participants) { throw new AssignmentValidationException('Добавьте хотя бы одного участника.'); }
        $table = Schema::table('assignment_participants'); $now = current_time('mysql', true);
        foreach (array_slice($participants, 0, 100) as $raw) {
            if (!is_array($raw)) { continue; }
            $user = self::resolveUser((string)($raw['identifier'] ?? ''));
            $teamName = trim(sanitize_text_field((string)($raw['team_name'] ?? '')));
            $teamKey = (string)$assignment['assignment_mode'] === 'team_shared' ? self::teamKey($teamName) : '';
            if ((string)$assignment['assignment_mode'] === 'team_shared' && $teamKey === '') { throw new AssignmentValidationException('Для командного назначения у каждого участника должна быть указана команда.'); }
            $display = trim((string)$user->display_name) ?: (string)$user->user_login;
            $existing = $wpdb->get_row($wpdb->prepare("SELECT id FROM `$table` WHERE assignment_id=%d AND user_id=%d", $assignmentId, (int)$user->ID), ARRAY_A);
            $data = ['display_name'=>$display,'team_key'=>$teamKey!==''?$teamKey:null,'team_name'=>$teamName!==''?$teamName:null,'status'=>'assigned','updated_at'=>$now];
            if ($existing) {
                if ($wpdb->update($table, $data, ['id'=>(int)$existing['id']]) === false) { throw new \RuntimeException('Не удалось обновить участника назначения.'); }
            } else {
                $data += ['assignment_id'=>$assignmentId,'user_id'=>(int)$user->ID,'last_session_id'=>null,'created_at'=>$now];
                if ($wpdb->insert($table, $data) === false) { throw new \RuntimeException('Не удалось добавить участника назначения.'); }
            }
        }
        return $this->detail($assignmentId);
    }

    public function removeParticipant(int $assignmentId, int $participantId): array {
        global $wpdb;
        $assignment = self::assignmentRow($assignmentId, true);
        if ((string)$assignment['status'] === 'closed') { throw new AssignmentUnavailableException('Закрытое назначение нельзя изменять.'); }
        $table = Schema::table('assignment_participants');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE id=%d AND assignment_id=%d", $participantId, $assignmentId), ARRAY_A);
        if (!$row) { throw new AssignmentValidationException('Участник назначения не найден.'); }
        $sessions = Schema::table('sessions');
        $used = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$sessions` WHERE assignment_id=%d AND (participant_key=%s OR participant_key=%s)", $assignmentId, 'user:'.(int)$row['user_id'], ((string)$assignment['assignment_mode']==='team_shared' && !empty($row['team_key']))?'assignment-team:'.$assignmentId.':'.$row['team_key']:'__none__'));
        if ($used > 0) { throw new AssignmentValidationException('Нельзя удалить участника после начала назначенной игры.'); }
        if ($wpdb->delete($table, ['id'=>$participantId,'assignment_id'=>$assignmentId]) === false) { throw new \RuntimeException('Не удалось удалить участника.'); }
        return $this->detail($assignmentId);
    }

    public function setStatus(int $assignmentId, string $status): array {
        global $wpdb;
        $assignment = self::assignmentRow($assignmentId, true);
        if (!in_array($status, ['active','closed'], true)) { throw new AssignmentValidationException('Недопустимый статус назначения.'); }
        if ($status === 'active') {
            $count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `".Schema::table('assignment_participants')."` WHERE assignment_id=%d", $assignmentId));
            if ($count < 1) { throw new AssignmentValidationException('Перед активацией добавьте участников.'); }
            if (!empty($assignment['deadline_at']) && strtotime((string)$assignment['deadline_at'].' UTC') <= time()) { throw new AssignmentValidationException('Дедлайн уже истёк.'); }
        }
        if ($wpdb->update(Schema::table('assignments'), ['status'=>$status,'updated_at'=>current_time('mysql', true)], ['id'=>$assignmentId]) === false) { throw new \RuntimeException('Не удалось изменить статус назначения.'); }
        return $this->detail($assignmentId);
    }

    public function listOwn(): array {
        global $wpdb;
        $context = self::requireManager(); $table = Schema::table('assignments'); $scenarios = Schema::table('scenarios'); $participants = Schema::table('assignment_participants'); $sessions = Schema::table('sessions');
        $where = empty($context['admin']) ? $wpdb->prepare('a.tenant_id=%d AND a.created_by=%d', (int)$context['tenant_id'], (int)$context['user_id']) : $wpdb->prepare('a.tenant_id=%d', (int)$context['tenant_id']);
        $rows = (array)$wpdb->get_results("SELECT a.*,s.title AS scenario_title,(SELECT COUNT(*) FROM `$participants` p WHERE p.assignment_id=a.id) AS participant_count,(SELECT COUNT(*) FROM `$sessions` se WHERE se.assignment_id=a.id AND se.status LIKE 'completed_%') AS completed_sessions FROM `$table` a INNER JOIN `$scenarios` s ON s.id=a.scenario_id WHERE $where ORDER BY a.id DESC", ARRAY_A);
        return array_map(static function(array $row): array { $pub=self::publicAssignment($row);$pub['scenario_title']=(string)$row['scenario_title'];$pub['participant_count']=(int)$row['participant_count'];$pub['completed_sessions']=(int)$row['completed_sessions'];return $pub; }, $rows);
    }

    public function detail(int $assignmentId): array {
        global $wpdb;
        $assignment = self::assignmentRow($assignmentId, true);
        $scenario = $wpdb->get_row($wpdb->prepare("SELECT id,slug,title FROM `".Schema::table('scenarios')."` WHERE id=%d", (int)$assignment['scenario_id']), ARRAY_A);
        $rows = (array)$wpdb->get_results($wpdb->prepare("SELECT * FROM `".Schema::table('assignment_participants')."` WHERE assignment_id=%d ORDER BY COALESCE(team_name,''),display_name,id", $assignmentId), ARRAY_A);
        $out = self::publicAssignment($assignment); $out['scenario']=$scenario; $out['participant_count']=count($rows); $out['assignment_url']=AssignmentPage::url(['assignment'=>$assignmentId]);
        $out['participants'] = [];
        foreach ($rows as $row) { $out['participants'][] = $this->participantReport($assignment, $row); }
        return $out;
    }

    private function participantReport(array $assignment, array $participant): array {
        global $wpdb;
        $key = self::participantKey($assignment, $participant); $sessions=Schema::table('sessions');$evaluations=Schema::table('evaluations');
        $stats=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS attempts,SUM(CASE WHEN se.status LIKE 'completed_%%' THEN 1 ELSE 0 END) AS completed,MAX(COALESCE(se.last_activity_at,se.created_at)) AS last_activity,MAX(ev.final_score) AS best_score FROM `$sessions` se LEFT JOIN `$evaluations` ev ON ev.session_id=se.id WHERE se.assignment_id=%d AND se.participant_key=%s", (int)$assignment['id'], $key), ARRAY_A) ?: [];
        $active=$wpdb->get_row($wpdb->prepare("SELECT id,status FROM `$sessions` WHERE assignment_id=%d AND participant_key=%s AND status IN ('in_progress','paused') ORDER BY id DESC LIMIT 1", (int)$assignment['id'], $key), ARRAY_A) ?: null;
        return [
            'id'=>(int)$participant['id'],'user_id'=>(int)$participant['user_id'],'display_name'=>(string)$participant['display_name'],'team_name'=>$participant['team_name'],'team_key'=>$participant['team_key'],
            'status'=>(string)$participant['status'],'attempts'=>(int)($stats['attempts']??0),'completed'=>(int)($stats['completed']??0),'best_score'=>$stats['best_score']!==null?(float)$stats['best_score']:null,
            'last_activity'=>$stats['last_activity']??null,'active_session_id'=>$active?(int)$active['id']:0,'active_status'=>$active['status']??null,
        ];
    }

    public function mine(): array {
        global $wpdb;
        $context = Access::context(); $a=Schema::table('assignments');$p=Schema::table('assignment_participants');$s=Schema::table('scenarios');
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT a.*,p.id AS participant_row_id,p.display_name,p.team_key,p.team_name,s.title AS scenario_title,s.slug AS scenario_slug FROM `$p` p INNER JOIN `$a` a ON a.id=p.assignment_id INNER JOIN `$s` s ON s.id=a.scenario_id WHERE p.user_id=%d AND a.tenant_id=%d AND a.status IN ('active','closed') ORDER BY CASE WHEN a.status='active' THEN 0 ELSE 1 END,a.deadline_at IS NULL,a.deadline_at ASC,a.id DESC", (int)$context['user_id'], (int)$context['tenant_id']), ARRAY_A);
        $out=[];
        foreach($rows as $row){
            $participant=['id'=>(int)$row['participant_row_id'],'user_id'=>(int)$context['user_id'],'display_name'=>(string)$row['display_name'],'team_key'=>$row['team_key'],'team_name'=>$row['team_name'],'status'=>'assigned'];
            $pub=self::publicAssignment($row);$pub['scenario_title']=(string)$row['scenario_title'];$pub['scenario_slug']=(string)$row['scenario_slug'];$pub['participant']=$this->participantReport($row,$participant);
            $pub['deadline_passed']=!empty($row['deadline_at']) && strtotime((string)$row['deadline_at'].' UTC')<=time();
            $pub['can_launch']=(string)$row['status']==='active'&&!$pub['deadline_passed']&&((int)$pub['participant']['completed']<(int)$row['max_attempts']||!empty($pub['participant']['active_session_id']));
            $pub['result_visible']=$this->assignmentAllowsParticipantResult($row);
            $out[]=$pub;
        }
        return $out;
    }

    private function assignmentAllowsParticipantResult(array $assignment): bool {
        $visibility=(string)$assignment['result_visibility'];
        if($visibility==='immediate'){return true;}
        if($visibility==='organizer_only'){return false;}
        if((string)$assignment['status']==='closed'){return true;}
        return !empty($assignment['deadline_at'])&&strtotime((string)$assignment['deadline_at'].' UTC')<=time();
    }

    public function resultVisibleForSession(int $sessionId): array {
        $session=Access::session($sessionId);
        if(($session['session_kind']??'player')!=='assignment'||(int)($session['assignment_id']??0)<=0){return ['visible'=>true,'message'=>''];}
        $assignment=self::assignmentRow((int)$session['assignment_id'],false);
        if($this->assignmentAllowsParticipantResult($assignment)){return ['visible'=>true,'message'=>''];}
        $message=(string)$assignment['result_visibility']==='organizer_only'?'Итоговый разбор доступен организатору.':'Итоговый разбор станет доступен после дедлайна.';
        return ['visible'=>false,'message'=>$message];
    }

    public function assertSessionWritable(int $sessionId): void {
        $session=Access::session($sessionId);
        if(($session['session_kind']??'player')!=='assignment'){return;}
        $assignment=self::assignmentRow((int)$session['assignment_id'],false);
        if((string)$assignment['status']!=='active'){throw new AssignmentUnavailableException('Назначение закрыто организатором.');}
        if(!empty($assignment['deadline_at'])&&strtotime((string)$assignment['deadline_at'].' UTC')<=time()){throw new AssignmentUnavailableException('Срок выполнения назначения истёк.');}
    }

    public function launch(int $assignmentId): array {
        global $wpdb;
        $context=Access::context();$assignment=self::assignmentRow($assignmentId,false);$participant=self::participantRow($assignmentId,(int)$context['user_id']);
        if(!$participant){throw new AssignmentAccessDeniedException('Это назначение не выдано текущему пользователю.');}
        if((string)$assignment['status']!=='active'){throw new AssignmentUnavailableException('Назначение сейчас недоступно.');}
        if(!empty($assignment['deadline_at'])&&strtotime((string)$assignment['deadline_at'].' UTC')<=time()){throw new AssignmentUnavailableException('Срок выполнения назначения истёк.');}
        $version=Access::version((int)$assignment['scenario_version_id']);
        if((int)$version['scenario_id']!==(int)$assignment['scenario_id']||(string)$version['status']!=='published'){throw new AssignmentUnavailableException('Закреплённая версия сценария недоступна.');}
        $key=self::participantKey($assignment,$participant);
        $active=$this->sessions->findActiveForAssignment($assignmentId,$key);
        if($active){return ['session_id'=>(int)$active['id'],'existing'=>true,'url'=>ProductCatalog::url(['neg_assignment_session'=>(int)$active['id']])];}
        $completed=$this->sessions->countCompletedForAssignment($assignmentId,$key);
        if($completed>=(int)$assignment['max_attempts']){throw new AssignmentAttemptLimitException('Лимит попыток по назначению исчерпан.');}
        if($wpdb->query('START TRANSACTION')===false){throw new \RuntimeException('Не удалось начать назначенную попытку.');}
        try{
            $sessionId=$this->sessions->createAssignedRuntime($assignmentId,(int)$assignment['scenario_version_id'],$key,(string)$assignment['mode'],(bool)$assignment['voice_enabled'],DifficultyPolicy::normalize($assignment['difficulty']??'medium'));
            $this->sessions->initializeItems($sessionId,(int)$assignment['scenario_version_id']);
            $pTable=Schema::table('assignment_participants');$now=current_time('mysql',true);
            if((string)$assignment['assignment_mode']==='team_shared'){
                $wpdb->query($wpdb->prepare("UPDATE `$pTable` SET status='started',last_session_id=%d,updated_at=%s WHERE assignment_id=%d AND team_key=%s",$sessionId,$now,$assignmentId,(string)$participant['team_key']));
            } else {
                $wpdb->update($pTable,['status'=>'started','last_session_id'=>$sessionId,'updated_at'=>$now],['id'=>(int)$participant['id']]);
            }
            if($wpdb->query('COMMIT')===false){throw new \RuntimeException('Не удалось завершить создание назначенной попытки.');}
            return ['session_id'=>$sessionId,'existing'=>false,'url'=>ProductCatalog::url(['neg_assignment_session'=>$sessionId])];
        }catch(\Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
    }
}
