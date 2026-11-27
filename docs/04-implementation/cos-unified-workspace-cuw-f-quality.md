---
title: CUW-F — дизайн-система та якість Unified Workspace
description: Компонентний контракт, матриця перевірок, візуальні еталони та передача інтегратору.
status: preacceptance
updated: 2026-11-27
kind: implementation
---

# CUW-F: Design System & Quality

[Issue #524](https://github.com/Murakhovsky/TerraNova2026/issues/524), [parent #518](https://github.com/Murakhovsky/TerraNova2026/issues/518).
База: `10fc649df6aafdca432ec329ebd737f6a4abd1ec`. Master Spec §13–16, §19 UX-09 foundation, AC-12/13/17.

## Реалізація

| Контракт | Рішення |
|---|---|
| Foundation / Semantic / Component / Theme | Wave 12 `tokens.css`, існуючі Bootstrap 5.3, Twig/AssetMapper/Stimulus; підвищений контраст semantic primary, muted, warning, control border та focus. System dark має ту саму палітру Shell, що explicit dark. |
| Feedback | `CosOutcomeState` — 16 локалізованих presentation states. Лише `completed_verified` позитивний; невідомий/непідтверджений результат — warning. Нерозпізнаний код переходить у unknown. |
| DataGrid | Локалізовані control labels, keyboard-accessible table region, mobile cards, єдина selection semantics, danger action parity. Denied state не рендерить рядків, форм чи controls. Це лише presentation defense; авторизація лишається за Policy/Query. |
| Forms / approvals | 44px mobile controls, inputmode, контраст меж полів, коректний списковий markup помилок під live alert, фокус Cancel, очищення step-up при повторному відкритті. Не є backend step-up чи дозволом на виконання. |
| Entity / goal / agent | Перенесення довгих ідентифікаторів та тексту доказів, нормалізація ARIA progress, indeterminate при невідомій шкалі. |
| Catalogue | Існуючий `/dev/ui`, посилання на `/dev/ui?quality=1` (той самий manager-only маршрут). 66/66 зареєстрованих Cos* компонентів. Синтетичні стани, картки, метрики, Timeline, Evidence, Result/Process/Expert, форма, approval, drawer, DataGrid. |

Не вводимо новий frontend framework, Tabler runtime або нові бізнесові/політичні контракти. Підтримуємо існуючий licensed Tabler icon subset, Bootstrap і Symfony UX. Нові примітиви є візуальними, не визначають execution authority.

### Використання в A–E

`CosOutcomeState` приймає лише вже спроєктований, авторизований стан та необов'язковий безпечний текст деталей. Немає datastore або permission inference.

```twig
<twig:CosOutcomeState state="external_outcome_unknown" />
<twig:CosOutcomeState state="waiting_approval" />
<twig:CosOutcomeState state="completed_verified" :detail="authorizedReceiptSummary" />
```

Останній виклик припустимий тільки за наявності доказу з канонічного джерела. Не перетворювати факти завершення в verified без незалежного доказу. Критичні повідомлення розташовувати поза conditional disclosure у всіх рівнях досвіду. Невідомий outcome не містить команди retry. Дані користувача екрануються Twig.

## Повторювана перевірка (ізольовані fixtures)

Виконати з кореня репозиторію після інсталяції locked npm і Symfony dependencies. Використати тестову конфігурацію runtime без production credentials, з локальними DATABASE_URL, Redis/Mercure DSN, APP_SECRET. Не потрібен seed, провайдер чи зовнішній запис для цих fixtures.

```sh
npm ci
cd symfony
composer install --no-interaction --prefer-dist
export APP_ENV=test APP_DEBUG=0
php bin/console importmap:install
php bin/console cache:clear
mkdir -p var/translations
php bin/console asset-map:compile
php bin/console cos:web:cuw-quality:fixture ../tmp/cuw-quality-fixtures
php bin/console cos:web:design-system:smoke
php bin/console cos:experience:design-system:audit --strict
cd ..
php tests/unit/cuw_design_quality_contract.php
CHROME_PATH=/path/to/chromium node tests/browser/cuw_design_quality.mjs
```

CLI fixture-команда доступна виключно у `APP_ENV=test`, рендерить справжні Symfony Twig та Components. Локальна статична HTTP fixture подає тільки синтетичний HTML і скомпільовані public assets, без доступу до runtime/API. Це не обхід production auth. За бажанням інтегратор повторює на ізольованому реальному середовищі: `CUW_QUALITY_BASE_URL` та `CUW_QUALITY_STORAGE_STATE` для авторизованого manager (не production session). Baseline update дозволено лише локально за `--update-baseline`; необхідний review скриншотів.

### Матриця та бюджети

8 парних профілів: 1440×1000 і 390×844; UK/EN, light/dark/system dark/system light, comfortable/compact, reduced motion. Кожен профіль: axe WCAG 2.x A/AA на каталозі й approval; 16 states, denied grid, mobile cards/table, ARIA invalid, keyboard tabs з однаковим synthetic EntityRef, Escape/focus return, очищення step-up, no document overflow, JS errors, screenshot regression.

Візуальний еталон `tests/browser/baselines/cuw-design-quality.json` — 72 стиснені 40×40 RGB signatures із реальних PNG; порівняння розмірів і відстані кольорів. Це грубий perceptual guard, не pixel-perfect тест. Використовувати Chromium 156.0.8078.79 та ті самі системні шрифти для deterministic compare, або затвердити новий reference. PNG і структурований report створюються в `tmp/cuw-design-quality`. Декілька зменшених скриншотів і фактичний звіт збережено під `resources/experience/design-system/evidence/`.

Dev catalog — окремий бюджет, бо він завантажує весь наявний AssetMapper inventory: до 180 network resources, 2 500 DOM nodes, 1.5 MiB compressed JS (3 MiB decoded), 1 MiB CSS, 4 MiB transfer, TTFB ≤ 1 s, navigation ≤ 3.5 s. Public/production budget 120 resources з existing `web_performance_budget.mjs` **не послаблено**. Локальні виміри не є production SLA; зафіксовано 167 resources у fixture, що не слід маскувати.

## Інтеграційний gate

Shell-ownership залишено інтегратору #518: source branch не змінює canonical Shell, routes/services, shared translations або workflow CI. Dedicated translation domain `cuw_quality` не конфліктує з A–E. Shell preview знятий тією самою матрицею. `report.json` окремо містить `shellAcceptance`, axe та overlap findings. Для суворого інтеграційного gate встановити `CUW_QUALITY_REQUIRE_SHELL=1`. Патч `resources/experience/design-system/cuw-f-shell-integration.patch` пропонує спільну адаптацію Shell (overlapping controls 390px, dark contrast, label/accessibility). На тимчасовій локальній копії з цим патчем суворий Shell gate пройшов 8/8; фактичний patch report і comparison screenshot збережено в evidence. Застосовувати його інтегратором після консолідації A–E, перевірити на actual Shell, повторно затвердити visual reference; не сліпо накладати на змінений Shell.

- AC-12: ізольовані mobile specimens та card parity; реальні доменні сторінки combined SHA потребують acceptance.
- AC-13: компонентні keyboard/axe тести; Shell findings є окремим gate.
- AC-17: additive changes, revert PR / не підключати новий компонент; реальний feature flag/rollout залишається UX-09.
- Не заявляємо D3–D5, live Goal/Operation/Agent verification, реальну tenant authorization або UX-09 cutover. Не модифікуємо #431 staging gate.
- Для CI інтегратор додає команди вище в існуючий Runtime/Frontend job і підключає artifacts `tmp/cuw-design-quality`; workflow не змінюється паралельним потоком.
