# Persistence architecture

Audit date: 2026-08-28.

## Boundary

`Infrastructure/Persistence` contains physical storage adapters only. Business rules and orchestration belong to `Domains`; HTTP, CLI and Telegram delivery belong to `Interfaces`; dependency assembly belongs to `Bootstrap`.

The write path is:

```text
Interface -> Domain application service -> Domain-owned port -> MySQL/Phalcon adapter
```

Query-heavy screens may use a dedicated read model:

```text
Interface -> Domain query contract -> Infrastructure/Persistence/MySql/ReadModel
```

## MySQL areas

| Directory | Responsibility | Files |
|---|---|---:|
| `MySql/Database` | Generic Kernel persistence: events/outbox, actions, approvals, policies, rules, agents, queue, configuration, migrations and transactions | 20 |
| `MySql/Sales` | Implementations of Sales-owned command, activity, deal, follow-up, message and context ports | 8 |
| `MySql/Property` | Property submission, moderation and management write adapters | 3 |
| `MySql/Content` | Content repository adapter | 1 |
| `MySql/Spatial` | Spatial scene repository adapter | 1 |
| `MySql/ReadModel` | Query-only projections for ClientCase, Operations, Admin, Analytics and Property Catalog | 5 |
| `MySql/Operations` | Kernel metrics recorder | 1 |

`DatabaseService` is the low-level PDO gateway. It is not exposed to Domains or new Interface code. Repositories accept it internally and implement contracts declared by the owning Domain or Kernel.

## Property split

- `PropertySubmissionService` and `PropertyModerationService` own application orchestration.
- `PropertyManagementService` is the application boundary for management commands and queries.
- `PropertyWorkflowPolicy` owns publication readiness, status-note requirements, stage transitions and quality rules without PDO or Phalcon.
- `PropertyMediaStorageInterface` and `PropertyNotificationInterface` prevent MySQL adapters from depending on concrete Media or Telegram implementations.
- `MysqlProperty*Repository` classes contain transactions, SQL mapping and persistence-specific normalization only.

## Phalcon ActiveRecord

ActiveRecord is retained only for the active legacy Telegram surface:

| Directory | Responsibility | Files |
|---|---|---:|
| `Phalcon/Identity/Telegram` | Telegram user, company, employee, contact, progress and message table mappings | 11 |
| `Phalcon/Telegram` | Estate, request, showing, preference and Realty table mappings used by 23 Longman command adapters | 17 |

There are no Property, Sales, Media, Economy or generic Identity ActiveRecord trees anymore. Duplicate Telegram/Identity mappings and dead dialogue/realty classes were removed. Non-model identity helpers live in `Infrastructure/Identity`, not under Persistence.

Longman commands remain framework adapters and may access this quarantined canonical ActiveRecord surface. New Web/API/CLI code must use Domain application contracts instead. A future Telegram rewrite can replace these mappings command-by-command without changing the rest of the architecture.

## Enforced rules

- Domains cannot import Infrastructure, Interfaces, Phalcon or PDO.
- MySQL adapters cannot import Interfaces or concrete Media/Integration services; they use Domain-owned ports.
- Phalcon ActiveRecord classes cannot exist outside `Infrastructure/Persistence/Phalcon`.
- Old top-level `Infrastructure/Database`, `Infrastructure/ReadModel` and `Infrastructure/Operations` roots cannot be restored.
- `tests/architecture/persistence_boundaries.php` enforces these constraints.
- `tests/integration/persistence_di.php` verifies that the composition root exposes Domain services backed by the canonical MySQL repositories.

Current inventory: 39 MySQL adapter files and 28 retained ActiveRecord files.
