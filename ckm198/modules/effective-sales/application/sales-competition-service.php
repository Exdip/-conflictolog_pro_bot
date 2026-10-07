<?php
namespace CKM\EffectiveSales;
if (!defined('ABSPATH')) { exit; }

use CKM\NegotiationMaster\Access;
use CKM\NegotiationMaster\AssignmentAccessDeniedException;
use CKM\NegotiationMaster\AssignmentService;
use CKM\NegotiationMaster\AssignmentUnavailableException;
use CKM\NegotiationMaster\AssignmentValidationException;
use CKM\NegotiationMaster\Schema;

/**
 * Sales competition adapter over the existing assignment/team runtime.
 * Internally competition sessions use mode=exam so the in-game coach is disabled by core policy.
 * SALES-496 adds a central host screen and a fair start gate without new DB tables.
 * SALES-497 makes generated team keys collision-safe for Cyrillic and other multibyte names.
 * SALES-498 replaces browser-login cookie switching with stateless signed team URLs.
 */
final class SalesCompetitionService {
    private const TEAM_USER_META = 'ckm_sales_competition_team';
    private const TEAM_SECRET_META = 'ckm_sales_competition_secret';
    private const HOST_OPTION_PREFIX = 'ckm_sales_competition_host_';
    private AssignmentService $assignments;

    public function __construct(?AssignmentService $assignments = null) {
        $this->assignments = $assignments ?: new AssignmentService();
    }

    public static function canManage(): bool { return AssignmentService::canManage(); }

    private static function teamKey(string $name): string {
        // Team keys are internal identifiers, so prefer collision-safe deterministic ASCII.
        // Percent-encoded Cyrillic slugs can share the same first 80 bytes and merge teams.
        $normalized = trim($name);
        return 'team-' . substr(hash('sha256', $normalized), 0, 24);
    }

    private static function teamUserLogin(int $assignmentId, string $teamKey): string {
        return substr('ckm_sales_' . $assignmentId . '_' . preg_replace('/[^a-z0-9_-]+/i','_', $teamKey), 0, 60);
    }

    private static function createTeamUser(int $assignmentId, string $teamName): array {
        $teamKey = self::teamKey($teamName);
        $login = self::teamUserLogin($assignmentId, $teamKey);
        $existing = get_user_by('login', $login);
        if ($existing instanceof \WP_User) { $userId = (int)$existing->ID; }
        else {
            $userId = wp_insert_user([
                'user_login'=>$login,
                'user_pass'=>wp_generate_password(40,true,true),
                'display_name'=>$teamName,
                'role'=>'subscriber',
            ]);
            if (is_wp_error($userId) || (int)$userId <= 0) { throw new \RuntimeException('Не удалось создать ссылку команды «'.$teamName.'».'); }
            $userId = (int)$userId;
        }
        $secret = wp_generate_password(48,false,false);
        update_user_meta($userId,self::TEAM_USER_META,['assignment_id'=>$assignmentId,'team_key'=>$teamKey,'team_name'=>$teamName]);
        update_user_meta($userId,self::TEAM_SECRET_META,$secret);
        return ['user_id'=>$userId,'team_key'=>$teamKey,'secret'=>$secret];
    }

    private static function teamSignature(int $assignmentId,int $userId,string $secret): string {
        return hash_hmac('sha256',$assignmentId.'|'.$userId,$secret.'|'.wp_salt('auth'));
    }

    private static function teamLinkForUser(int $assignmentId,int $userId): string {
        $secret=(string)get_user_meta($userId,self::TEAM_SECRET_META,true);
        $meta=get_user_meta($userId,self::TEAM_USER_META,true);
        if ($secret==='' || !is_array($meta) || (int)($meta['assignment_id']??0)!==$assignmentId) { return ''; }
        return SalesPage::url([
            'sales_team_join'=>$assignmentId.'.'.$userId,
            'sales_team_sig'=>self::teamSignature($assignmentId,$userId,$secret),
        ]);
    }

