---
title: Огляд домену Finance
description: Канонічний фінансовий домен COS із контрольованими бюджетами, резервами та незалежним погодженням операцій.
status: draft
updated: 2026-11-19
kind: domain
version: 0.2.2
contract: domain-v1
---

# Огляд домену Finance

Finance `0.2.1` додає ізольований бюджетний контур **F1 Budget Control** поверх базового Finance V1. Це **не повноцінна бухгалтерія**, не модуль платежів і не підтверджена інтеграція з банками або рекламними провайдерами.

Усі фінансові операції залишаються вимкненими для tenants за замовчуванням. У production модуль не активовано; приймання реального бюджету та витрат не завершено.

## Межі власності

Finance володіє Budget Envelope, його лімітом, резервами, доступним залишком, журналом фінансових дій та погодженими змінами стану. Marketing володіє кампаніями, рекламними креативами та фактичними Meta observations. Реальні платежі й бухгалтерські витрати в F1 не виконуються.

- Ліміт фіксується в **цілих мінімальних одиницях валюти**, наприклад у центах USD. Дробові числа та невизначена валюта заборонені.
- Конверт містить дату початку та завершення, валюту, джерело `marketing`, посилання на план і незмінний початковий ліміт.
- `available_minor = limit_minor - reserved_minor - committed_minor`. У F1 `committed_minor` залишається нулем, поки не з'явиться незалежне підтвердження рекламних витрат.
- Резервування застосовує транзакційне блокування `SELECT ... FOR UPDATE`, перевіряє ревізію конверта, період, валютну область та залишок. Повторне резервування того ж зовнішнього operation key відхиляється.
- Підтвердження рекламної витрати із власних повідомлень агента, JSON-форми або Meta reported spend не допускається.

## Стани та керовані переходи

```text
DRAFT --[Finance Action + independent Approval]--> ACTIVE
ACTIVE --[Finance Action + independent Approval]--> FROZEN
ACTIVE --[approved reserve]--> RESERVED reservation
RESERVED --[approved release]--> RELEASED reservation
FROZEN --[approved release]--> RELEASED reservation
```

Заморожений бюджет не допускає нового резервування, але дозволяє звільнення раніше зарезервованих коштів. Відновлення з FROZEN, збільшення ліміту, підтвердження витрати й автоматичне повернення коштів не входять у цей зріз.

Модуль декларує чотири Action types: `finance.budget.envelope.activate`, `finance.budget.reservation.reserve`, `finance.budget.reservation.release` та `finance.budget.envelope.freeze`. Для кожного діє `PolicyDecision::ApprovalRequired` і canonical Kernel Action/Policy/Approval runtime. Окремого executor немає.

Чернетка й читання конвертів доступні виключно менеджеру tenant через три типізовані Gateway-контракти: `finance.budget.envelope.draft.create`, `finance.budget.envelope.get`, `finance.budget.reservation.list`. AI/CRON не можуть викликати прямі фінансові зміни.

## Структура й докази

База бюджетів: `cos_finance_budget_envelopes`, `cos_finance_budget_reservations`, `cos_finance_budget_intents`, `cos_finance_budget_events`.

Кожна мутація атомарно записує стан, idempotency receipt і immutable event. Операції відхиляють чужий tenant, застарілу ревізію, повторювані provider operation keys, прострочений бюджет, overspend і неузгоджені переходи.

Перевірки: `tests/unit/finance_budget_f1.php` і окрема MySQL 8.4 перевірка `tests/integration/finance_budget_f1_mysql.php` у Runtime CI. **Це кодові та інтеграційні перевірки, не дозвіл запускати рекламу.**

## Спостереження рекламних витрат за даними джерела (F1.1)

Finance приймає й зберігає **окремий журнал спостережень**, не `Expense`. Мета-звіт `reported_spend_decimal` залишається рядком з точним десятковим значенням (до шести знаків), валютою, часовим поясом, конкретним періодом, `grain=campaign`, attribution hash, ID провайдерного об'єкта і посиланням на незмінну версію Marketing snapshot.

