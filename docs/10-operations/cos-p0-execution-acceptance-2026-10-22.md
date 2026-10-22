---
title: Перевірка P0 виконання та безпечного відновлення
description: Порядок технічного приймання, відновлення після збоїв та операторських перевірок COS.
status: active
updated: 2026-10-22
kind: operations
---

# P0. Контроль виконання, відновлення та підготовка до production (2026-10-22)

## Межа відповідальності

Цей пакет закриває технічний ризик повторного запуску generic Kernel Agent/Tool через старі черги. Він **не** приймає Golden 50, результати бізнес-Goals, production tenant cutover і незалежний operator sign-off. Не можна видавати успішний unit/MySQL smoke за production acceptance.

## Єдине джерело технічного стану

Нові технічні Agent/Tool Run і Step змінюються тільки через `DoctrineWorkflowExecutionStore` та tenant-scoped canonical claims. Native `cos_agent_runs`, `cos_audit_log`, `cos_jobs`, `cos_actions` залишаються бізнесовими / черговими джерелами **незалежного доказу**, а не другим writer `cos_workflow_runs/steps`. Для старої Federation та Engineering діють задокументовані винятки й контрольований cutover.

Залишкові дублікати ownership **не можна** вважати усунутими без production fleet tracing, порівняння history/shadow і підтвердження всіх consumers. Запуск `php bin/cos-execution-authority-report.php` дає статичний аудит; `php symfony/bin/console cos:workflow:authority-inventory TENANT` дає read-only зріз по трьох runtime контурах, свідомо без release authorization.

## Failover і безпечність повторних спроб

- `MysqlJobQueue::recoverTimedOut()` переводить прострочені canonical Agent і `ACTION_EXECUTION` для `kernel.tool.invoke` у `DEAD`, а не у повторну чергу.
- `MysqlJobQueue::failWithRetryPolicy()`, `replayDead()` та `claimSql()` не дозволяють повторно брати canonical-owned виконання. Перевірка враховує як поточний tenant rollout, так і вже збережений canonical Run: вимкнення rollout не відкриває старий claim.
- `MysqlActionRepository::requeueStale()` не розблоковує `kernel.tool.invoke`; `ActionExecutionJobHandler` не повторює Action із невідомим результатом.
- Before-LLM admission звіряє незалежну живу queue lease, worker identity, input та Step token. Прострочений Job і прострочений Step відхиляються.
- Звичайні, не enrolled legacy jobs зберігають попередню retry/timeout семантику. Recovery невідомих external effects лишається ручним через незалежні receipts і два менеджери.

Принцип: неуспішний зовнішній виклик **не дорівнює** доведеній відсутності зовнішнього ефекту. `DEAD` означає операторський розбір, а не безпечне повторення.

## Незалежне підтвердження

CI `Federation MySQL` виконує:

- `tests/integration/workflow_kernel_canonical_mysql.php`: claims, native receipts, tenant isolation, CAS, READ/WRITE Tool, forged receipt, recovery;
- `tests/integration/workflow_kernel_queue_failover_mysql.php`: реальна MySQL queue, timeout, failWithRetryPolicy, operator dead replay, відключений rollout із уже створеним canonical Run, no stale Tool Action replay та legacy parity;
- `tests/architecture/workflow_kernel_queue_failover.php`: статичний запобіжник проти повернення старих retry шляхів.

CI PASS є технічною умовою продовження, **не операторським підписом**.

## Production acceptance: що перевіряє оператор

1. Ізольований staging із реальними схемами MySQL, worker і tenant; перевірка migrations, permissions, feature rollout `off` за замовчуванням.
2. Tenant-scoped `cos:workflow:authority-inventory TENANT`, `cos:workflow:kernel-recovery TENANT`; відсутність невідомих writers, orphaned/expired claims, змішаного dispatch і неповного inventory.
3. Fault injection: worker crash до й після remote effect, повторна доставка queue, мережевий timeout, недійсний/прострочений receipt, concurrent workers, від'єднання DB, rollout toggle.
4. Rollback через **відключення нових admissions**, а не повторний запуск існуючих claims. Збережені Run/Step, receipts і незавершені external effects не переписувати.
5. Окремий підпис незалежного оператора після збереження доказів, плюс Golden 50 із реальними результатами Growth, Sales, Documents та незалежним Goal Outcome.

Поки немає результату пунктів 2–5 на цільовому tenant, статус `PRODUCTION_ACCEPTED` заборонений.

## P1 після прийняття P0

Перехідні Federation/Legacy executors прибираються лише після підтвердженого паритету та нульового ризику replay; наступні Domains працюють через той самий Platform executor без нового writer; Result / Process / Expert будуються зі спільних canonical projections.