    private static function hostOptionName(int $assignmentId): string { return self::HOST_OPTION_PREFIX.$assignmentId; }

    private static function normalizeHostMode(mixed $mode): string {
        return (string)$mode === 'human' ? 'human' : 'ai';
    }

    private static function hostModeLabel(string $mode): string { return $mode === 'human' ? 'Ведущий-человек' : 'ИИ-ведущий'; }

    private static function readHostState(int $assignmentId): array {
        $raw=get_option(self::hostOptionName($assignmentId),false);
        // Compatibility: competitions created by .494/.495 had no host gate and must remain launchable.
        if (!is_array($raw)) {
            return [
                'host_mode'=>'human',
                'phase'=>'running',
                'ready_user_ids'=>[],
                'created_at'=>'',
                'started_at'=>'',
                'legacy'=>true,
            ];
        }
        $ids=[];
        foreach ((array)($raw['ready_user_ids']??[]) as $uid) { $uid=(int)$uid; if($uid>0)$ids[$uid]=true; }
        return [
            'host_mode'=>self::normalizeHostMode($raw['host_mode']??'ai'),
            'phase'=>in_array((string)($raw['phase']??'waiting'),['waiting','running','closed'],true)?(string)$raw['phase']:'waiting',
            'ready_user_ids'=>array_map('intval',array_keys($ids)),
            'created_at'=>(string)($raw['created_at']??''),
            'started_at'=>(string)($raw['started_at']??''),
            'legacy'=>false,
        ];
    }

    private static function writeHostState(int $assignmentId,array $state): array {
        $clean=[
            'host_mode'=>self::normalizeHostMode($state['host_mode']??'ai'),
            'phase'=>in_array((string)($state['phase']??'waiting'),['waiting','running','closed'],true)?(string)$state['phase']:'waiting',
            'ready_user_ids'=>array_values(array_unique(array_filter(array_map('intval',(array)($state['ready_user_ids']??[])),static fn(int $v):bool=>$v>0))),
            'created_at'=>(string)($state['created_at']??current_time('mysql',true)),
            'started_at'=>(string)($state['started_at']??''),
        ];
        update_option(self::hostOptionName($assignmentId),$clean,false);
        return $clean+['legacy'=>false];
    }

    private static function initializeHostState(int $assignmentId,string $mode): array {
        return self::writeHostState($assignmentId,[
            'host_mode'=>self::normalizeHostMode($mode),
            'phase'=>'waiting',
            'ready_user_ids'=>[],
            'created_at'=>current_time('mysql',true),
            'started_at'=>'',
        ]);
    }

