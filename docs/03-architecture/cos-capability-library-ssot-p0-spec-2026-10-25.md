---
title: COS Capability Library — єдине джерело виконуваних контрактів
description: Нормативне P0 ТЗ канонічного каталогу, типізованого invocation, result/evidence, AI/CRON/Event admission та сумісності з Goal Runtime.
status: proposed
updated: 2026-10-25
kind: architecture
---

# COS Universal Capability Library: Single Source of Truth

**ID:** COS-CAPABILITY-CORE-2026-10  
**Версія ТЗ:** 1.0 (proposal)  
**Пріоритет:** P0 Reliable Core, наступні P1 extension packs  
**Власники:** Kernel/Platform (контрактне управління); кожен Domain (реалізація бізнес-можливості)  
**Статус:** нормативний проєкт до acceptance; жодна нова execution authority не створена цим документом.  
**Baseline:** `main` станом на 2026-10-25; див. `docs/03-architecture/cos-universal-capability-invocation.md`, `docs/03-architecture/cos-federated-architecture-implementation-spec.md`, `docs/03-architecture/cos-execution-contract-consolidation-p0.md`.

## 1. Проблема та результат

В COS існують різні джерела ініціювання роботи: UI, API, Goal/AI Planner, Jobs/CRON, Event/Rule, сторонні Agent/Tool. Бізнес-функції належать Domains і реалізуються через вже наявні QueryBus, CommandBus, Kernel Action/Policy/Approval, Platform Workflow, Tool/Agent runtime. Ціль цього ТЗ — **єдиний каталог контрактів доступних можливостей**, а не ще одна система виконання.

Кінцевий результат: будь-який дозволений ініціатор здатний (1) знайти доступні функції й їхню документацію; (2) сформувати типізований запит; (3) перевірити всі залежності, повноваження, політику, вхід; (4) отримати безпечний Route/Plan; (5) виконати дію через її існуючого власника; (6) перевірити output, durable technical receipt та окремий native business evidence; (7) зафіксувати trace/audit; (8) породити подію як факт, що може запропонувати новий процес із повторним admission.

**Non-goals P0:** не створювати Executor/CommandBus/ActionService/Workflow Store/EventBus/Registry DB замість наявних; не дозволяти довільний AI shell, PHP service або SQL; не переписувати всю бізнес-логіку Domains; не видавати AI/CRON необмежену делегацію; не проводити автоматичний legacy cutover; не вимагати міграції до мікросервісів.

## 2. Обґрунтований AS-IS (аудит коду)

| Зона | Існуюча реалізація | Висновок / прогалина |
| --- | --- | --- |
| Identity/metadata | `app/Kernel/Module/CapabilityContract.php`, `ModuleContributions.php`, доменні `module.php` | Domain-owned source є; декларативні `capabilities` і typed `capability_contracts` відрізняються; не всі декларації виконувані |
| Catalog | `CanonicalCapabilityCatalog` + `ModuleCapabilityRegistry` | Є перевірка єдиного owner; словник за ID містить одну версію; multi-version/pinned archive ще не універсальні |
| Binding | `FederationCapabilityBindingResolver`, `DomainModuleRegistry` | Реальна action-реєстрація та tenant activation перевіряються для federation; потрібна узгоджена багатотипова projection |
| Direct invoke | `CanonicalCapabilityGateway`, `CanonicalCapabilityController` | Підтримує bounded `human_ui/human_api` direct Command і Query, інші записи мають status `workflow_approval_required` / `unavailable`. Немає загального довільного machine side-effect |
| Input schema | `CapabilityJsonInputValidator` | Fail-closed локальна підмножина JSON Schema; 16 KiB input, depth 10; підтримуються лише відомі keywords; не стверджувати повну JSON Schema реалізацію |
| Output schema | `CapabilityContract.outputSchema` | Поле є, але обов'язковий output validator для всіх adapters/Action outputs не доведений |
| Goal Plan | `GoalPlanValidator` | Pin capability ID/version, DAG, input, allowed list; спирається на одну поточну версію і tenant-available список |
| Workflow | `FederationCanonicalWorkflowBridge` → `DoctrineWorkflowExecutionCoordinator` / `DoctrineWorkflowExecutionStore` | Канонічний technical Run/Step для allowlisted сценаріїв; universal consumer fleet acceptance не підтверджено |
| Events | `EventBus`, `DurableEventDispatcher`, `RuleEngineEventHandler`, Outbox | Є durable fanout та Rule engine; generic Goal event-to-intent admission ще не підтверджено |
| UI/API | `/workspace/capabilities`, `/api/v1/capabilities` і `/{id}/invoke` | Каталог є, але виглядає як перелік технічних рядків, недостатньо input/output demo, readiness та сертифікованих proof |
| Report | `bin/federation-capability-report.php` | Static report без DI/runtime bindings; `INVALID` у його звіті не доводить дефект handler — потрібен verified snapshot |
| Machine intent | `/workspace/capabilities/intents`, Property AI/CRON HMAC intake | Вузький безпечний Property pilot, не універсальний service identity / automation invoke |

