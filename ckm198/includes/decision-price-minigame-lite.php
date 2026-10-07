<?php
if (!defined('ABSPATH')) exit;

/**
 * Управленческая игра "Ваш выбор" — короткая демонстрационная версия.
 * Подготовка данных для первого запуска после покупки.
 */
function ckm_quiz_pro_decision_price_demo_package(): array {
    return [
        'format_key'=>'solution_price',
        'title'=>'Управленческая игра "Ваш выбор" — демо',
        'case'=>'Компания потеряла ключевого клиента. Предложите решение и объясните последствия.',
        'time_minutes'=>5,
    ];
}
