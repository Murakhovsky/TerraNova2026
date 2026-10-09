---
title: "Довірені докази бізнес-результатів COS Federation"
description: "Джерела фактів від доменів, межа довіри для оцінювання Goal та перевірка доказів."
status: active
updated: 2026-10-08
kind: architecture
---

# Довірені результати бізнес-цілей

Завершення Workflow або зовнішнього Action не означає, що Goal досягнуто.
Окрема оцінка за критеріями бізнес-цілі виконується тільки для вже
фіналізованого Run зі схваленим Plan тієї самої версії Goal.

## Межа довіри

Метод `FederationGoalStore::recordEvaluation` більше не приймає
довільних чисел або evidence від користувача. Його аргументи:
TenantContext, ідентифікатор нової оцінки та ідентифікатор Run.

Публічна операція `POST /api/v1/federation/runs/{runId}/evaluate`
вимагає менеджера та CSRF, самостійно створює ідентифікатор оцінки,
і не дозволяє передати значення показників або доказів через запит.
Результат фіксується в `cos_federation_evaluations` з Run,
версією специфікації, джерелами та часовими межами спостережень.

## Перший довірений адаптер: Sales

Відповідальний Sales Domain використовує власний read model над
журналом `cos_events` для двох критеріїв:

- `sales.won_deals` — кількість унікальних виграних угод;
- `sales.closed_deals` — кількість унікальних закритих угод.

Інтервал: від `Run.created_at` до моменту оцінювання, у UTC.
Tenant береться лише з авторизованого контексту. Якщо Sales Domain
неактивний, метрика не з'являється як нуль, а стає `unverifiable`.
Завершений Action із типом `sales.create_task` не може сам
підтвердити `tasks_created`: для цього ще потрібне окреме джерело
стану завдань CRM.

Кожне спостереження має domain source, часові межі та відбиток
агрегованого запиту, сформовані сервером. Відбиток не є окремою
цифровою сигнатурою або копією первинного запису, а лише засобом
аудиту запиту. Безперервне підтвердження узгодженості з журналом
Sales та snapshot/versioning джерел — наступні розширення.

## Підключення наступних Domains

`GoalOutcomeEvidenceProviderInterface` визначає мінімальний
контракт: власник Domain, підтримувані criterion IDs та
`observe(tenant, criterion, from, to)`. Нові адаптери явно
реєструються у `FederationTrustedOutcomeEvidenceResolver`.
Дублікати власника показника або некоректне джерело відхиляються.
Це read-only механізм, не новий Action executor.

## Відображення в Workspace

Сторінка `/workspace/goals` читає тільки найновішу збережену довірену
оцінку завершеного Run в активному tenant. Записи зі старою схемою без
`evidence_policy=domain_read_model_v1` не відображаються як досягнення Goal.
Факти наново не обчислюються під час GET: це окрема POST-операція
`/workspace/goals/runs/{runId}/evaluate` з CSRF та правами менеджера.

- **Результат:** статус досягнення цілі, дата оцінювання та кількість перевірених критеріїв.
- **Процес:** числові значення критеріїв, очікуваний результат, власник джерела та стан перевірки.
- **Експерт:** ідентифікатор оцінки, політика, часові межі й технічні references evidence.

Статус `unverifiable` видимий у всіх режимах. Зміна режиму не змінює
права чи результати бізнес-процесів. Для створення Goal наведено два
підтримувані коди Sales; довільні коди залишаються допустимими для
майбутніх Domain providers, але не відображаються як підтверджені.

Наразі `sales.create_task` через AIDA CRM записує активність у CRM,
не гарантуючи для кожного способу створення завдання синхронну
канонічну подію `sales.task.created`. Тому `tasks_created` **не**
рахується за Action receipts або неповним потоком подій. Для підтримки
цього критерію потрібен власний tenant-scoped CRM task read model.

## Довірений локальний облік CRM tasks

Критерій `sales.local_tasks_created` рахує **реальні task activities** у
`tn_client_case_activities` за `organization_id`, `activity_type=task` та
часом створення від початку Run до моменту перевірки (включно з нижньою
та виключно з верхньою межею). Кожен рядок дорівнює одному створеному
локальному CRM-завданню. Не використовує кількість успішних Action,
кількість спроб або повідомлень агента.

Джерело `sales.tn_client_case_activities.task.v1` ідентифікує власне
сховище COS/AIDA, а не всі завдання у сторонніх CRM. Показник
`tasks_created` зі старих специфікацій **не** змінює семантику та
залишається неперевіреним. Для зовнішніх CRM необхідні окремі
власні read models / синхронізовані локальні projections та явні
criterion IDs. Показник не підтверджує виконання завдання,
а тільки створення запису про нього.