### 2.1. Реєстр доменних typed внесків

Виявлені `capability_contracts` у `app/Domains/Property`, `Growth`, `Sales`, `Documents`, `CapitalMarkets`, `Federation`. Це не доказ повноти інших Domains чи кількості live handlers; P0 починається з машинного експорту **всіх** `module.php`, DI bindings, Action handlers, Query/Command adapters і route consumers на конкретному Git SHA.

### 2.2. Головний ризик

Присутність слова `capability` у маніфесті, успіх тесту декларації, наявність роуту або позитивний HTTP status **не є** доказом можливості, дозволу чи бізнес-результату. Ці факти належать різним рівням перевірки. Не зливати їх у один зелений `available`.

## 3. Архітектурні інваріанти (MUST)

**INV-01:** Ідентичністю функції й власником є Domain module manifest `module.php`; центральний Catalog лише read-only складена projection. Жодна tenant DB/UI/LLM не створює другу виконувану декларацію.  
**INV-02:** Рівно один owner Domain на публічний capability ID та один authoritative live handler/adapter на його executable binding у межах конкретного runtime scope.  
**INV-03:** Для кожної зовнішньої зміни використовувати **існуючі** Action/Policy/Approval/Queue та canonical Workflow технічного Run/Step. Library/Gateway сам не dispatch-ить другий Action.  
**INV-04:** Повноваження визначаються live server-side identity, tenant, permissions, active module, policy, approval, actor binding, step/plan snapshot, не полями браузерного/LLM payload.  
**INV-05:** Кожний виконуваний Plan Step pin-ить ID, version, contract digest, input digest і immutable approved Plan digest. Зміна каталогу не змінює погоджену інструкцію заднім числом.  
**INV-06:** Code deploy / retire старої версії не спричиняє непомітний remap чи replay. Якщо сумісний handler/verifier відсутній, Run переходить у WAITING/BLOCKED/RECONCILE, а не виконує нову реалізацію автоматично.  
**INV-07:** Output validation і native evidence verification — різні рівні; завершення Action/Run не дорівнює Goal achieved.  
**INV-08:** Event — збережений факт; Event consumer не надає собі authority, а породжує proposed intent або governed operation через existing admission.  
**INV-09:** Ретрай delivery може бути at-least-once; exactly-once side-effect не обіцяється без remote idempotency/receipt, unknown remote state = AMBIGUOUS і no blind replay.  
**INV-10:** Новий Domain інтегрується через manifest + adapters/handlers + contracts/tests без hardcoded `switch(domain)` у Kernel/Gateway.  
**INV-11:** Дані, аудити й секрети ізольовано за tenant; contract projection не розкриває secrets, credentials, поля іншого tenant.  
**INV-12:** Жоден legacy writer, internal plan-approval capability або низькорівнева system/admin команда не стає user-plannable через автоматичну індексацію.

## 4. Source of Truth: три чіткі шари

**A. Definition authority (у Git/code):** `module.php -> contributions.capabilities / contributions.capability_contracts -> CapabilityContract`, статичні JSON input/output schemas, tests/docs, semantic labels. Ідентичність, ownership, public/ internal scope, технічна версія, side effect, data classifications, declared dependency references. Редагується тільки через code review + CI.

**B. Deployment/runtime binding evidence (derived):** чи існує handler/adapter/Query/Command/Action/Tool/Agent read evidence handler на конкретному SHA, чи зареєстровано його в DI, версії, чи пройдені smoke/integration checks. Сюди можна додати **immutable audit snapshots** з `commit_sha`, `manifest_digest`, `schema_digest`, `binding_digest`. Вони не можуть стати writer definition.

**C. Tenant effective admission (computed on demand):** активація модулів, user/service identity, permissions, policy, approvals, rollout, data dependencies, risk controls, contracts pinned in Plan, worker live checks. Це не глобальне поле каталогу.

**Правило:** ні UI, ні AI не читають сирі `module.php` напряму для рішення про дозвіл. Вони отримують purpose-built catalog projection із мінімально необхідними public даними.

## 5. Контракт CapabilityContract vNext (розширювати без ламання)

**MUST preserve:** чинні `id`, `version` SemVer, `owner_domain`, `kind` (`query/command/job/workflow/agent_tool`), `input_schema`, `output_schema`, `execution_binding`, `async`, `side_effect_level` (`none/internal/external/financial/irreversible`), `permission`, `approval_policy` (`none/conditional/required`), `idempotency`, `timeout_policy`, `retry_policy`, `failure_contract`, `dependencies`, `lifecycle`, `tests`, `documentation`, `title_key`, `description_key`.

