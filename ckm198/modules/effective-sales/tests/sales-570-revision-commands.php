<?php
declare(strict_types=1);

namespace {
    define('ABSPATH', __DIR__ . '/');
    $GLOBALS['ckm570_user'] = 17;
    $GLOBALS['ckm570_tenant'] = 31;
    $GLOBALS['ckm570_meta'] = [];
    $GLOBALS['ckm570_writes'] = 0;
    $GLOBALS['ckm570_manage'] = true;
    $GLOBALS['ckm570_impact'] = [];

    function get_current_user_id(): int { return $GLOBALS['ckm570_user']; }
    function ckmqp_scope_id(): int { return $GLOBALS['ckm570_tenant']; }
    function get_user_meta(int $user, string $key, bool $single = false): mixed {
        return $GLOBALS['ckm570_meta'][$user][$key] ?? '';
    }
    function update_user_meta(int $user, string $key, mixed $value): bool {
        $GLOBALS['ckm570_meta'][$user][$key] = $value;
        $GLOBALS['ckm570_writes']++;
        return true;
    }
    function current_time(string $type, bool $gmt = false): string { return $gmt ? '2026-10-07 09:00:00' : '2026-10-07 12:00:00'; }
}

namespace CKM\EffectiveSales {
    // Only the external read model/access boundary is substituted. The command,
    // permissions, transition and scoped repository under test are production PHP.
    final class SalesStandardImpactService {
        public static function canManage(): bool { return $GLOBALS['ckm570_manage']; }
        public static function dashboard(string $id): array {
            $key = \get_current_user_id() . ':' . \ckmqp_scope_id() . ':' . $id;
            return $GLOBALS['ckm570_impact'][$key] ?? ['available' => false];
        }
    }
}

namespace {
    require dirname(__DIR__) . '/application/sales-script-service.php';
    require dirname(__DIR__) . '/application/sales-standard-revision-decision-service.php';

    use CKM\EffectiveSales\SalesScriptService;
    use CKM\EffectiveSales\SalesStandardRevisionDecisionService;

    $passes = 0;
    function check(string $name, bool $condition): void {
        global $passes;
        if (!$condition) { throw new \RuntimeException('FAIL: ' . $name); }
        $passes++;
        echo 'PASS: ' . $name . PHP_EOL;
    }
    function rejects(string $name, callable $command, string $message): void {
        try { $command(); }
        catch (\RuntimeException | \InvalidArgumentException $error) {
            check($name, str_contains($error->getMessage(), $message));
            return;
        }
        check($name, false);
    }
    function scriptRow(string $id, int $revision = 2): array {
        return [
            'id' => $id, 'status' => 'approved', 'practice_standard_revision' => $revision,
            'practice_methodology_notes' => [
                ['focus_code' => 'next_step', 'status' => 'active'],
                ['focus_code' => 'value_proposition', 'status' => 'active'],
            ],
            'practice_standard_events' => [['focus_code' => 'next_step', 'revision' => $revision, 'action' => 'activated', 'at' => '2026-10-01 10:00:00']],
            'updated_at' => '2026-10-01 10:00:00',
        ];
    }
    function store(array $script): void {
        $user = get_current_user_id(); $tenant = (string) ckmqp_scope_id();
        $all = $GLOBALS['ckm570_meta'][$user]['ckm_sales_scripts_v1'] ?? [];
        $items = $all[$tenant] ?? [];
        $items = array_values(array_filter($items, static fn(array $row): bool => $row['id'] !== $script['id']));
        $items[] = $script; $all[$tenant] = $items;
        $GLOBALS['ckm570_meta'][$user]['ckm_sales_scripts_v1'] = $all;
    }
    function impact(string $id, string $code, int $revision = 2): void {
        $GLOBALS['ckm570_impact'][get_current_user_id() . ':' . ckmqp_scope_id() . ':' . $id] = [
            'available' => true, 'revision' => $revision, 'focus_code' => 'next_step', 'focus_title' => 'Следующий шаг',
            'employee_count' => 2, 'completed_count' => 2, 'comparable_count' => 2, 'passed_count' => 2,
            'average_before' => 60, 'average_after' => 85, 'average_delta' => 25, 'pass_rate' => 100,
            'conclusion' => ['code' => $code, 'title' => 'Контрольный результат'],
        ];
    }