## Час і точність: UTC → Europe/Kyiv

Єдине джерело істини для збереження й порівняння: **UTC**.
Відображення часу оператору: **Europe/Kyiv** (зимове UTC+02:00,
літнє UTC+03:00, без примусового зміщення на 2 години).
Окремо виводиться UTC offset, щоб дві однакові локальні години
під час осіннього переходу залишалися однозначними.

- `cos_federation_runs.created_at` та
  `cos_federation_evaluations.evaluated_at` — UTC `DATETIME(6)`.
  `FederationGoalStore::now()` тепер створює справжні мікросекунди
  через `DateTimeImmutable`, замість некоректного `gmdate(...u)`.
- `tn_client_case_activities.created_at` — MySQL `TIMESTAMP(6)`
  після `20261009_000136_federation_sales_task_timestamp_precision.sql`.
  Старі події з точністю до секунди набувають `.000000` без зміни
  фактичного моменту. Нові події мають мікросекунди.
- SQL-доступ до task activity використовує `FROM_UNIXTIME(epoch)`:
  це приводить UTC-вікно Federation до timezone тієї самої
  MySQL-сесії, яка інтерпретує `TIMESTAMP`. Немає
  примусового `SET time_zone`, що могло б зламати сторонні Domains.
- `FederationKyivTime` конвертує UTC у локальний час **тільки в UI**;
  доменна оцінка та audit evidence зберігаються незмінними в UTC.
- `due_at` і `completed_at` залишаються `DATETIME`,
  бо історичні значення не несуть інформації про часовий пояс.
  Масове додавання +02:00 або зміна семантики цих полів без
  provenance зіпсувала б існуючі призначені терміни.

**Реліз:** перевірити timezone MySQL-сесії та snapshot схеми на staging,
зробити резервну копію таблиці, запустити міграцію поза піковими
запитами, порівняти старі й нові UTC epoch існуючих timestamp-ів
та протестувати DST fallback. DDL на живій великій таблиці може
мати блокування/перебудову; міграцію не запускаємо автоматично
на production з цього PR.

## Другий набір доказів: Growth / Capital Markets Research / Documents

Усі метрики рахуються тільки з першоджерел відповідного bounded context,
у межах tenant і UTC-вікна від Run creation до його оцінки.
**Це облік за інтервалом, не доказ причинності конкретного Run.**

| Критерій | Авторитетне джерело | Правило |
| --- | --- | --- |
| `growth.inbound_responses_recorded` | `tn_growth_engagement_responses` | Кількість дедуплікованих вхідних відповідей, створених у вікні (час запису `created_at`) |
| `capital_markets.research_results_validated` | `tn_capital_market_research_results` | Кількість записів `status=VALIDATED` з `created_at` у вікні |
| `documents.signatures_recorded` | `cos_document_signatures` | `status=signed`, `signed_at` у вікні, непорожні `signed_by` та `signature_reference` |

**Важливо:** Research належить `capital_markets` та перевіряється через
його tenant-module gate. Growth перевіряється через власний gate.
Documents є постійною Platform capability `platform.documents`,
не окремим module ID, і явно зареєстрована як дозволений provider у
Federation. Її записи завжди обмежені tenant. Підпис у Documents означає
`recorded signed state`, а не зовнішню перевірку юридичної чинності.

Кожен результат зберігає source, часовий інтервал та агрегатний fingerprint.
Fingerprint підтверджує відтворюваність запиту, **не цифровий доказ**
окремої відповіді, експерименту чи підпису. Невідомі показники або
вимкнені Domains залишаються `unverifiable`.

## Зв’язування результатів Growth із підтвердженими діями Run

Новий критерій `growth.run_linked_inbound_responses` не дорівнює загальному
`growth.inbound_responses_recorded`. Потрібен завершений tenant-owned Run.
Для кожного завершеного зовнішнього Step серверний
`FederatedActionAdmission::assertCompletedReceipt()` незалежно перевіряє
канонічні Action / Policy / Approval receipts без повторного виконання.
Пряме читання та атестація не створюють циклу залежностей із GoalStore.
Growth read model рахує лише дедупліковані `tn_growth_engagement_responses`,
чиї `action_id` збігаються з перевіреними Action поточного Run,
а `created_at` належить UTC-вікну оцінки.

Evidence містить `attribution=run_linked_action`, `run_id`, кількість
підтверджених Actions та hash запиту з переліком Action IDs.
Без Run-контексту показник залишається `unverifiable`. Невалідний
Action receipt не збільшує результат.