**P0 додаткові поля або окрема незмінна companion projection:**

| Поле | Тип | Правило |
| --- | --- | --- |
| `contract_digest` | SHA-256 canonical JSON | Поля + schema digests + binding reference + semantic version. Ідентичний на всіх інстансах того самого build |
| `schema_format` | pinned profile | `cos-json-schema-subset-v1`, **не** заявляти повний draft 2020-12 |
| `output_validation` | enum | `required` для нових public executables; legacy недоведені = `uncertified` |
| `visibility` | enum | `public_plannable / human_direct / internal / hidden`, найменші права за замовчуванням |
| `initiator_policy` | matrix reference | дозволені канали proposal і execution; це не пряма авторизація |
| `result_evidence_contract` | typed reference | який provider і які native proofs можуть підтвердити бізнес-факт |
| `data_classification` | enum + field-level rules | public/internal/confidential/restricted + PII redaction |
| `bounded_usage` | quota/config reference | timeout, payload limit, per tenant quotas, concurrency, fanout guard |
| `compatibility` | semantic profile | backward-compatibility policy / sunset / snapshot pin |
| `support` | contact/runbook link | owner team, runbook, failure handling |

**Приклад**, не literal live manifest:

~~~json
{
  "id": "property.asset.create",
  "version": "1.1.0",
  "owner_domain": "property",
  "kind": "command",
  "input_schema": "resources/contracts/property/asset-create-input.schema.json",
  "output_schema": "resources/contracts/property/asset-create-output.schema.json",
  "execution_binding": "command:property.asset.create",
  "side_effect_level": "internal",
  "approval_policy": "conditional",
  "permission": "cos.tenant.manage",
  "idempotency": "required",
  "schema_format": "cos-json-schema-subset-v1",
  "visibility": "human_direct",
  "output_validation": "required",
  "result_evidence_contract": "property.asset.native-read.v1",
  "contract_digest": "<server-compiled-sha256>"
}
~~~

**Увага:** приклад містить запропоновані vNext поля, не є готовим JSON для чинного `CapabilityContract::fromArray`.

## 6. Status та readiness: дві незалежні осі

**Definition readiness:** `DECLARED_ONLY`, `SCHEMA_VALID`, `BINDING_VERIFIED`, `TESTED`, `CERTIFIED`, `DEPRECATED`, `RETIRED`, `DRIFT`. Це внутрішня машина evidence governance; `TESTED` вимагає справжнього звіту тестів на SHA, не статичного рядка tests[].

**Tenant/execution decision (не збережений глобальний прапорець):** `DISCOVERABLE`, `READ_ONLY_INVOCABLE`, `DIRECT_HUMAN_INVOCABLE`, `PLAN_ONLY`, `APPROVAL_REQUIRED`, `BLOCKED`. Додаткові поля: `reason_code`, `next_action`, `evidence_ref`, `checked_at`, `definition_digest`. Стан `AVAILABLE` заборонений як єдина нечітка мітка.

**P0 reason codes:** `CAP_UNKNOWN`, `CAP_DECLARATIVE_ONLY`, `CAP_BINDING_MISSING`, `CAP_CONTRACT_DRIFT`, `CAP_SCHEMA_UNSUPPORTED`, `CAP_OUTPUT_UNVERIFIED`, `CAP_VERSION_UNAVAILABLE`, `TENANT_MODULE_DISABLED`, `IDENTITY_REQUIRED`, `TENANT_PERMISSION_DENIED`, `POLICY_DENIED`, `APPROVAL_REQUIRED`, `PLAN_STALE`, `INPUT_INVALID`, `DEPENDENCY_NOT_READY`, `RUN_AMBIGUOUS`, `NATIVE_EVIDENCE_MISSING`. API + UI використовують один словник із безпечним поясненням; внутрішні секрети не повертаються.

## 7. Версії та незмінні snapshots