Таблиці: `cos_finance_spend_observations`, `cos_finance_spend_evidence_events`. Унікальний ключ обмежує один запис на конкретні account/campaign/window/attribution. Новий `source_revision` допускається тільки строго вище попереднього; повтор версії ідентичний, конфлікт версії відхиляється. Журнал подій не видаляється.

Для менеджера tenant додано query `finance.ad_spend.reconciliation.status` із суворим групуванням за ad account, валютою, attribution hash, timezone та **точним** вікном. Вона повертає `NO_OBSERVATIONS` або `UNRECONCILED`, кількість рядків і `source_reported_spend_decimal` без PHP float. Навіть `FINALIZED_BY_SOURCE` не є `Invoice`, `Payment`, `Expense` чи розрахунком прибутку.

**Обмеження:** `FinanceSpendObservationService::acceptUnverifiedMarketingSnapshot` залишається внутрішнім Finance persistence-портом. У цьому Draft інтеграційному зрізі реалізовано окремий `FinanceMarketingSpendReadbackService`, який отримує доказ безпосередньо через tenant-scoped Marketing readback та Symfony adapter. Це реальний D2 міждоменний тест, але **не активований продукт**: публічного Capability/HTTP/AI/CRON імпорту немає до canonical Workflow acceptance. Усі дані залишаються `UNVERIFIED_SOURCE`; reserved/committed balances незмінні, а invoice/expense/budget exposure coverage не підтверджені.

Автоматичний MySQL-тест: `tests/integration/finance_spend_observations_mysql.php` перевіряє незмінність попередніх версій, дублікати, tenant boundary, різні валюти/атрибуцію/вікна й відсутність фіктивних фінансових фактів.

## Приватний Marketing → Finance readback (F1.2, D2)

Тепер є приватний Finance-owned контракт `MarketingSpendEvidenceReadPort`, який дозволяє Finance **самостійно** запитати поточний запис `marketing.paid.spend.evidence.get` без приймання довільної суми, hash або `organization_id` від зовнішнього викликача. Інтеграційний адаптер `App\\Infrastructure\\Finance\\MarketingSpendEvidenceReadAdapter` делегує tenant-scoped lookup власнику Marketing; Finance не читає таблиць Marketing безпосередньо.

Сервіс `FinanceMarketingSpendReadbackService` перевіряє manager і tenant permissions, точні ID `mads-`/`mis-`, форму джерела й прив'язку account/snapshot, після чого зберігає лише Finance observation з provenance `UNVERIFIED_SOURCE`. Повтор з тієї ж source revision повертає існуючий результат, revision correction оновлює доказ та додає event без дублювання spend. Витрату `Expense` і фінансові `committed_minor` це **не** змінює.

`tests/integration/finance_marketing_spend_readback_mysql.php` перевіряє обидва домени на одній MySQL: справжню взаємодію Market read model → Finance ledger, replay, корекції, cross-tenant, чужий account, незалежний status і відсутність provider network write. Модулі лишаються вимкненими за замовчуванням, а API/agent/CRON ingress не реєструвався. Потрібні окремий canonical command/action із джерельною ідемпотентністю, D3 staging та незалежне приймання #435/#436/#440 перед експлуатацією.

## Відкладені залежності для Marketing

1. Окремо прийняти Finance F1 у staging, включно з правами, багатокористувацькими race-сценаріями та довготривалою перевіркою інваріантів.
2. Після об'єднання Finance та Marketing інтегрувати typed Finance read/hold контракт між доменами, оголосити реальне `cross_domain_contracts` і виконати аудит залежностей.
3. Перед будь-яким Meta write підтвердити незалежне погодження, канонічний Workflow, reserve-before-write, idempotent provider operation та readback.
4. Тільки після достовірного provider receipt додавати settlement і Finance Expense. Повідомлення AI не є фінансовим доказом.

- [Модулі та capabilities](../../12-reference/module-capabilities.md)
- [Покриття доменів процесами](../../12-reference/domain-process-coverage.md)

