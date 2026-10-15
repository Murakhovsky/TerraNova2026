---
title: Міграція виконання Goals на Platform Workflow
description: Контрольований перехід нових федеративних Goals на єдині Run і Step без подвійного виконання Actions.
status: proposed
updated: 2026-10-15
kind: architecture
---

# Контрольований перехід Federation Goals на Platform Workflow

## Призначення

Цей інкремент переносить **нові, явно дозволені** Federation Goal Plans з власного технічного `FederationSequentialOrchestrator` на канонічне сховище `cos_workflow_runs/steps` через `WorkflowExecutionStoreInterface`. Він не переносить автоматично старі записи `cos_federation_runs/steps`, не запускає черги і не змінює Engineering state machine.

Збережено бізнес-власність `Goal`, `GoalSpecification`, `Plan` і `OutcomeEvaluation`. Новий `FederationCanonicalWorkflowBridge` є **тонким адаптером** процесу, а не другим engine: його SQL лише читає й блокує рядки для унеможливлення гонок, усі технічні мутації здійснює канонічний Workflow Store.

## Увімкнення і правила

Для нового маршруту потрібні всі умови: модуль `federation` активний у tenant; власник Goal автентифікований як manager; immutable Plan затверджений незалежною людиною; Action-провайдери опубліковані та активні; у процесі є тільки кроки `side_effect_level=external`, які виконуються через зареєстровані канонічні Action.

Створення нових canonical Run **вимкнено за замовчуванням**. Оператор інфраструктури може обрати рівно один тестовий tenant за допомогою `COS_FEDERATION_CANONICAL_ORGANIZATION=<organization_id>`. Це не HTTP-параметр і не UI-дозвіл. Прив'язка кожного створеного Run зберігається в `cos_workflow_runs`; вимкнення налаштування не повертає такий Run до legacy.

Перед увімкненням необхідно окремо підтвердити MySQL acceptance, повний Action worker flow, shadow parity, Approval/Policy та recovery. Налаштування не слід використовувати на production tenant без цих доказів.

## Гарантії від дублювання

1. На старті Plan рядок блокується `FOR UPDATE`; обидва шляхи перевіряють відсутність Run у сусідньому сховищі. Unique constraint `uq_workflow_plan_run` захищає canonical Plan. Під час створення відбувається **лише один** `WorkflowExecutionStore::create` і жодного `cos_federation_runs/steps` write.
2. `Advance` виконується під блокуванням **канонічного Run**. Конкурентні запити не створюють другий Step claim і не породжують другий Action.
3. Кожен Action має єдиний сталий ключ `wf:<sha256(tenant,run,step)>`. Після невизначеного результату система спершу шукає вже записаний Action за ключем і **не викликає повторного `submit`**.
4. Початковий Action одержує `PENDING_APPROVAL`. Step резервується в Workflow до реального human Approval. Worker повторно перевіряє approval, tenant, зв'язок із Plan, непорушний DAG, claim lease та попередні receipts.
5. Повторне виконання `COMPLETED`, `FAILED`, `REJECTED`, несумісних Action або Step із закінченим claim не допускається. Вони потребують ручної перевірки.
6. Підтвердження Step та Run виконується тільки після незалежної перевірки receipt першої успішної Action attempt. Бізнес-результат Goal оцінюється окремо через Domain-owned evidence providers.

## Межі реалізації

- Legacy-запуски продовжують існувати на старому шляху, доки не пройдуть backfill/fencing, і **не копіюються автоматично** у canonical. Це свідомий поетапний cutover.
- Шлях із `none`/не-Action steps не активовано. Він потребує власного safety contract для доказів.
- Реальні зовнішні наслідки не виконуються тестами архітектурної структури. MySQL перевірка admission є обов'язковою; потрібен наскрізний deployment acceptance.
- Step lease обмежено 24 годинами; прострочення вимагає розгляду оператором, а не повторного запуску. Автоматичного lease renewal немає.
- Канонічний Run, який потребує recovery невизначеного ефекту, не відновлюється через legacy Federation recovery API. Використовується штатне Workflow recovery з незалежними дозволами.

## Критерій наступного завершення

Міграція всіх Federation Goal Runs допускається тільки після перевірки активних legacy references, адитивного backfill з єдиним ID mapping, повної receipt-based parity за tenant, закриття невизначених Action, керованого rollback і перевірки Golden 50. Усі статуси виконання перевіряються по канонічних Run/Step, не по текстовій декларації Federation.

**Не вважати цей обмежений opt-in завершеною міграцією всієї COS.**