- Поточний `CanonicalCapabilityCatalog::describe(id)` залишається legacy API «актуальна версія». Для нового планування додати `resolve(id, version, digest)` через compatible adapter, без перебудови існуючого Kernel.
- Catalog build-time зобов'язаний відхиляти дубль `id+version`, зміну payload при незміненій версії, дубль owner/binding та невідому schema. Кілька активних версій лише за наявності явного version-aware handler binding; у противному разі старий Plan безпечно блокується.
- Contract digest формувати детерміновано: canonical JSON with sorted keys, UTF-8, normalized schema bytes/hash, fixed algorithm version. Заборонені locale-dependent або non-deterministic serialized map.
- Plan зберігає `capability_id`, `version`, `contract_digest`, `input_digest`, `plan_digest`, `binding_revision`, verifier profile; worker порівнює pinned hash із дозволеною immutable snapshot. Нова schema не валідизує заднім числом старий вже погоджений input.
- Compatible ADD (optional fields без зміни поведінки): minor; breaking input/output/side effect/approval/owner changes: major або нова identity + migration map. У жодному разі не послаблювати approval в existing approved Plan через minor.
- `deprecated`: нові плани не створюються, старі виконуються лише через доведену pinned compatibility і live re-authorization; `retired` не виконується, існуючий Run потребує safe reconciliation. Розмежувати governance lifecycle і technical availability.
- Якщо output містить stable native external identifier, він має зберігати semantic identity across versions; зміну ID format оголошувати breaking.
- Міграцію старих Run/Plan робити через manifest inventory → read-only shadow → parity → operator accepted cutover. Ні silent replan, ні dual dispatch.

## 8. Єдиний каталог API (read/control, без executor)

**Підтримати чинне:** `GET /api/v1/capabilities`, `GET /api/v1/capabilities/{id}`, `POST /api/v1/capabilities/{id}/invoke` для already-authorized limited human direct paths.

**P0 additive API:**

| Method/path | Призначення | Безпека |
| --- | --- | --- |
| `GET /api/v1/capabilities?domain=&kind=&search=&cursor=` | paginated authorized catalog, status/reason | tenant-scoped, no sensitive fields |
| `GET /api/v1/capabilities/{id}/versions/{version}` | pinned safe contract projection | version+digest |
| `POST /api/v1/capabilities/{id}/validate` | dry-run input/schema/dependencies, **no side effect** | quota; no authorization implied |
| `POST /api/v1/capability-plans/validate` | DAG/pin/tenant/preflight | immutable request, safe result |
| `GET /api/v1/capabilities/{id}/readiness` | live bounded existence/binding/module/permission | reason codes, no auth token |
| `GET /api/v1/capability-executions/{runId}` | canonical Run/Step/receipt projections | owner/tenant check |
| `GET /api/v1/capability-contracts/drift` | admin governance drift report | restricted admin / operator |
| `GET /api/v1/capability-contracts/build` | deploy SHA and digest attestation | restricted admin / operator |

Структури Route і controller мають пере-використовувати наявний Gateway/Catalog, а не створювати іншу `CapabilityApiService` як parallel dispatcher. API відповіді versioned, stable errors, cursor pagination, size limits, ETag for immutable definition projections, no secret binding details for unauthorized roles. Preview/validate не викликає Action, не бере externally mutating claim і не робить Domain mutation.

## 9. Invocation envelope і шляхи

Планувальник/ініціатор формує **intent proposal**, а не правдиві поля actor/approval. Сервер заповнює trusted envelope:

~~~json
{
  "request_id": "uuid",
  "correlation_id": "uuid",
  "causation_id": "uuid|null",
  "initiator_kind": "human_ui|human_api|ai|cron|event|service",
  "trusted_identity_ref": "server-owned",
  "organization_id": "server-owned",
  "capability_id": "property.asset.create",
  "capability_version": "1.1.0",
  "contract_digest": "sha256",
  "input_digest": "sha256",
  "input_reference": "immutable-validated-ref",
  "goal_id": "optional",
  "plan_id": "optional",
  "run_id": "optional",
  "step_id": "optional",
  "policy_ref": "server-owned",
  "approval_ref": "server-owned",
  "idempotency_key": "server-owned-or-validated",
  "expected_revision": 1
}
~~~

**Route 1: Query/read.** Identity/tenant/permissions + schema + quota → existing QueryBus/registered read provider → output schema → redaction → response/audit. Reads must not produce external mutation (including read adapters with hidden writes).

**Route 2: Direct human internal Command.** Already-authorized session/CSRF + schema + owner/binding + Action policy assessment; only if explicitly `direct_human`, not external/financial/irreversible, idempotency required. Execute through existing Domain CommandBus, check Domain native write receipt/readback, output schema, audit. No new dispatch path.

**Route 3: Governed Action.** Proposed immutable Plan → `GoalPlanValidator` + canonical capability resolution + human Plan approval → Platform Workflow → per-Action Policy + human approval (when policy demands) + canonical Kernel Action/Queue → owner Domain handler → verified Action attempt/receipt → Workflow checkpoint → separate native outcome verification. Never Gateway→handler direct for governed side effects.

**Route 4: Agent/Tool/Job.** P0 cataloging, discoverability, policy classification and bounded allowlist only; execution delegates to existing fenced Kernel Agent/Tool/Job runtime and Platform Workflow where pilot accepted. Unknown Tool side effects blocked; no generic unsafe automation.