    foreach (['confirm' => 'confirmed', 'rework' => 'mixed'] as $choice => $code) {
        $id = 'command-' . $choice;
        store(scriptRow($id)); impact($id, $code);
        $first = SalesStandardRevisionDecisionService::decide($id, $choice, 2);
        check($choice . ' executes production command', !$first['reused'] && $first['decision']['decision'] === $choice);
        check($choice . ' stores one history row', count($first['script']['practice_standard_decisions']) === 1);
        check($choice . ' keeps other active rule', $first['script']['practice_methodology_notes'][1]['status'] === 'active');
        check($choice . ' sets target rule status', $first['script']['practice_methodology_notes'][0]['status'] === ($choice === 'confirm' ? 'active' : 'adopted'));
        check($choice . ' advances revision only on rollback', $first['script']['practice_standard_revision'] === ($choice === 'confirm' ? 2 : 3));
        if ($choice === 'rework') {
            check('rework emits one rollback event', count($first['script']['practice_standard_events']) === 2);
            check('rollback stores UTC alongside local display time', $first['script']['practice_standard_events'][1]['at_utc'] === '2026-10-07 09:00:00' && $first['script']['practice_standard_events'][1]['at'] === '2026-10-07 12:00:00');
            // A newer standard can exist after rollback; repeating the old request
            // must reuse its original revision rather than review the remaining rule.
            impact($id, 'awaiting', 3);
            $fixtureKey = get_current_user_id() . ':' . ckmqp_scope_id() . ':' . $id;
            $GLOBALS['ckm570_impact'][$fixtureKey]['completed_count'] = 0;
            $GLOBALS['ckm570_impact'][$fixtureKey]['comparable_count'] = 0;
        }
        $beforeRepeat = $GLOBALS['ckm570_meta']; $writes = $GLOBALS['ckm570_writes'];
        $repeat = SalesStandardRevisionDecisionService::decide($id, $choice, 2);
        check('repeat ' . $choice . ' is reused', $repeat['reused'] && $repeat['decision'] === $first['decision']);
        check('repeat ' . $choice . ' has no repository write', $GLOBALS['ckm570_meta'] === $beforeRepeat && $GLOBALS['ckm570_writes'] === $writes);
        $opposite = SalesStandardRevisionDecisionService::decide($id, $choice === 'confirm' ? 'rework' : 'confirm', 2);
        check('opposite request reuses recorded ' . $choice, $opposite['reused'] && $opposite['decision']['decision'] === $choice);
        if ($choice === 'rework') {
            rejects('implicit retry cannot review new revision without exams', static fn() => SalesStandardRevisionDecisionService::decide($id, 'rework'), 'завершённой контрольной проверки');
            check('implicit retry cannot duplicate rollback or disable another rule', $GLOBALS['ckm570_meta'] === $beforeRepeat && $GLOBALS['ckm570_writes'] === $writes);
            $GLOBALS['ckm570_impact'][$fixtureKey] = ['available' => false];
            $unavailableRepeat = SalesStandardRevisionDecisionService::decide($id, 'rework', 2);
            check('explicit rollback retry survives unavailable dashboard', $unavailableRepeat['reused']);
        }
    }