Інші критерії залишаються `attribution=temporal_only`.
**Зв'язок у COS не є доказом контрфактичної економічної причинності.**
Research і Documents поки не мають аналогічного надійного FK на
перевірену Federation Action у власних business outcomes.

## Перевірений зв'язок Research і Documents з Action: підготовлений реєстр

Поточні записи Research та Documents не містять Federation Action ID. Їхні
наявні критерії `capital_markets.research_results_validated` та
`documents.signatures_recorded` і надалі мають `attribution=temporal_only`.

Додано непублічний `FederationOutcomeOriginRecorder` і таблицю
`cos_federation_outcome_origins` (міграція `20261009_000137`). В одному
транзакційному контексті з нативним бізнес-записом recorder перевіряє:

- Активну Action у стані `RUNNING`, що існує у канонічній таблиці;
- Worker-time Policy, незалежне Approval і незмінний затверджений Plan;
- Точний тип Action та ціль (`research_result` або `document_signature`);
- Прив'язку до claimed Step конкретного Run та часову послідовність;
- Збережений результат Research `VALIDATED` або реальний запис підпису
  Documents `signed` з непорожніми виконавцем і посиланням.

Унікальні ключі не дозволяють приписати один native Outcome декільком
Actions або Runs. `FederationOutcomeOriginReader` повторно перевіряє
поточний native record fingerprint і канонічну завершену Action
з однією успішною спробою. Невірні, підмінені або відкликані записи
не повертаються серед verified links. Пряме внесення рядка в журнал
не є підтвердженням походження.

**Обмеження цього етапу:** реальні Action handlers типів
`capital_markets.research.result.record` та `documents.signature.sign`
ще не реалізовані. Не підмінюємо їх іншими Actions та не вмикаємо
Run-linked метрики для Research/Documents до наявності робочого writer
у доменній транзакції. Ніякого retroactive backfill за correlation IDs.

### Активація Run-linked метрик без хибних результатів

`capital_markets.run_linked_validated_results` та
`documents.run_linked_signatures` реалізовано на рівні
`FederationNativeRunLinkedOutcomeEvidenceProvider`, але **за замовчуванням**
обидва джерела залишаються недоступними (`unverifiable`).

Умови активації:

- Створено й протестовано реальні канонічні Action handlers для відповідних
  Domain capabilities, з подачею recorder під час бізнес-транзакції.
- `FederationCapabilityBindingResolver` підтвердив наявність живого
  executable binding, дозволи та активність Domain.
- Проведено інтеграційні тести позитивного сценарію і відкликання квитанцій.
- Лише після цього оператор явно вмикає
  `COS_FEDERATION_RESEARCH_ORIGIN_WRITER_READY=1` або
  `COS_FEDERATION_DOCUMENTS_ORIGIN_WRITER_READY=1`.

Відсутність writer або binding не вважається нульовою кількістю
результатів. Resolver перехоплює тільки вузький
`FederationOutcomeSourceNotReady`, залишаючи метрику `unverifiable`;
усі інші помилки джерела й цілісності не маскуються.

### Додатковий захист від приписування старих результатів

Реєстратор вимагає persisted Action у статусі `RUNNING` і
одну активну спробу worker із `attempt=1`, `status=RUNNING`.
Native outcome timestamp має бути не ранішим за початок Run
та першої worker attempt; для Research час має бути UTC з
мікросекундами. Старі записи з округленням до секунди не
приписуються заднім числом.

Research перевіряє `status=VALIDATED` одночасно в рідному
стовпці та `record_json`, а також відповідність `result_id`.
Documents вимагає `signed_by_actor_id`, `signed_at`,
`signed_by` та непорожній `signature_reference`.

**Fail-closed читання:** якщо в журналі існує link, але квитанція
Action відкликана, native запис змінився або зв'язок підроблений,
`FederationOutcomeOriginReader` викидає помилку цілісності замість
повернення нульового результату. Це особливо важливо для цілей
з умовою `at_most`, де помилковий нуль міг би означати «успіх».

### Канонічний Research writer

`capital_markets.research.result.record` зареєстрований у `capital_markets` як реальний Action handler та typed executable capability. Він відмовляє без завершеного Research experiment, структурованих метрик та `review_evidence` із рішенням `VALIDATED`, особою і референсом перевірки. Запис результату й походження відбуваються атомарно. `VALIDATED` означає записане рішення Research Lab, **не гарантію прибутковості** і не незалежну економічну причинність. Власне схвалення Action повинно залишатися незалежним від автора Goal. Для rollout потрібні runtime smoke та QA фактичного Action-шляху.
