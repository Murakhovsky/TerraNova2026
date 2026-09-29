---
title: Перехід вертикальними зрізами та сумісність
description: Канонічний підхід COS до тестування, legacy adapters, strangler migration, frontend compatibility та Symfony Console.
status: active
updated: 2026-09-18
kind: architecture
contract: architecture-v1
---

# Перехід вертикальними зрізами та сумісність

## 32. Тестування

COS уже має велику мережу unit, smoke, architecture та deployment contracts. Поточна міграція не вводить другий тестовий світ лише заради PHPUnit-етикетки. Кожен Symfony slice зобов'язаний мати:

- чистий Application/Domain contract test;
- architecture boundary test;
- Docker integration test для реального adapter wiring;
- deployment smoke для змін runtime.

## 33. Межа сумісності legacy

Legacy база не стає Domain API. Symfony composition root явно зв'язує legacy read-only PDO лише з Infrastructure adapters.

Новий HTTP або Console код не може бачити `PDO`, SQL або `legacy_cos.pdo`.

## 34. Перехід методом strangler

Перший бізнесовий Symfony slice після системних probes:

```text
GET /api/v1/sales/client-cases/stats
        ↓
SalesClientCaseStatsController
        ↓
QueryBus
        ↓
GetClientCaseStatsQueryHandler
        ↓
ClientCaseReadModelFactoryInterface
        ↓
MysqlClientCaseReadModel
        ↓
legacy MySQL (read-only)
```

Tenant береться із `TenantContext`, а не з query parameter або глобальної session-змінної.

## 35. Сумісність frontend

Існуючий frontend не переписується. Новий versioned endpoint додається паралельно; старий UI може переходити на нього окремим slice без одночасної зміни URL, payload і persistence.

## 36. Консольні команди

Symfony Console стає канонічною точкою для нових operational commands. `cos:sales:client-case-stats --organization=...` використовує той самий QueryBus і той самий Application handler, що й HTTP. CLI не отримує окремої бізнес-логіки.

Старі Phalcon CLI tasks залишаються compatibility entrypoints до моменту перенесення відповідних services у Symfony composition.

## 39. Завершення Symfony Foundation і Core freeze

Станом на 2026-09-18 архітектурна foundation-фаза Symfony migration вважається завершеною після злиття migration points 1–38 у `main`.

Подальша міграція виконується не через розширення абстрактного Core, а через вертикальні бізнес-сценарії. Одиницею міграції є завершений сценарій від transport/UI до Application/Domain, Infrastructure adapter, persistence/external system, Audit/Observability, tenant isolation та тестів.

Core freeze означає:

- не створювати нові Kernel/Platform abstraction або runtime layer без вимоги конкретного активного business slice;
- не переписувати стабільні framework-independent contracts лише заради симетрії, стилю або можливого майбутнього use case;
- дозволяти зміни Core, якщо вони потрібні для business cutover, security/correctness, production incident, measured performance/reliability problem або усунення підтвердженої архітектурної суперечності;
- не робити одночасний big-bang rewrite frontend, legacy schema та business logic;
- legacy paths прибираються тільки після того, як відповідний vertical slice переведений, протестований і більше не має production consumer.

Перший пріоритет Phase II — Sales business cutover:

```text
Sales read paths
    ↓
Sales write paths
    ↓
Sales automation
    ↓
Agent → Tool → Sales
    ↓
Diagnostics
    ↓
Integrations
    ↓
Frontend cutover
    ↓
Legacy Sales retirement
```

`migration/symfony` залишається тимчасовим integration stream до повного retirement старого runtime, але foundation-only work після цієї точки не є самостійною метою міграції.

## 40. Друга фаза, хвиля 1 — перенесення читання Sales

Перший business-cutover wave переносить Sales dashboard, Lead list/detail, Opportunity list/workspace та Pipelines у `/api/v1/sales/*` через QueryBus і чинний `SalesWorkspaceReadModelInterface`.

Wave не створює другу Sales persistence model і не переписує frontend. Legacy MySQL залишається read-only compatibility source за Infrastructure adapter boundary; tenant scope надходить тільки з `TenantContext`.

Канонічний опис: [Sales Read Cutover](./sales-read-cutover.md).

## 41. Друга фаза, хвиля 2 — перенесення запису Sales

Другий business-cutover wave переносить Create Lead, Update Lead, Lead → Opportunity, Activities, Pipeline transitions та Next Action у `/api/v1/sales/*` через CommandBus і чинні Sales Application services/use cases.

Symfony не дублює бізнес-правила. Tenant scope походить із `TenantContext`, mutations захищені CSRF, а retry-sensitive операції мають idempotency boundary. Legacy frontend і `/api/sales/*` залишаються compatibility path до окремого frontend cutover.

Канонічний опис: [Перенесення запису Sales](./sales-write-cutover.md).



## 42. Друга фаза, хвиля 3 — deterministic Sales automation

Третя business-cutover wave переносить Sales monitoring та deterministic automation у Symfony Scheduler + Messenger, але залишає Rule/Policy/Action business runtime канонічним і framework-independent.

Канонічний execution path:

```text
Scheduler
    ↓
Messenger
    ↓
Sales detector
    ↓
Domain Event + Outbox
    ↓
Rule
    ↓
Policy
    ↓
Action
    ↓
Messenger
    ↓
Sales use case
    ↓
Audit
```

Wave 3 закритий тільки після tenant-safe/idempotent detector execution, Sales-only Outbox strangler boundary і end-to-end Docker smoke.

## 43. Друга фаза, хвиля 4 — Agent → Tool → Sales

Четверта business-cutover wave прибирає Symfony Sales agent execution із legacy `cos_jobs / AgentRunJobHandler` compatibility path.

Канонічний Agent execution path:

```text
Sales Event
    ↓
Rule
    ↓
Symfony Messenger
    ↓
AgentRuntime
    ↓
structured Agent decision
    ↓
ToolRuntime
    ↓
sales.action.propose
    ↓
Policy
    ↓
Action
    ↓
Symfony Messenger
    ↓
Sales use case / CRM port
    ↓
Audit + Agent trace + Tool trace
```

Agent не отримує прямий write-доступ до CRM або Sales repositories. Write capability `sales.action.propose` є керованим Tool, який може лише передати структурований намір у канонічний Policy/Action lifecycle.

Для Symfony cutover заборонено повертати `agent.run.*` у legacy `AGENT_RUN` job. Legacy queue залишається compatibility runtime лише для ще не перенесених entrypoints.

CI використовує deterministic LLM adapter, але той самий Agent definition, output validation, ToolRuntime, Policy, Action handler та persistence contracts, що й production. Production default використовує HTTP structured LLM adapter через environment configuration.
