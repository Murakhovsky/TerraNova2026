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

Finance `0.2.2` додає ізольований бюджетний контур **F1 Budget Control** поверх базового Finance V1. Це **не повноцінна бухгалтерія**, не модуль платежів і не підтверджена інтеграція з банками або рекламними провайдерами.

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

**Обмеження:** `FinanceSpendObservationService::acceptUnverifiedMarketingSnapshot` є лише внутрішнім контрактом для майбутнього довіреного bridge. Запису через HTTP, загальнодоступну Capability або Hermes немає. До приймання Marketing M1 та Finance F1 не існує перевіреного cross-domain джерела для автоматичної передачі. Усі записи позначаються `UNVERIFIED_SOURCE`; reserved/committed balances не змінюються. Реконсиляція з рахунками та бюджетний exposure coverage також не підтверджені.

Автоматичний MySQL-тест: `tests/integration/finance_spend_observations_mysql.php` перевіряє незмінність попередніх версій, дублікати, tenant boundary, різні валюти/атрибуцію/вікна й відсутність фіктивних фінансових фактів.

## F1.4. Блокування фінансового покриття зовнішньої операції

Finance-owned `cos_finance_paid_exposure_claims` фіксує одноразовий execution claim за reservation, Action і immutable Marketing plan. Claim та release використовують однаковий порядок locks (envelope, reservation), перевіряють active envelope, точну суму/валюту/період/operation key і revision; replay повертає fresh=false, новий Action не може повторно використати hold. Сума лишається RESERVED. Звичайний release після claim заблокований до незалежного settlement; freeze не знімає exposure і не гарантує зупинки вже допущеного provider request. `ApprovedFinancePaidExposureService` вимагає persisted canonical approved Marketing activation Action; немає direct Capability або HTTP write. Реальний Meta activation handler ще не підключений. Тести MySQL використовують дві незалежні connections; fixture claim не є live provider acceptance.

## Відкладені залежності для Marketing

1. Окремо прийняти Finance F1 у staging, включно з правами, багатокористувацькими race-сценаріями та довготривалою перевіркою інваріантів.
2. Після об'єднання Finance та Marketing інтегрувати typed Finance read/hold контракт між доменами, оголосити реальне `cross_domain_contracts` і виконати аудит залежностей.
3. Перед будь-яким Meta write підтвердити незалежне погодження, канонічний Workflow, reserve-before-write, idempotent provider operation та readback.
4. Тільки після достовірного provider receipt додавати settlement і Finance Expense. Повідомлення AI не є фінансовим доказом.

- [Модулі та capabilities](../../12-reference/module-capabilities.md)
- [Покриття доменів процесами](../../12-reference/domain-process-coverage.md)


## F1.2. Міждоменне читання джерельних рекламних даних

Finance читає Marketing-owned snapshot лише через `MarketingSpendEvidenceReadPort` та окремий integration adapter. Внутрішній `FinanceMarketingSpendReadbackService` перевіряє роль менеджера, tenant, account, snapshot та source revision і зберігає свідчення у власному Finance ledger.

Сервіс не експонований як публічний Capability або HTTP endpoint. `SOURCE_OBSERVATION_UNRECONCILED` не є Finance Expense, оплатою, settlement або бюджетним резервом. Публічний запис вимагає незалежного Approval через canonical Workflow/Action у F1.3. Відповідні відмови та replay перевіряються MySQL тестом.


## M3 — підготовка фінансового врегулювання

Finance готує детермінований кандидат врегулювання з нативного резерву, підтвердження завершення кампанії, точної ревізії спостереження та незалежно перевіреного фінансового документа Meta. Звіряються кампанія, валюта, період UTC, сума та хеш плану. Розбіжності, округлення та перевищення резерву блокують операцію.

`FinancePaidSettlementPolicy` обчислює суми фіксації та звільнення резерву, але повертає `MATCHED_NOT_SETTLED`. До підключення джерела фінансових документів, незалежного погодження, канонічного виконання та атомарного журналу врегулювання резерв залишається заблокованим.

Приватний журнал `cos_finance_paid_settlements` атомарно переводить резерв у стан `SETTLED`, фіксує погоджену суму в бюджеті, повертає невикористану частину та зберігає незмінний доказ і дві події. Повтор того самого Action повертає його квитанцію, інший Action не може повторити врегулювання. Виконання дозволяється лише через погоджений фінансовий Action та довірений порт доказів. Це бюджетна фіксація, а не банківська оплата чи бухгалтерська витрата.

Marketing надає приватне читання `settlementSource`: усі три команди та сім підоперацій мають підтверджені нативні квитанції, однакові ідентифікатори кампанії та точну ревізію джерела. Цей результат сам по собі не підтверджує фінансовий документ. Для публічного виконання ще потрібні адаптер незалежно перевірених документів, контроль актуальності доказу під час врегулювання та реєстрація фінансового контракту.

Погоджений фінансовий Action `finance.ad_spend.settlement.commit` приймає тільки ідентифікатори резерву, документа та джерела, хеш кандидата і ревізію бюджету. Незалежний менеджер перевіряє PDF у Documents, точну суму, ідентифікатор документа провайдера, кампанію та закритий період. Оригінальні байти PDF звіряються з SHA-256; зміна версії документа або джерела блокує попереднє погодження. Перевірка є людською, не автоматичною автентифікацією рахунку Meta. Фінансові факти повторно читаються під час транзакції з блокуванням джерела. Оплата і бухгалтерський облік залишаються поза цим контрактом.

Операторський сценарій: у Marketing Workspace прочитати термінальний рекламний план, завантажений PDF і source snapshot, зафіксувати незалежне рев'ю та запропонувати фінансовий Goal. Результат Goal перевіряється через нативний журнал Finance і канонічну Action receipt, а не прапорець успіху від агента.

У Capability Library також доступні `finance.ad_spend.settlement.status` (читання нативного стану або кандидата, без фінансової мутації) та `finance.ad_spend.settlement.review` (тільки людський CommandBus із явним підтвердженням перевірки, без дозволу на списання). Виконання `commit` завжди потребує окремого canonical Workflow і Approval.