    private static function teamCount(int $assignmentId): int {
        global $wpdb;
        $p=Schema::table('assignment_participants');
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT team_key) FROM `$p` WHERE assignment_id=%d AND team_key IS NOT NULL AND team_key<>''",$assignmentId));
    }

    private static function markReady(int $assignmentId,int $userId): array {
        $state=self::readHostState($assignmentId);
        if (!empty($state['legacy']) || $state['phase']!=='waiting') { return $state; }
        $ready=array_fill_keys(array_map('intval',(array)$state['ready_user_ids']),true);
        $ready[$userId]=true;
        $state['ready_user_ids']=array_map('intval',array_keys($ready));
        $teamCount=self::teamCount($assignmentId);
        if ($state['host_mode']==='ai' && $teamCount>=2 && count($state['ready_user_ids']) >= $teamCount) {
            $state['phase']='running';
            $state['started_at']=current_time('mysql',true);
        }
        return self::writeHostState($assignmentId,$state);
    }

    public static function teamAuthRequested(): bool {
        return trim((string)($_GET['sales_team_join']??'')) !== '' || trim((string)($_GET['sales_team_sig']??'')) !== '';
    }

    private static function requestedTeamAuth(): ?array {
        $join=trim((string)($_GET['sales_team_join']??''));
        $sig=trim((string)($_GET['sales_team_sig']??''));
        if ($join==='' || $sig==='' || !preg_match('/^(\d+)\.(\d+)$/',$join,$m)) { return null; }
        $assignmentId=(int)$m[1]; $userId=(int)$m[2];
        if ($assignmentId<=0 || $userId<=0) { return null; }
        $secret=(string)get_user_meta($userId,self::TEAM_SECRET_META,true);
        $meta=get_user_meta($userId,self::TEAM_USER_META,true);
        if ($secret==='' || !is_array($meta) || (int)($meta['assignment_id']??0)!==$assignmentId) { return null; }
        $expected=self::teamSignature($assignmentId,$userId,$secret);
        if (!hash_equals($expected,$sig)) { return null; }
        global $wpdb;
        $a=Schema::table('assignments'); $p=Schema::table('assignment_participants');
        $valid=$wpdb->get_var($wpdb->prepare("SELECT p.id FROM `$p` p INNER JOIN `$a` a ON a.id=p.assignment_id WHERE p.assignment_id=%d AND p.user_id=%d AND a.assignment_mode='team_shared' AND a.mode='exam' AND a.status='active' LIMIT 1",$assignmentId,$userId));
        if (!$valid) { return null; }
        return [
            'assignment_id'=>$assignmentId,
            'user_id'=>$userId,
            'join'=>$join,
            'sig'=>$sig,
            'team_name'=>(string)($meta['team_name']??''),
            'team_key'=>(string)($meta['team_key']??''),
        ];
    }

    /**
     * Stateless team access. The signed team URL authenticates only the current request;
     * it never replaces the browser's WordPress login cookie.
     */
    public static function authenticateRequest(): bool {
        $auth=self::requestedTeamAuth();
        if (!$auth) { return false; }
        wp_set_current_user((int)$auth['user_id']);
        self::markReady((int)$auth['assignment_id'],(int)$auth['user_id']);
        return true;
    }

    public static function maybeJoinFromLink(): void {
        if (is_admin() && !wp_doing_ajax()) { return; }
        self::authenticateRequest();
    }

    public static function currentTeamAuthArgs(): array {
        $auth=self::requestedTeamAuth();
        if (!$auth) { return []; }
        return ['sales_team_join'=>(string)$auth['join'],'sales_team_sig'=>(string)$auth['sig']];
    }

    public static function currentTeamAssignmentId(): int {
        $auth=self::requestedTeamAuth();
        return $auth ? (int)$auth['assignment_id'] : 0;
    }

    public static function currentTeamName(int $assignmentId=0): string {
        $auth=self::requestedTeamAuth();
        if (!$auth) { return ''; }
        if ($assignmentId>0 && (int)$auth['assignment_id']!==$assignmentId) { return ''; }
        return trim((string)$auth['team_name']);
    }

    private static function teamName(string $name): string {
        $name = trim(sanitize_text_field($name));
        if ($name === '') { throw new AssignmentValidationException('Укажите название каждой команды.'); }
        if (mb_strlen($name) > 80) { throw new AssignmentValidationException('Название команды слишком длинное.'); }
        return $name;
    }

    private static function isSalesScenario(int $scenarioId): bool {
        try { SalesDomain::versionForScenario($scenarioId); return true; }
        catch (\Throwable) { return false; }
    }

    private static function isCompetition(array $row): bool {
        return (string)($row['assignment_mode'] ?? '') === 'team_shared'
            && (string)($row['mode'] ?? '') === 'exam'
            && self::isSalesScenario((int)($row['scenario_id'] ?? 0));
    }

    public static function isCompetitionAssignment(int $assignmentId): bool {
        if($assignmentId<1)return false;
        // Classification is also used by assigned players, who cannot open a
        // manager-only report. Keep the lookup inside the current tenant.
        global $wpdb;
        try{
            $context=Access::context();$table=Schema::table('assignments');
            $row=$wpdb->get_row($wpdb->prepare(
                "SELECT id,scenario_id,assignment_mode,mode FROM `$table` WHERE id=%d AND tenant_id=%d",
                $assignmentId,(int)$context['tenant_id']
            ),ARRAY_A);
            return is_array($row)&&self::isCompetition($row);
        }catch(\Throwable){return false;}
    }

    private static function publicTeamRows(array $participants,int $assignmentId,array $readyUserIds=[]): array {
        $ready=array_fill_keys(array_map('intval',$readyUserIds),true);
        $teams = [];
        foreach ($participants as $p) {
            if (!is_array($p)) { continue; }
            $key = trim((string)($p['team_key'] ?? ''));
            if ($key === '') { $key = 'participant-'.(int)($p['id'] ?? 0); }
            if (!isset($teams[$key])) {
                $uid=(int)($p['user_id'] ?? 0);
                $teams[$key] = [
                    'team_key'=>$key,
                    'team_name'=>(string)($p['team_name'] ?: $p['display_name'] ?: 'Команда'),
                    'members'=>0,
                    'attempts'=>0,
                    'completed'=>0,
                    'best_score'=>null,
                    'active_session_id'=>0,
                    'user_id'=>$uid,
                    'team_link'=>'',
                    'ready'=>isset($ready[$uid]),
                    'state'=>'waiting',
                ];
            }
            $teams[$key]['members']++;
            $teams[$key]['attempts'] = max($teams[$key]['attempts'], (int)($p['attempts'] ?? 0));
            $teams[$key]['completed'] = max($teams[$key]['completed'], (int)($p['completed'] ?? 0));
            if (($p['best_score'] ?? null) !== null) {
                $score = (float)$p['best_score'];
                $teams[$key]['best_score'] = $teams[$key]['best_score'] === null ? $score : max((float)$teams[$key]['best_score'], $score);
            }
            $teams[$key]['active_session_id'] = max($teams[$key]['active_session_id'], (int)($p['active_session_id'] ?? 0));
            if ((int)$teams[$key]['user_id']<=0 && (int)($p['user_id']??0)>0) { $teams[$key]['user_id']=(int)$p['user_id']; }
            if (isset($ready[(int)($p['user_id']??0)])) { $teams[$key]['ready']=true; }
        }
        foreach ($teams as &$team) {
            if ((int)$team['user_id']>0) { $team['team_link']=self::teamLinkForUser($assignmentId,(int)$team['user_id']); }
            if ((int)$team['completed']>0) { $team['state']='completed'; }
            elseif ((int)$team['active_session_id']>0) { $team['state']='playing'; }
            elseif (!empty($team['ready'])) { $team['state']='ready'; }
            else { $team['state']='waiting'; }
        }
        unset($team);
        return array_values($teams);
    }

    private static function decorateDetail(array $detail): array {
        if (!self::isCompetition($detail)) { throw new AssignmentAccessDeniedException('Соревнование продаж недоступно.'); }
        $assignmentId=(int)($detail['id']??0);
        $host=self::readHostState($assignmentId);
        $teams = self::publicTeamRows((array)($detail['participants'] ?? []),$assignmentId,(array)$host['ready_user_ids']);
        $teamCount = count($teams);
        $completed = count(array_filter($teams, static fn(array $t): bool => (int)$t['completed'] > 0));
        $scored = count(array_filter($teams, static fn(array $t): bool => $t['best_score'] !== null));
        $ready = count(array_filter($teams, static fn(array $t): bool => !empty($t['ready'])));
        usort($teams, static function(array $a,array $b): int {
            $as=$a['best_score']; $bs=$b['best_score'];
            if ($as===null && $bs===null) { return strcmp((string)$a['team_name'],(string)$b['team_name']); }
            if ($as===null) { return 1; }
            if ($bs===null) { return -1; }
            return $bs <=> $as;
        });
        $detail['teams']=$teams;
        $detail['team_count']=$teamCount;
        $detail['ready_teams']=$ready;
        $detail['all_ready']=$teamCount>=2 && $ready >= $teamCount;
        $detail['completed_teams']=$completed;
        $detail['scored_teams']=$scored;
        $detail['all_completed']=$teamCount>=2 && $completed >= $teamCount;
        $detail['ranking_ready']=$teamCount>=2 && $scored >= $teamCount;
        $detail['host_mode']=(string)$host['host_mode'];
        $detail['host_mode_label']=self::hostModeLabel((string)$host['host_mode']);
        $detail['host_phase']=$detail['all_completed']?'finished':(string)$host['phase'];
        $detail['host_started_at']=(string)$host['started_at'];
        $detail['host_url']=SalesPage::url(['sales_view'=>'competition-host','sales_competition'=>$assignmentId]);
        $detail['host_legacy']=!empty($host['legacy']);
        $detail['host_announcement']=self::hostAnnouncement($detail);
        return $detail;
    }

    private static function hostAnnouncement(array $detail): string {
        $mode=(string)($detail['host_mode']??'ai');
        $phase=(string)($detail['host_phase']??'waiting');
        if ($phase==='finished') { return $mode==='ai' ? 'Все команды завершили разговор. Открываю общий рейтинг.' : 'Все команды завершили разговор. Можно открыть общий рейтинг.'; }
        if ($phase==='running') { return $mode==='ai' ? 'Соревнование началось. Команды независимо работают с одинаковым ИИ-клиентом.' : 'Соревнование запущено ведущим. Команды работают со своими экземплярами ИИ-клиента.'; }
        if ($mode==='ai') { return 'ИИ-ведущий ждёт готовности всех команд и запустит соревнование автоматически.'; }
        return 'Ведущий-человек запускает соревнование после готовности всех команд.';
    }

    public function create(array $data): array {
        if (!self::canManage()) { throw new AssignmentAccessDeniedException('Соревнование может создать организатор.'); }
        $scenarioId=(int)($data['scenario_id'] ?? 0);
        SalesDomain::versionForScenario($scenarioId);
        $rawTeams=$data['teams'] ?? [];
        if (!is_array($rawTeams)) { $rawTeams=[]; }
        if (count($rawTeams) < 2 || count($rawTeams) > 6) { throw new AssignmentValidationException('В соревновании должно быть от 2 до 6 команд.'); }
        $teamNames=[]; $names=[];
        foreach ($rawTeams as $raw) {
            if (!is_array($raw)) { throw new AssignmentValidationException('Некорректные данные команды.'); }
            $name=self::teamName((string)($raw['team_name'] ?? ''));
            $key=mb_strtolower($name);
            if (isset($names[$key])) { throw new AssignmentValidationException('Названия команд не должны повторяться.'); }
            $names[$key]=true;
            $teamNames[]=$name;
        }
        $title=trim(sanitize_text_field((string)($data['title'] ?? '')));
        if ($title==='') { $title='Соревнование продавцов'; }
        $hostMode=self::normalizeHostMode($data['host_mode']??'ai');
        $assignment=$this->assignments->create([
            'scenario_id'=>$scenarioId,
            'title'=>$title,
            'assignment_mode'=>'team_shared',
            'mode'=>'exam',
            'difficulty'=>(string)($data['difficulty'] ?? 'medium'),
            'max_attempts'=>1,
            'voice_enabled'=>true,
            'result_visibility'=>'organizer_only',
        ]);
        $id=(int)$assignment['id'];
        $createdUsers=[];
        self::initializeHostState($id,$hostMode);
        try {
            $participants=[];
            foreach ($teamNames as $name) {
                $teamUser=self::createTeamUser($id,$name);
                $createdUsers[]=(int)$teamUser['user_id'];
                $participants[]=['identifier'=>(string)$teamUser['user_id'],'team_name'=>$name];
            }
            $this->assignments->addParticipants($id,$participants);
            $assignment=$this->assignments->setStatus($id,'active');
        } catch (\Throwable $e) {
            try { $this->assignments->setStatus($id,'closed'); } catch (\Throwable) {}
            delete_option(self::hostOptionName($id));
            if ($createdUsers) {
                if (!function_exists('wp_delete_user')) { require_once ABSPATH.'wp-admin/includes/user.php'; }
                foreach ($createdUsers as $uid) { if (function_exists('wp_delete_user')) { wp_delete_user($uid); } }
            }
            throw $e;
        }
        return self::decorateDetail($assignment);
    }

    public function managed(): array {
        if (!self::canManage()) { return []; }
        $out=[];
        foreach ($this->assignments->listOwn() as $row) {
            if (!self::isCompetition($row)) { continue; }
            $host=self::readHostState((int)$row['id']);
            $row['host_mode']=(string)$host['host_mode'];
            $row['host_mode_label']=self::hostModeLabel((string)$host['host_mode']);
            $row['host_phase']=(string)$host['phase'];
            $out[]=$row;
        }
        return $out;
    }

    public function mine(): array {
        $out=[];
        foreach ($this->assignments->mine() as $row) { if (self::isCompetition($row)) { $out[]=$row; } }
        return $out;
    }

    public function detail(int $id): array { return self::decorateDetail($this->assignments->detail($id)); }

    public function hostDashboard(int $id): array {
        if (!self::canManage()) { throw new AssignmentAccessDeniedException('Экран ведущего доступен организатору.'); }
        return $this->detail($id);
    }

    public function startByHost(int $id): array {
        $detail=$this->detail($id);
        if ((string)$detail['status']!=='active') { throw new AssignmentUnavailableException('Соревнование уже закрыто.'); }
        $state=self::readHostState($id);
        if (!empty($state['legacy']) || $state['phase']==='running') { return self::decorateDetail($this->assignments->detail($id)); }
        if ((string)$state['host_mode']!=='human') { throw new AssignmentValidationException('В режиме ИИ-ведущего старт выполняется автоматически после готовности всех команд.'); }
        if (empty($detail['all_ready'])) { throw new AssignmentValidationException('Сначала дождитесь готовности всех команд.'); }
        $state['phase']='running';
        $state['started_at']=current_time('mysql',true);
        self::writeHostState($id,$state);
        return self::decorateDetail($this->assignments->detail($id));
    }

    public function waitingStatus(int $id): array {
        $mine=null;
        foreach ($this->mine() as $row) { if ((int)$row['id']===$id) { $mine=$row; break; } }
        if (!$mine) { throw new AssignmentAccessDeniedException('Это соревнование не назначено текущей команде.'); }
        $userId=get_current_user_id();
        $state=self::markReady($id,$userId);
        $teamCount=self::teamCount($id);
        $readyCount=count((array)$state['ready_user_ids']);
        return [
            'id'=>$id,
            'title'=>(string)$mine['title'],
            'scenario_title'=>(string)($mine['scenario_title']??''),
            'team_name'=>(string)(($mine['participant']['team_name']??'') ?: 'Команда'),
            'host_mode'=>(string)$state['host_mode'],
            'host_mode_label'=>self::hostModeLabel((string)$state['host_mode']),
            'phase'=>(string)$state['phase'],
            'ready_teams'=>$readyCount,
            'team_count'=>$teamCount,
            'all_ready'=>$teamCount>=2 && $readyCount >= $teamCount,
            'can_launch'=>(string)$state['phase']==='running',
            'message'=>(string)$state['phase']==='running'?'Ведущий запустил соревнование. Открываем ваш разговор…':((string)$state['host_mode']==='ai'?'Команда готова. ИИ-ведущий запустит соревнование, когда подключатся все команды.':'Команда готова. Ожидайте запуска ведущим.'),
        ];
    }

    public function close(int $id): array {
        $this->detail($id);
        $state=self::readHostState($id);
        if (empty($state['legacy'])) { $state['phase']='closed'; self::writeHostState($id,$state); }
        return self::decorateDetail($this->assignments->setStatus($id,'closed'));
    }

    public function launch(int $id): array {
        $mine=null;
        foreach ($this->mine() as $row) { if ((int)$row['id']===$id) { $mine=$row; break; } }
        if (!$mine) { throw new AssignmentAccessDeniedException('Это соревнование не назначено текущему пользователю.'); }
        $state=self::readHostState($id);
        if (empty($state['legacy']) && (string)$state['phase']!=='running') { throw new AssignmentUnavailableException('Соревнование ещё не запущено ведущим.'); }
        $launch=$this->assignments->launch($id);
        $sessionId=(int)($launch['session_id'] ?? 0);
        if ($sessionId<=0) { throw new \RuntimeException('Не удалось открыть попытку команды.'); }
        $session=Access::session($sessionId);
        SalesDomain::versionForSession($sessionId);
        $slug=(string)($mine['scenario_slug'] ?? '');
        if ($slug==='') {
            $scenario=Access::scenario((int)$session['scenario_id']);
            $slug=(string)$scenario['slug'];
        }
        return [
            'session_id'=>$sessionId,
            'existing'=>!empty($launch['existing']),
            'url'=>SalesPage::url(['sales_scenario'=>$slug,'sales_session'=>$sessionId,'sales_format'=>'competition']),
        ];
    }

    public function statusForSession(int $sessionId): array {
        global $wpdb;
        $session=Access::session($sessionId);
        if (($session['session_kind'] ?? '') !== 'assignment' || (int)($session['assignment_id'] ?? 0)<=0 || (string)($session['mode'] ?? '')!=='exam') {
            return ['competition'=>false,'all_completed'=>true,'ranking_ready'=>true,'team_count'=>0,'completed_teams'=>0,'scored_teams'=>0];
        }
        SalesDomain::versionForSession($sessionId);
        $assignmentId=(int)$session['assignment_id'];
        $context=Access::context();
        $a=Schema::table('assignments'); $p=Schema::table('assignment_participants'); $s=Schema::table('sessions'); $e=Schema::table('evaluations');
        $row=$wpdb->get_row($wpdb->prepare("SELECT id,assignment_mode,mode,status FROM `$a` WHERE id=%d AND tenant_id=%d",$assignmentId,(int)$context['tenant_id']),ARRAY_A);
        if (!$row || (string)$row['assignment_mode']!=='team_shared' || (string)$row['mode']!=='exam') {
            return ['competition'=>false,'all_completed'=>true,'ranking_ready'=>true,'team_count'=>0,'completed_teams'=>0,'scored_teams'=>0];
        }
        $teamCount=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT team_key) FROM `$p` WHERE assignment_id=%d AND team_key IS NOT NULL AND team_key<>''",$assignmentId));
        $completed=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT participant_key) FROM `$s` WHERE assignment_id=%d AND status LIKE 'completed_%%'",$assignmentId));
        $scored=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT se.participant_key) FROM `$s` se INNER JOIN `$e` ev ON ev.session_id=se.id AND ev.final_score IS NOT NULL WHERE se.assignment_id=%d AND se.status LIKE 'completed_%%'",$assignmentId));
        return [
            'competition'=>true,
            'assignment_id'=>$assignmentId,
            'status'=>(string)$row['status'],
            'team_count'=>$teamCount,
            'completed_teams'=>$completed,
            'scored_teams'=>$scored,
            'all_completed'=>$teamCount>=2 && $completed >= $teamCount,
            'ranking_ready'=>$teamCount>=2 && $scored >= $teamCount,
        ];
    }

    public function resultGate(int $sessionId): array {
        $status=$this->statusForSession($sessionId);
        if (empty($status['competition']) || !empty($status['all_completed'])) { return ['visible'=>true]+$status; }
        return [
            'visible'=>false,
            'message'=>'В соревновании итоговый разбор откроется после завершения всех команд.',
        ]+$status;
    }
}