**Route 5: AI/CRON/Event.** Authenticate origin server-side (signed intent/service identity), tenant-scoped proposal with replay guard, human review or explicit delegation policy where separately certified; Plan validation + native approvals as in Route 3. Current Property HMAC intent flow stays supported; no pretend universal direct invocation.

## 10. Validation contract

**Compile-time:** id/owner version syntax, known handler binding, duplicate discovery, schema files resolve under trusted `resources/contracts`, schema strictly supported, input/output schema type defined, side effect/permission/approval invariants, referenced docs/tests exist, dependency cycles, plugin unsafe capabilities hidden.

**Admission-time:** actual tenant permissions; module enabled; actor authenticated; owner verified; pinned contract; policy current; JSON object/primitive semantics; strict input value validation, extra fields blocked; payload max / nesting max / regex bounds; IP/SSRF/secret fields according to domain; required output contract defined; no unapproved secret in logs.

**Worker-time:** revalidate tenant/module/permissions if policy requires, live handler, immutable Plan/Action digest, Action approval, previous step receipts, claim fencing, attempt lease, idempotency and revision. Admission at proposal time is **not** perpetual authorization.

**Output-time:** validated against pinned output schema, explicit status, stable native reference, no out-of-tenant objects, PII redaction in transport/audit, verification attestation on domain native source. An invalid or missing output must never silently checkpoint `COMPLETED`; use `OUTPUT_UNVERIFIED`, block dependents and safe operator reconciliation.

**Validation engine:** extend existing `CapabilityJsonInputValidator` (or extract a shared schema engine) retaining fail-closed limited vocabulary. P0 MUST support input and output using same pinned profile, schemas validated at build-time. Unsupported constructs (e.g. remote `$ref`) reject; do not label partial validator as generic JSON Schema support. When transport decodes JSON preserve object/array distinctions; retain size/depth guards.

## 11. Execution Receipt vs Business Evidence

Технічний receipt — доказ конкретної операції, не успішності бізнес-метрики:

~~~json
{
  "status": "completed|failed|waiting|ambiguous",
  "organization_id": "immutable",
  "capability_id": "id",
  "capability_version": "semver",
  "contract_digest": "sha256",
  "plan_id": "ref",
  "run_id": "ref",
  "step_id": "ref",
  "action_id": "ref|null",
  "attempt_id": "ref|null",
  "native_result_reference": "ref|null",
  "input_digest": "sha256",
  "output_digest": "sha256|null",
  "output_schema_verified": true,
  "receipt_attestation_reference": "ref|null",
  "checked_at": "UTC",
  "error_code": "nullable",
  "recovery_required": false
}
~~~

Схема — **TO-BE response projection**, а не заміна чинних durable tables/DTO. Зміни в Action/Workflow persistence лише additive та versioned.

Окремий `DomainOutcomeEvidenceProvider` / існуючий owner verifier підтверджує native факт: реальний Property Asset, Sales Lead, Document relation, інструмент ринку тощо. Позитивне `Goal achieved` допускається **лише** після independent stored evaluation with evidence policy version. Якщо native reference зник/змінився, результат `UNVERIFIABLE`, а не synthetic success. Attestation не доводить причинність доходу чи угоди лише за часовим збігом.

## 12. Event/Rule binding

Використовувати існуючі `DomainEvent / EventBus / OutboxPublisher / DurableEventDispatcher / RuleEngineEventHandler`. Domain owns semantic events; Kernel owns generic infrastructure facts. Реєстр subscription — contribution manifest або versioned module-owned handler binding, але **не другий EventBus**.

~~~text
native state + DomainEvent + outbox in one transaction
  → at-least-once transport
  → registered tenant-scoped consumer / event checkpoint
  → Rule evaluation with dedupe + bounded recursion
  → proposed capability intent / approved Workflow signal
  → normal Policy / Approval / canonical execution
  → verified receipt + native evidence → separate Goal evaluation
~~~

Event metadata: `event_id`, `type`, `schema_version`, `organization_id`, `occurred_at`, `producer_domain`, `correlation_id`, `causation_id`, `native_reference`, `payload_digest`. Per-consumer `event_id+consumer_id` dedup, visibility of retry/dead-letter. Domain event names are facts (past tense). User-facing subscriptions cannot create new execution authority. Loop breaker: max hop depth + causation chain check; self-trigger replay denied.

**P0:** inventarize current consumers and verify their ownership; establish contract that future Goal completed/evaluated signals are published after durable state and cannot infer achievement from Step completed.  
**P1:** governed dynamic subscription registry and UI for Rule→Intent, with approval policies and rollback.

## 13. AI Planner integration

