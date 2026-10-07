<?php
if (!defined('ABSPATH')) exit;

function ckm_quiz_pro_table(string $logical): string {
    global $wpdb;
    $map=[
        'quizzes'=>'ckm_quiz_quizzes','questions'=>'ckm_quiz_questions','games'=>'ckm_quiz_games','teams'=>'ckm_quiz_game_teams','members'=>'ckm_quiz_game_team_members','answers'=>'ckm_quiz_answers','score_events'=>'ckm_quiz_score_events','events'=>'ckm_quiz_events','formats'=>'ckm_quiz_formats','rounds'=>'ckm_quiz_rounds',
        'mechanics'=>'ckm_quiz_mechanics','drafts'=>'ckm_quiz_team_drafts','appeals'=>'ckm_quiz_appeals','thinking_analysis'=>'ckm_quiz_thinking_analysis','methodology_analysis'=>'ckm_quiz_methodology_analysis','report_jobs'=>'ckm_quiz_report_jobs','packages'=>'ckm_quiz_packages','entitlements'=>'ckm_quiz_entitlements',
        'game_access'=>'ckm_quiz_game_access','ready_instances'=>'ckm_quiz_ready_instances','ready_game_revisions'=>'ckm_quiz_ready_game_revisions','ready_game_releases'=>'ckm_quiz_ready_game_releases','scenario_orders'=>'ckm_quiz_scenario_orders','history_trash'=>'ckm_quiz_history_trash','history_audit'=>'ckm_quiz_history_audit','history_reports'=>'ckm_quiz_history_reports','access'=>'ckm_quiz_access_unused','sales'=>'ckm_quiz_sales_unused',
        'negotiation_sessions'=>'ckm_negotiation_sessions','negotiation_turns'=>'ckm_negotiation_turns','negotiation_events'=>'ckm_negotiation_events','negotiation_requests'=>'ckm_negotiation_requests'
    ];
    return isset($map[$logical]) ? $wpdb->prefix.$map[$logical] : '';
}

function ckm_quiz_pro_register_standalone_services(): void {
    ckm_quiz_core_register_service('storage_table', static fn(string $logical): string => ckm_quiz_pro_table($logical));
    ckm_quiz_core_register_service('team_policy', static function(array $quiz=[]): array {
        $format = sanitize_key((string)($quiz['format_key'] ?? ''));
        if (in_array($format, ['chgk','solution_price','negotiation_duel'], true)) return ['min'=>1,'max'=>10];
        return ['min'=>2,'max'=>10];
    });
    ckm_quiz_core_register_service('normalize_team_count', static fn($value,int $min,int $max): int => max($min,min($max,(int)$value)));
    ckm_quiz_core_register_service('team_default_name', static fn(int $slot): string => 'Команда '.ckm_quiz_core_team_key_from_slot($slot));
    ckm_quiz_core_register_service('validate_room_access', static function(int $saleId,int $accessId,bool $testMode,array $quiz): array {
        return ['ok'=>true,'status'=>200,'sale_id'=>0,'access_id'=>0,'standalone'=>true,'testMode'=>$testMode];
    });
    ckm_quiz_core_register_service('consume_access_locked', static fn(array $game): array => ['ok'=>true,'status'=>200,'standalone'=>true,'consumed'=>false]);
    ckm_quiz_core_register_service('preflight_access', static fn(array $game): array => ['ok'=>true,'detail'=>'standalone_local']);
    ckm_quiz_core_register_service('history_game_row', static function(int $id){ global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.ckm_quiz_pro_table('games').' WHERE id=%d',$id),ARRAY_A) ?: null; });
    ckm_quiz_core_register_service('history_team_rows', static function(int $id): array { global $wpdb; return $wpdb->get_results($wpdb->prepare('SELECT * FROM '.ckm_quiz_pro_table('teams').' WHERE game_id=%d ORDER BY slot_no,id',$id),ARRAY_A) ?: []; });
    ckm_quiz_core_register_service('history_finished_game_ids', static function(): array { global $wpdb; return array_map('intval',$wpdb->get_col("SELECT id FROM ".ckm_quiz_pro_table('games')." WHERE status='finished' ORDER BY id DESC") ?: []); });
    ckm_quiz_core_register_service('ai_provider_choices', static fn(): array => []);
    ckm_quiz_core_register_service('ai_default_provider', static fn(array $choices): string => '');
    ckm_quiz_core_register_service('ai_text_call', static fn(array $request): array => ['ok'=>false,'code'=>'cloud_not_configured','error'=>'Облачный сервис ещё не подключён.']);
    ckm_quiz_core_register_service('methodology_catalog_rows', static fn(): array => []);
}
ckm_quiz_pro_register_standalone_services();
