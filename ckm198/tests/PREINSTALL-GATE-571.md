# PRE-INSTALL GATE .571

Версия: `0.3.23.539-dev.571-SALES-STANDARD-PREINSTALL-GATE`. Итог полного gate: **PASS**.

Разобраны все **193 исходных FAIL** из 254 исторических entrypoint. Все исходные файлы сохранены и запущены; исключений, удалений и waiver нет. Пофайловый журнал с исходными ошибками, причинами, исправлениями, SHA-256 и итоговым статусом: [PREINSTALL-GATE-571-AUDIT.json](PREINSTALL-GATE-571-AUDIT.json).

| Причина | Исходные failing suite |
|---|---:|
| Устаревший тест | 161 |
| Неполная фикстура | 9 |
| Перенесённая архитектура | 18 |
| Реальный дефект | 5 |
| Всего | 193 |

У одного suite может быть несколько причин. Таблица назначает одну основную причину в порядке: реальный дефект, неполная фикстура, перенесённая архитектура, устаревший тест. Полный журнал сохраняет все причины, включая первоначальные version pins и ошибки, до которых ранний exit прежде не позволял дойти. Пять failing suite выявили четыре независимых дефекта, поэтому числа suite и дефектов различаются.

Устаревшие проверки фиксировали старые версии плагина/schema/evaluator, старое количество контента, фрагменты исходного кода и формулировки UI. Текущая версия проверяется через согласованность WordPress header и константы; содержимое — по актуальным manifest и прежним обязательным сценариям. Неполные фикстуры не содержали WordPress add_filter, state_revision, typed item/discovery state, criterion_code или зависимостей текущего bootstrap. Перенесённые обязанности проверяются у RecoveryService, resumable evaluator, текущей проекции скрытых фактов, каталога и полного session dedup вместо старых API.

Исправлено исторических тестовых файлов: **193**. Ещё одна проверка версии в новом lifecycle-suite стала проверкой текущей синхронизированной версии. Три ранее нормализованных CRLF файла функционально не изменены; их изменения сохранены. Добавлены четыре regression entrypoint для production-дефектов.

Подтверждённые дефекты и минимальные исправления:

1. Редактор отвергал четыре существующих seed-правила `process`. Этот тип добавлен в whitelist и русскоязычный select; содержание правил сохраняется.
2. В пакете «цена 960 000 рублей и срок поставки 40 дней» сумма ошибочно привязывалась к сроку. Однозначная соседняя единица теперь имеет приоритет перед заголовком следующего условия.
3. «Три основных последствия задержки поставки» становилось сроком 3. Числительное словами требует непосредственной связи с предметом/единицей; корректные словесные предложения сохранены.
4. UI mapper оставлял `custom`, хотя select принимал `custom_rule`, и предпочтение терялось. Исправлен только mapper; backend-контракт сохранён. Реальный UI mapper и PHP normalizer прошли roundtrip всех 275 seed items (1102 JS + 3371 PHP assertions).

Три negative control против неизменного кода .570 воспроизводят исправленные дефекты (два числовых дефекта покрыты одним suite). Имена production-файлов, regression paths и доказательства перечислены в JSON-журнале.

Production-файлы, изменённые относительно сохранённого ZIP .570:

- `ckm-quiz-pro.php`
- `modules/negotiation-master/ai/arbiter/arbiter-service.php`
- `modules/negotiation-master/application/scenario-builder-service.php`
- `modules/negotiation-master/assets/negotiation-builder.js`
- `modules/negotiation-master/public/builder-page.php`

Header и константа повышены синхронно. Production-изменения вне этих файлов отсутствуют; прежние исправления Adaptive recert и Architecture guards сохранены.

| Полная проверка | Итог |
|---|---|
| Исторические suite | 254 PASS / 0 FAIL |
| Все исторические и новые entrypoint | 262 PASS / 0 FAIL / 0 SKIP / 0 timeout |
| Новый lifecycle-suite .570/.571 | 165/165 assertions PASS (4 suite) |
| PHP parser, всё дерево | 451/451 PASS |
| Реальный PHP 8.3.33 TOKEN_PARSE, всё дерево | 451/451 PASS |
| node --check изменённых/новых JS | 3/3 PASS |
| Дополнительный node --check всех JS дерева | 21/21 PASS |
| Tenant isolation | PASS: реальные access/repository/controller services и SQL в SQLite; foreign tenant/owner/participant cases |
| Architecture source guards | PASS: runtime PHP/JS двух модулей |

PHP выполнялся в официальном WordPress Playground WASM CLI, с передачей фактического exit code. PHP parser проверяет синтаксис отдельно и не заменяет исполнение. Каждый PHP entrypoint выполняется в отдельном процессе; runner проходит все suite даже при ошибке одного. FAIL, warnings/notices, ненулевой exit code, SKIP и timeout не превращаются в PASS. Исходящие HTTP/socket/curl/mail/shell отключены, среда дочерних процессов не содержит credentials хоста.

Architecture guards охватывают runtime PHP/JS `modules/effective-sales/` и `modules/negotiation-master/`, включая public/API/assets и изменённые файлы. Тесты, статический seed content и migrations исключены из проверки бизнес-зависимостей; синтаксис всего PHP-дерева проверен без этих исключений. PHP AST проверяет любые положительные scenario IDs и scenario-version IDs в comparisons/assignments/defaults/arrays/allowlists/switch; source guards дополнительно проверяют SQL/JS, `leogorn` и production hostname. Нулевые sentinels и проверки допустимого диапазона разрешены. Проверка исходного кода не является доказательством полного dataflow.

Сохранены две прежние presentation-ссылки на один логотип `https://ckkm.ru/wp-content/uploads/2026/09/CKKM-logo-icon.webp` в `public/app-shell.php` обоих модулей. Это явно учтённые branding defaults, не production business/API endpoints. Никаких других production hostname зависимостей в проверенном runtime scope не найдено; ссылки не скрыты общим исключением файла.

Повторный полный запуск из корня плагина:

```sh
node tests/run-preinstall-gate.mjs --php /path/to/php83 --parser /path/to/tools/node_modules/php-parser --jobs 4 --output /path/to/reports
node tests/run-sales-lifecycle-570.mjs --php /path/to/php83
```

В текущей облачной среде вместо native PHP используется `/workspace/ckm-dev-tools/php-cli.mjs`, а package parser расположен в `/workspace/ckm-dev-tools/node_modules/php-parser`. Инструменты и логи находятся вне плагина. Для WASM adapter в `--php` передаётся `.mjs`, для native PHP — бинарный файл. Inventory содержит все 254 исторических пути; runner дополнительно обнаруживает все непосредственные PHP-тесты и `*-test.mjs`/`*-regression.mjs` в трёх test-директориях. Waiver не требуется.

Live не проверялось: установка и UI WordPress, WPVibe, production endpoints, реальные игры/LLM, платежи/внешние интеграции, production MySQL и фактическая конкурентная нагрузка. Offline tests не подтверждают конфигурацию развёрнутого сайта.
