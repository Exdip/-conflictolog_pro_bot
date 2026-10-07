<?php
namespace CKM\NegotiationMaster;
define('ABSPATH', __DIR__.'/');
define('ARRAY_A', 'ARRAY_A');
require dirname(__DIR__).'/modules/negotiation-master/repositories.php';
require dirname(__DIR__).'/modules/negotiation-master/application/topicality-guard.php';

// WordPress/DB boundary doubles; exercise the real guard and message repository.
final class Access {
    public static function session(int $id): array {
        if ($id !== 71) { throw new \RuntimeException('Access denied'); }
        return ['scenario_id'=>1, 'scenario_version_id'=>2];
    }
    public static function scenario(int $id): array { return ['title'=>'Продажи']; }
    public static function version(int $id): array {
        return ['mechanics_json'=>json_encode(['opening_message'=>'Прогноз погоды мешает мероприятию.'])];
    }
}
final class Schema {
    public static function table(string $key): string { return 'wp_ckm_neg_'.$key; }
}
$wpdb = new class {
    public array $rows = [];
    public function prepare(string $sql, ...$args): string {
        if (str_contains($sql, 'messages')) {
            if ($args !== [71, 6] || !str_contains($sql, "actor IN ('player','opponent')") ||
                !str_contains($sql, "channel IN ('dialogue','negotiation')")) {
                throw new \RuntimeException('Unscoped dialogue query');
            }
        }
        return $sql;
    }
    public function get_col(string $sql): array { return []; }
    public function get_results(string $sql, string $format): array { return $this->rows; }
};
$guard = new TopicalityGuard();
// Opening shown by the sales UI, with no saved messages yet.
$guard->assertRelevant(71, 'Какой прогноз погоды вас беспокоит?');
// A later client reply replaces the opening as the topical context.
$wpdb->rows = [['content'=>'Новости изменили наши планы.', 'actor'=>'opponent']];
$guard->assertRelevant(71, 'Какие новости изменили ваши планы?');
try {
    $guard->assertRelevant(72, 'Здравствуйте');
    throw new \LogicException('Unauthorized session accepted');
} catch (\RuntimeException $e) {
    if ($e->getMessage() !== 'Access denied') { throw $e; }
}
echo "PASS: opening, stored dialogue, session access\n";