- AI sees only safe catalog projection for actor/tenant + `description`, use cases, input hints, known prerequisites, cost/risk, confidence is advisory, and `not executable` statuses.
- Model returns **proposal** using references `capability_id@version`, structured step inputs and `depends_on`. No invented function, arbitrary code, policy grant, approval ID or direct SQL/HTTP.
- Existing `GoalPlanValidator` or shared contract validation deterministically checks DAG, capability pin, schema input, dependencies and role availability; cycle/missing edges/unsupported IDs reject.
- Tool selection may request read-only discovery queries only under existing runtime policy; side-effect requests become human-reviewed proposed plans.
- Multiple possible capabilities ⇒ show alternatives and blockers; never silently pick one with higher risk.
- Ask user for missing business parameters in UI; do not hallucinate native IDs, Deal IDs, URLs, amounts or customer identities.
- AI receives sanitized outputs and full correlation, no secrets/PII beyond actual actor authority. Untrusted tool output never instructs executor.
- Deterministic acceptance: same frozen catalog snapshot + same proposed JSON yields identical Plan validation/normalization decisions. LLM nondeterminism is not a reason to weaken validation.

## 14. UI / Experience Package P0

`/workspace/capabilities` має стати **робочим інструментом**, а не лише таблицею технічних ID.

**Library list:** пошук людською мовою, Domain, тип (Query/Command/Action/Agent/Tool), lifecycle, tenant-readiness, impact, version, last certified SHA, dependencies. Недоступні функції видимі за правом із чіткою причиною і без кнопки виконання.

**Capability detail:** призначення для бізнесу, owner, приклади input/output (без секретів), schema, дозволені канали, потрібні права/approvals, timeout/retry/idempotency, native evidence, verified test profile, залежності, активні Run/usage, version changelog. Вкладка Expert з технічним JSON.

**Try/Validate:** auto-generated form із local schema, тільки `dry-run` до явного допустимого invoke; Query можна виконувати після авторизації; governed Action тільки `Додати до плану`, не Run now. Idempotency key повторно не генерується під час небезпечного retry.

**Goal binding:** під час побудови плану вибір `доступної бізнес-операції` з Library, показ очікуваного результата і verification. Формування Step з pinned version/digest; бачити blockers до approval.

**Governance:** admin view declaration vs live binding, output receipt certification, schema drift, modules/tenants, forbidden internal capabilities, failed tests, sunset timetable. Не показувати raw secrets. Existing Result/Process/Expert presentation levels змінюють тільки деталізацію, не права.

**Accessibility:** keyboard navigation, semantic labels, explicit disabled reasons, contrast, mobile >= 320px, no JSON-only critical path.

## 15. Security / governance

Threats: tenant spoofing, cross-domain ownership substitution, confused deputy AI/CRON, approval bypass, forged receipt, replay after timeout, service drift, stale version, schema poisoning, prompt injection in tool outputs, unbounded fan-out, event loop, API enumeration and information leak.

Mitigations:
1. trusted server-side actor + scoped service identities, no impersonation from request body;
2. tenant, permission and module check at discovery/invoke/admission/worker/verification;
3. session CSRF for human forms; signed nonce/rate bound or approved service auth for machine proposal; authorization never from signature alone;
4. static allowlisted local schema files; block symlink/path traversal/untrusted refs, size/depth bounded;
5. high-risk/irreversible operations require explicit Action and independent human Approval; never self-approval;
6. one writer + idempotency + lease fencing + outbox transaction boundaries; no dual-writes;
7. no blindly retried external effect after unknown outcome; receipt-based reconciliation with audit;
8. redact secret/PII in logs, tool output to AI, error reason fields, list/detail projections;
9. append-only evidence trace with immutable SHA/digest + retention policy; no invented cryptographic signature when not present;
10. security review of plugin/third-party capability manifests before activation.

## 16. Persistence / cache / deployment

**P0 без окремої mutable authoritative `cos_capabilities` таблиці.** Runtime Catalog compiles from manifests; DI/handler evidence derives at boot/deploy. Database may store ONLY tenant overlays (already existing settings), audit of certification, pinned contract snapshots/digests as needed, immutable invocation/receipt references and governance decisions. Before any new table use gap analysis vs existing Action/Workflow/Audit/Module persistence.

Registry cache key: build SHA + module set digest + contract schema digest; per-tenant readiness **never** cached across permission/module/approval changes without invalidation. Version pin must resolve across worker deployments or fail closed. Database migrations additive only; tenant foreign keys/indexes, no new Run/Step writer. Rollback keeps immutable receipts and prevents old binary from replaying unrecognized executions.

## 17. Observability / metrics

Per operation trace: `correlation_id`, `causation_id`, `tenant` (restricted), `actor_ref`, `capability_id@version`, `contract_digest`, `plan/run/step/action` references, binding, timestamps, denied code, receipt status, evaluator policy. Не логувати full PII/raw credentials/full LLM prompts by default.

