# Wave 12.21 — Audit / History

Цей етап закриває канонічний audit/history contract COS без створення другого журналу подій. Durable store залишається `cos_audit_log`; Agent trace залишається окремою деталізованою трасою виконання.

## Канонічна модель

Кожна activity має чотири незалежні осі:

- **Actor** — хто ініціював або виконав дію.
- **Source** — через який runtime-контекст виникла activity.
- **Correlation** — до якого наскрізного execution chain вона належить.
- **Resource** — над якою сутністю виконувалася дія.

Source має фіксований словник:

`HUMAN | AGENT | TOOL | WORKFLOW | INTEGRATION | WORKER | SYSTEM`.

Actor додатково нормалізується до `human | agent | system`. Це не те саме, що source. Наприклад, користувач може бути actor=`human`, а source=`TOOL`, якщо він вручну запустив tool.

## Correlation

`correlation_id` залишається обов'язковим durable атрибутом. Він проходить через Platform ActivityRecord → Kernel AuditEntry → `cos_audit_log` і використовується для відновлення наскрізної історії одного процесу.

Agent trace може бути прив'язаний до того самого correlation id, але не замінює загальний activity history.

## History read contract

`ActivityHistoryRepositoryInterface` підтримує:

- останні activity організації;
- історію конкретного resource;
- історію конкретного correlation id.

Кожен метод вимагає `OrganizationId`. Немає API, яке читає history без tenant scope.

Ліміт жорстко обмежується максимумом 250 записів, щоб UI або агент не могли випадково перетворити audit store на бездонний SELECT.

## Persistence

Wave 12.21 додає до `cos_audit_log` індексоване поле `source_type`. Історичні записи backfill-яться з `actor_type` там, де source ще не існував.

Новий audit store не створюється. `MysqlAuditRepository` продовжує бути єдиним append path для Kernel audit, а `MysqlActivityHistoryRepository` є read-side projection над тією самою таблицею.

## Agent / Human differentiation

`ActorKind` робить відмінність human vs agent явною на рівні Platform model. `KernelAuditSink` записує `actor_kind` та `source` у metadata, а `MysqlAuditRepository` дублює source у queryable `source_type`.

Це дозволяє UI, Activity Center та майбутнім governance/policy механізмам показувати не просто «щось змінилося», а **хто**, **через що**, **в якому execution chain** і **над яким resource** це зробив.
