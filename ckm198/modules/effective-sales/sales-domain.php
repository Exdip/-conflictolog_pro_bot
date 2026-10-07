<?php
namespace CKM\EffectiveSales;
if (!defined('ABSPATH')) { exit; }

use CKM\NegotiationMaster\Access;

final class SalesDomain {
    public const LIBRARY_SLUG = 'effective-sales';
    public const DOMAIN = 'sales';

    public static function decode(?string $json): array {
        if ($json === null || $json === '') { return []; }
        try { $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR); return is_array($value) ? $value : []; }
        catch (\Throwable) { return []; }
    }

    public static function mechanics(array $version): array { return self::decode((string)($version['mechanics_json'] ?? '')); }

    public static function isSalesVersion(array $version): bool {
        return (string)(self::mechanics($version)['training_domain'] ?? '') === self::DOMAIN;
    }

    public static function assertVersion(array $version): array {
        if (!self::isSalesVersion($version)) { throw new \InvalidArgumentException('Этот сценарий не относится к тренажёру продаж.'); }
        return $version;
    }

    public static function versionForScenario(int $scenarioId): array {
        $scenario = Access::scenario($scenarioId);
        $context = Access::context();
        if ($scenario['tenant_id'] !== null && (int)$scenario['tenant_id'] !== (int)$context['tenant_id']) { throw new \InvalidArgumentException('Сценарий другого арендатора недоступен.'); }
        if (($scenario['status'] ?? '') !== 'published' || empty($scenario['current_version_id'])) { throw new \InvalidArgumentException('Сценарий недоступен.'); }
        $version = Access::version((int)$scenario['current_version_id']);
        if ((int)$version['scenario_id'] !== $scenarioId) { throw new \InvalidArgumentException('Версия не относится к выбранному сценарию.'); }
        return self::assertVersion($version);
    }

    public static function versionForSession(int $sessionId): array {
        $session = Access::session($sessionId);
        $context = Access::context();
        if ((int)$session['tenant_id'] !== (int)$context['tenant_id']) { throw new \InvalidArgumentException('Попытка другого арендатора недоступна.'); }
        return self::assertVersion(Access::version((int)$session['scenario_version_id']));
    }

    public static function publicMechanics(array $version): array {
        $mechanics = self::mechanics($version);
        return [
            'training_domain'=>self::DOMAIN,
            'ui_profile'=>(string)($mechanics['ui_profile'] ?? 'sales_v1'),
            'completion_mode'=>(string)($mechanics['completion_mode'] ?? 'manual_sales'),
            'opening_message'=>(string)($mechanics['opening_message'] ?? ''),
            'stages'=>array_values(array_filter(array_map('strval', (array)($mechanics['stages'] ?? [])))),
            'situation_class'=>(string)($mechanics['situation_class'] ?? ''),
            'deal_stage'=>(string)($mechanics['deal_stage'] ?? ''),
            'primary_skill'=>(string)($mechanics['primary_skill'] ?? ''),
            'success_mode'=>(string)($mechanics['success_mode'] ?? 'advance'),
        ];
    }
}