Dashboards: total declared/typed/bound/certified/drift; direct/query/workflow invocation count; blocks by reason; schema validation failures; approval wait; duplicate deliveries/claims suppressed; ambiguous remote outcomes; output schema invalid; business evidence unverified; age of retired pinned plans. Data derived from durable sources, not frontend optimistic counts.

## 18. Backward compatibility / migration

Phase 0: read-only full inventory all Domain manifests, existing API, DI, Action handlers and Tool/Agent, approved Plans; classify `DECLARED_ONLY/BOUND/CERTIFIED/INTERNAL/LEGACY/UNKNOWN`. Output machine JSON with SHA; annotate exact consumers.

Phase 1: add schema/digest/compiler/readiness projection behind feature flag. No changes to existing invocation, business writes, or worker. Shadow compare returned statuses on selected tenants; mismatches block activation.

Phase 2: output validation on existing safe Property direct Query/Command + approved Federation Action receipts; output mismatch becomes guarded uncertain, never replay. Add bounded MySQL tests and receipts.

Phase 3: Goal Planner version pin + capability-to-Plan binding (existing `GoalPlanValidator`), human approvals unchanged. Run parallel read-only validation on Golden 50 and Property Bulk, no parallel execution.

Phase 4: extend certified Domains one at a time: Property → Growth/Sales/Documents (Golden 50) → next Domains and Kernel Agent/Tool. Update catalog UI and automated proposals. No mass auto-activation.

Phase 5: migration/sunset legacy catalog consumers only after full consumers inventory, compatibility evidence, rollback and operator sign-off. Retain historical immutable snapshots.

## 19. Work breakdown / ownership (implementable tickets)

| ID | Priority | Package | Required deliverables |
| --- | --- | --- | --- |
| CL-01 | P0 | Registry census | All manifests, typed/declarative, DI/handlers, API/Goal/AI/Queue/Event consumers; exact SHA, machine report |
| CL-02 | P0 | Contract compiler | Schema validation both I/O, normalized contract digest, duplicates/ownership/dependency graph checks |
| CL-03 | P0 | Version pinning | Resolve id/version/digest, immutable archived snapshots, backward-compatible adapter and no silent remap |
| CL-04 | P0 | Runtime readiness | One tenant/actor-aware projection across query/direct/workflow/agent/tool with consistent reasons |
| CL-05 | P0 | Invocation standard | Trusted context envelope, origin boundaries, idempotency, existing Query/Command/Action/Workflow delegation |
| CL-06 | P0 | Output/result verification | Required output schema, native domain read proof, canonical receipts, separate Goal Outcome |
| CL-07 | P0 | Goal Planner binding | AI proposal → typed DAG → deterministic preflight → existing approval Plan; no invented capabilities |
| CL-08 | P0 | Event/intent governance | Current consumer audit + event→intent adapter, dedupe, no authority grants; subscriptions P1 |
| CL-09 | P0 | Library UX | Search/list/detail/try/validate/readiness, human messages, expert proof/audit and Goal deep links |
| CL-10 | P0 | CI/E2E acceptance | Unit/architecture/MySQL/browser/failover matrices, operator sign-off and deploy attestation |
| CL-11 | P1 | Domain certification expansion | Domain-by-Domain tests and monitored rollout, feature enablement after proof |
| CL-12 | P1 | Dynamic event subscriptions | Governed subscriptions versioned with quarantine/dead-letter replay controls |

Every ticket requires changed files inventory, owner, test coverage, expected DB migration (or explicit none), feature flag, rollback, no new executor, PR and SHA-linked evidence.

## 20. Test catalog та обов'язкові негативні кейси

| Test ID | Scenario | Expected result |
| --- | --- | --- |
| REG-01 | Two Domains claim same ID | Build FAIL |
| REG-02 | Typed contract not declared in owning `module.php` | FAIL |
| REG-03 | Declared string with no typed contract | Listed DECLARED_ONLY; never invocable |
| REG-04 | Handler missing/DI not registered/version mismatch | BLOCKED with exact safe reason |
| REG-05 | Old pinned Plan after manifest version upgrade | Exact snapshot resolves or BLOCKED; never silent retarget |
| REG-06 | Schema missing, remote `$ref`, unsupported keyword, tampered symlink | FAIL CLOSED |
| INV-01 | Foreign tenant / self-approved Action / forged actor | 403/denied, no execution |
| INV-02 | Human Query with valid schema & native read | Verified typed output, no mutation |
| INV-03 | AI/CRON/Event POST invoking direct Command | DENIED or pending intent; no Domain write |
| INV-04 | Required Action Approval missing or stale policy | BLOCKED, no dispatch |
| INV-05 | Duplicate idempotency key + same digest | Same logical result/reference, one effect |
| INV-06 | Duplicate idempotency key + changed digest | CONFLICT, no new effect |
| INV-07 | Out-of-schema Domain output | OUTPUT_UNVERIFIED; no dependent Step |
| RUN-01 | CAS race / two workers claim one Step | Single authoritative claim |
| RUN-02 | Crash before remote effect | Safe retry only where trusted pre-effect proof |
| RUN-03 | Crash after remote effect, no durable receipt | AMBIGUOUS, no blind retry |
| RUN-04 | Completed Action but missing native entity | Goal UNVERIFIABLE, never achieved |
| EVT-01 | Same Event delivered twice | One checkpoint/effective proposal; no repeated Action |
| EVT-02 | Event recursion / cross-tenant subscription | Quarantined/denied |
| UX-01 | Nontechnical user finds and validates capability | Human understandable, no raw JSON requirement |
| GOLD-01 | Golden 50 with 49/50 native Documents | Not achieved |
| GOLD-02 | 50 real Candidates/Leads/Documents + receipts + approvals | Independent stored satisfied evaluation on one accepted SHA |
| GOLD-03 | No active handler in any child Plan | No synthesized business outcome |