    store(scriptRow('stale', 4)); impact('stale', 'confirmed', 4);
    $unchanged = $GLOBALS['ckm570_meta']; $writes = $GLOBALS['ckm570_writes'];
    rejects('stale unrecorded revision rejected', static fn() => SalesStandardRevisionDecisionService::decide('stale', 'confirm', 3), 'Редакция стандарта изменилась');
    check('stale request leaves storage untouched', $GLOBALS['ckm570_meta'] === $unchanged && $GLOBALS['ckm570_writes'] === $writes);
    impact('stale', 'confirmed', 5);
    rejects('revision changed during dashboard rejected', static fn() => SalesStandardRevisionDecisionService::decide('stale', 'confirm', 4), 'Редакция стандарта изменилась');

    store(scriptRow('blocked')); impact('blocked', 'preliminary_positive');
    $fixtureKey = get_current_user_id() . ':' . ckmqp_scope_id() . ':blocked';
    $GLOBALS['ckm570_impact'][$fixtureKey]['comparable_count'] = 1;
    rejects('one comparable employee cannot confirm through command', static fn() => SalesStandardRevisionDecisionService::decide('blocked', 'confirm', 2), 'минимум два сопоставимых сотрудника');
    impact('blocked', 'insufficient'); $GLOBALS['ckm570_impact'][$fixtureKey]['comparable_count'] = 0;
    rejects('no baseline cannot rework through command', static fn() => SalesStandardRevisionDecisionService::decide('blocked', 'rework', 2), 'Нет сопоставимого результата');
    impact('blocked', 'awaiting'); $GLOBALS['ckm570_impact'][$fixtureKey]['completed_count'] = 0;
    $GLOBALS['ckm570_impact'][$fixtureKey]['comparable_count'] = 0;
    rejects('no completed exams cannot confirm through command', static fn() => SalesStandardRevisionDecisionService::decide('blocked', 'confirm', 2), 'завершённой контрольной проверки');
    rejects('unknown decision rejected', static fn() => SalesStandardRevisionDecisionService::decide('blocked', 'invalid', 2), 'Неизвестное решение');
    $GLOBALS['ckm570_manage'] = false;
    rejects('organizer permission required', static fn() => SalesStandardRevisionDecisionService::decide('blocked', 'confirm', 2), 'доступно организатору');
    $GLOBALS['ckm570_manage'] = true;
    check('blocked commands create no history', empty(SalesScriptService::find('blocked')['practice_standard_decisions']));

    store(scriptRow('isolated', 6)); impact('isolated', 'confirmed', 6);
    $tenant31 = $GLOBALS['ckm570_meta'][17]['ckm_sales_scripts_v1']['31'];
    $GLOBALS['ckm570_tenant'] = 32;
    rejects('other tenant cannot see source method', static fn() => SalesStandardRevisionDecisionService::decide('isolated', 'confirm', 6), 'Методика не найдена');
    store(scriptRow('isolated', 8)); impact('isolated', 'mixed', 8);
    SalesStandardRevisionDecisionService::decide('isolated', 'rework', 8);
    check('same method id in another tenant changes only its scope', $GLOBALS['ckm570_meta'][17]['ckm_sales_scripts_v1']['31'] === $tenant31);
    check('tenant-local rollback stored in tenant32', SalesScriptService::find('isolated')['practice_standard_revision'] === 9);
    $GLOBALS['ckm570_tenant'] = 31; $GLOBALS['ckm570_user'] = 18;
    rejects('another owner cannot decide on source method', static fn() => SalesStandardRevisionDecisionService::decide('isolated', 'confirm', 6), 'Методика не найдена');
    store(scriptRow('isolated', 10)); impact('isolated', 'confirmed', 10);
    SalesStandardRevisionDecisionService::decide('isolated', 'confirm', 10);
    check('same method id in another owner changes only owner metadata', $GLOBALS['ckm570_meta'][17]['ckm_sales_scripts_v1']['31'] === $tenant31);
    check('owner-local confirm is stored', SalesScriptService::find('isolated')['practice_standard_decisions'][0]['revision'] === 10);

    echo 'sales-570 revision commands: ' . $passes . ' PASS' . PHP_EOL;
}