**Test layers:** unit deterministic compiler; architecture (one catalog/one binding/one Run writer); integration MySQL 8.x (tenant, CAS, receipts, outbox, recovery); browser keyboard/mobile; staging with real two-human approvals and native data; production preflight no side effect. CI PASS ≠ live fleet acceptance.

## 21. Acceptance gates / Go-No-Go

| Gate | Pass evidence |
| --- | --- |
| G0 | All public capabilities inventoried; owner collision zero; real runtime binding audit on pinned SHA |
| G1 | 100% newly executable public contracts input/output schema valid, actual handlers registered, drift zero for promoted scope |
| G2 | Versioned snapshot pin survives deploy/rollback or cleanly blocks stale Plans; no remapping |
| G3 | UI/API/AI/CRON/Event initiators all use one admission policy semantics; unsafe machine invocation denied |
| G4 | No duplicate writer/Action dispatch; MySQL CAS/retry/ambiguous evidence tests green |
| G5 | Output schema verified, independent native evidence linked, no synthetic Goal success |
| G6 | Event→Rule→Intent replay/loop/tenant tests green; dynamic subscriptions only if separately accepted |
| G7 | Library UX accessible, discoverable, and helpful with explicit blocked reasons |
| G8 | Isolated Golden 50 50/50/50 + 200 separately approved child Actions, verified native relations, independent durable `native_goal_aggregate_v1` evaluation and operator signoff on exact SHA |

**No-Go if:** any severe contract drift, missing native receipt, production tenant unverified, unknown consumer could double-dispatch, output schema unsafe, or a capability allows AI to bypass approval. Package may merge incrementally only after **scope-specific** gates and preserved regression, not as claim of full Golden 50 production readiness.

## 22. Definition of Done для P0

1. Після будь-якого deploy можна відповісти: «Які functions має COS, кому вони належать, які versions, що реально bound, кому/коли доступно і чому не можна виконати?» через один canonical projection.
2. Кожен виконуваний Step посилається на конкретний довірений immutable contract; жодна зміна каталогу не змінює зміст approved Plan.
3. Кожен input **та output** пройшов pinned schema profile; зовнішні side effects мають independent Action receipt, native business result — окремий evidence.
4. Кожний ініціатор використовує existing authority boundaries; не виник новий Action/Workflow/Event executor.
5. Golden 50 та Property Bulk інтегровані як regression anchors без спеціального bypass.
6. Є QA/CI/production evidence ledger, rollout controls, rollback, operator signoff. Якщо чогось немає — чесно відображається NOT VERIFIED, а не 100%.

## 23. Порядок виконання

**Wave A (контракти):** CL-01 → CL-02 → CL-03 → CL-04.  
**Wave B (виконання/докази):** CL-05 + CL-06 → CL-07 → CL-08.  
**Wave C (продукт/приймання):** CL-09 + CL-10 → Golden 50 E2E.  
**Wave D (масштабування):** CL-11/12 і лише тоді retired consumer cleanup.

Окремі PR з однаковим governance contract, не одна велика неперевірена заміна. Першими вертикальними доказами мають бути `Property Asset create/get` і `Golden 50 Growth→Sales→Documents`.

---

## Додаток: відповідність до наявних контрактів

- [Поточна канонічна бібліотека](./cos-universal-capability-invocation.md)
- [COS Federation architecture](./cos-federated-architecture-implementation-spec.md)
- [Execution contract P0](./cos-execution-contract-consolidation-p0.md)
- [Golden 50 acceptance](../02-workflows/federation-golden-50-operator-acceptance.md)
- [Events and Outbox](../05-runtime/events-and-outbox.md)

**Це специфікація, а не факт розгортання чи завершений аудит live fleet.**
