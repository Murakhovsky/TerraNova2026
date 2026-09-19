---
title: Реєстр бізнес-процесів
description: Згенерований реєстр канонічних бізнес-процесів COS, ownership, покриття capabilities і runtime verification на основі evidence.
status: generated
updated: 2026-09-16
kind: reference
contract: reference-v1
generated: true
---

# Реєстр бізнес-процесів

Згенеровано з `resources/processes/*.json`, канонічних module capabilities і каталогу runtime evidence поточного checkout. Не редагуйте цю сторінку вручну.

Бізнес-стан, покриття capabilities і runtime verification є окремими вимірами: крок може виконуватися поточним кодом, навіть якщо vocabulary можливостей його Domain ще неповний.

## Індекс процесів

| Процес | Domain | Бізнес-стан | Verification | Кроків | Cross-domain | Ownership | Capability mapped | Evidence verified | Critical source | Critical runtime | Workflow |
| --- | --- | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| Diagnostic Session → Recommendation | `diagnostic` | `as-is` | `source-verified` | 7 | 0 | 7/7 | 0/7 | 7/7 | 7/7 | 0/7 | [Відкрити workflow](../02-workflows/diagnostic-session-to-recommendation.md) |
| Property Submission → Publication | `property` | `as-is` | `source-verified` | 6 | 0 | 6/6 | 6/6 | 6/6 | 6/6 | 0/6 | [Відкрити workflow](../02-workflows/property-submission-to-publication.md) |
| Opportunity → Property Reservation | `real_estate` | `as-is` | `source-verified` | 7 | 3 | 7/7 | 7/7 | 7/7 | 7/7 | 3/7 | [Відкрити workflow](../02-workflows/real-estate-opportunity-to-reservation.md) |
| Sales Lead → Managed Case | `sales` | `as-is` | `source-verified` | 8 | 0 | 8/8 | 0/8 | 8/8 | 6/6 | 3/6 | [Відкрити workflow](../02-workflows/sales-lead-to-managed-case.md) |
| Sales Request → Property Match | `sales` | `as-is` | `source-verified` | 5 | 1 | 5/5 | 1/5 | 5/5 | 4/4 | 1/4 | [Відкрити workflow](../02-workflows/sales-request-to-property-match.md) |
| Service Request → Ticket Close | `service` | `as-is` | `source-verified` | 7 | 0 | 7/7 | 7/7 | 7/7 | 6/6 | 0/6 | [Відкрити workflow](../02-workflows/service-request-to-close.md) |

## Модель перевірки та можливостей

- `capability mapped` — крок посилається на discoverable capability, задекларовану його Domain module і присутню в канонічному vocabulary capabilities Architecture Graph.
- `capability gap` — крок реальний і може мати runtime evidence, але Domain-власник ще не декларує достатньо семантичну module capability для цієї бізнес-операції.
- `cross-domain` — крок виконується в Domain, відмінному від власника процесу, і захищений перевіреним `requires` contract від Domain процесу до Domain кроку.
- `documented` — topology реєстру існує, але щонайменше один критичний крок не підтверджений resolvable evidence поточного checkout.
- `source-verified` — кожний критичний крок має щонайменше один mapping, який резолвиться до поточного source/code evidence.
- `runtime-verified` — кожний критичний крок має щонайменше один mapping до канонічного runtime/contract registry. Це структурна перевірка, а не доказ спостереженого production execution trace.

| Процес | Кроків з owner | Capability mapped | Capability gaps | Cross-domain кроки | Mapped кроки | Evidence-verified кроки | Runtime-backed кроки | Critical source-verified | Critical runtime-verified |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Diagnostic Session → Recommendation | 7/7 | 0/7 | 7/7 | 0/7 | 7/7 | 7/7 | 0/7 | 7/7 | 0/7 |
| Property Submission → Publication | 6/6 | 6/6 | 0/6 | 0/6 | 6/6 | 6/6 | 0/6 | 6/6 | 0/6 |
| Opportunity → Property Reservation | 7/7 | 7/7 | 0/7 | 3/7 | 7/7 | 7/7 | 3/7 | 7/7 | 3/7 |
| Sales Lead → Managed Case | 8/8 | 0/8 | 8/8 | 0/8 | 8/8 | 8/8 | 3/8 | 6/6 | 3/6 |
| Sales Request → Property Match | 5/5 | 1/5 | 4/5 | 1/5 | 5/5 | 5/5 | 1/5 | 4/4 | 1/4 |
| Service Request → Ticket Close | 7/7 | 7/7 | 0/7 | 0/7 | 7/7 | 7/7 | 0/7 | 6/6 | 0/6 |

## Diagnostic Session → Recommendation

- **Process ID:** `diagnostic.session-to-recommendation`
- **Schema:** `v4`
- **Domain:** `diagnostic`
- **Бізнес-стан:** `as-is`
- **Покриття capabilities:** 0/7 кроків
- **Cross-domain кроки:** 0/7
- **Derived verification:** `source-verified`
- **Тригер:** A published methodology version is selected for a diagnostic target
- **Workflow:** [Diagnostic Session → Recommendation](../02-workflows/diagnostic-session-to-recommendation.md)

**Результати**

- Version-pinned diagnostic session is completed or canceled
- Evidence-backed findings and recommendations are recorded
- Recommendation decision remains traceable to the session

**Відповідальність, capabilities і runtime evidence**

| Крок | Owner | Domain | Capability / gap | Вид | Критичний | Executable / evidence mapping |
| --- | --- | --- | --- | --- | --- | --- |
| Draft and publish methodology version | methodology author | `diagnostic` | gap: `missing-domain-capability` | `operation` | так | use_case `DraftDiagnosticPack` [source]<br>use_case `PublishDiagnosticPack` [source] |
| Start version-pinned session | diagnostic operator / interviewer | `diagnostic` | gap: `missing-domain-capability` | `operation` | так | use_case `StartDiagnosticSession` [source] |
| Capture evidence | diagnostic operator / interviewer | `diagnostic` | gap: `missing-domain-capability` | `operation` | так | use_case `CaptureDiagnosticEvidence` [source] |
| Evaluate structured evidence | deterministic evaluation engine | `diagnostic` | gap: `missing-domain-capability` | `operation` | так | use_case `EvaluateDiagnosticSession` [source] |
| Record findings and recommendation | deterministic evaluation engine | `diagnostic` | gap: `missing-domain-capability` | `state` | так | use_case `RecordDiagnosticResult` [source] |
| Complete coherent session | diagnostic operator / interviewer | `diagnostic` | gap: `missing-domain-capability` | `operation` | так | use_case `CompleteDiagnosticSession` [source] |
| Review / accept recommendation | reviewer / decision maker | `diagnostic` | gap: `missing-domain-capability` | `decision` | так | use_case `AcceptDiagnosticRecommendation` [source] |

## Property Submission → Publication

- **Process ID:** `property.submission-to-publication`
- **Schema:** `v4`
- **Domain:** `property`
- **Бізнес-стан:** `as-is`
- **Покриття capabilities:** 6/6 кроків
- **Cross-domain кроки:** 0/6
- **Derived verification:** `source-verified`
- **Тригер:** External or manual property data enters the Property intake boundary
- **Workflow:** [Property Submission → Publication](../02-workflows/property-submission-to-publication.md)

**Результати**

- Canonical Property Asset is resolved or created
- Organization commercial state may exist as Inventory
- Market presentation may exist as Listing
- Channel-specific Publication state may be created and synchronized

**Відповідальність, capabilities і runtime evidence**

| Крок | Owner | Domain | Capability / gap | Вид | Критичний | Executable / evidence mapping |
| --- | --- | --- | --- | --- | --- | --- |
| Accept and validate submission | submitter / operator | `property` | `property.intake` | `operation` | так | use_case `PropertySubmissionService` [source] |
| Resolve identity / provenance | moderator / reviewer | `property` | `property.identity.review` | `decision` | так | use_case `PropertyModerationService` [source]<br>source `app/Domains/Property/Application/Service/PropertyIdentityWorkflowService.php` · `resolvePublished` [source] |
| Resolve or create canonical Property Asset | property manager | `property` | `property.registry` | `state` | так | source `app/Domains/Property/Application/Service/PropertyCanonicalRuntimeService.php` · `registerAsset` [source] |
| Create or update Inventory Item | organization inventory owner | `property` | `property.inventory` | `state` | так | source `app/Domains/Property/Application/Service/PropertyCanonicalRuntimeService.php` · `createInventory` [source] |
| Create or update Listing | listing/content operator | `property` | `property.listing` | `state` | так | source `app/Domains/Property/Application/Service/PropertyCanonicalRuntimeService.php` · `createListing` [source] |
| Publish and synchronize channel state | publication channel adapter | `property` | `property.publish` | `outcome` | так | source `app/Domains/Property/Application/Service/PropertyCanonicalRuntimeService.php` · `publishListing` [source] |

## Opportunity → Property Reservation

- **Process ID:** `real_estate.opportunity-to-reservation`
- **Schema:** `v5`
- **Domain:** `real_estate`
- **Бізнес-стан:** `as-is`
- **Покриття capabilities:** 7/7 кроків
- **Cross-domain кроки:** 3/7
- **Derived verification:** `source-verified`
- **Тригер:** A Sales opportunity needs a concrete Property matched and progressed toward reservation
- **Workflow:** [Opportunity → Property Reservation](../02-workflows/real-estate-opportunity-to-reservation.md)

**Результати**

- Sales opportunity is validated without RealEstate reading Sales storage directly
- Canonical Property and Inventory are resolved through Property contracts
- Brokerage match, offer and viewing are traceable in RealEstate
- Reservation is executed by Property ownership and reflected in the brokerage case

**Відповідальність, capabilities і runtime evidence**

| Крок | Owner | Domain | Capability / gap | Вид | Критичний | Executable / evidence mapping |
| --- | --- | --- | --- | --- | --- | --- |
| Validate Sales opportunity | Sales opportunity boundary | `sales` | `sales.workspace.use` | `operation` | так | contract `Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface` [runtime]<br>source `app/Domains/RealEstate/Infrastructure/Sales/SalesOpportunityReferenceAdapter.php` · `exists(` [source] |
| Resolve canonical Property and Inventory | Property boundary | `property` | `property.reference` | `operation` | так | contract `Domains\Property\Contract\PropertyBrokerageReferencePort` [runtime]<br>source `app/Domains/RealEstate/Application/Service/RealEstateWorkflowService.php` · `getPropertyPresentation(` [source] |
| Create brokerage Property Match | RealEstate broker | `real_estate` | `real_estate.property_match` | `state` | так | source `app/Domains/RealEstate/Application/Service/RealEstateWorkflowService.php` · `function match(` [source] |
| Create Property Offer | RealEstate broker | `real_estate` | `real_estate.offer` | `state` | так | source `app/Domains/RealEstate/Application/Service/RealEstateWorkflowService.php` · `function createOffer(` [source] |
| Schedule Property Viewing | RealEstate broker | `real_estate` | `real_estate.viewing` | `state` | так | source `app/Domains/RealEstate/Application/Service/RealEstateWorkflowService.php` · `function scheduleViewing(` [source] |
| Reserve Property Inventory | Property boundary | `property` | `property.inventory` | `operation` | так | contract `Domains\Property\Application\Contract\PropertyInventoryCommandInterface` [runtime]<br>source `app/Domains/Property/Application/Service/CanonicalPropertyInventoryCommands.php` · `function reserve(` [source] |
| Record brokerage reservation outcome | RealEstate broker | `real_estate` | `real_estate.reservation` | `outcome` | так | source `app/Domains/RealEstate/Application/Service/RealEstateWorkflowService.php` · `function reserve(` [source] |

## Sales Lead → Managed Case

- **Process ID:** `sales.lead-to-managed-case`
- **Schema:** `v4`
- **Domain:** `sales`
- **Бізнес-стан:** `as-is`
- **Покриття capabilities:** 0/8 кроків
- **Cross-domain кроки:** 0/8
- **Derived verification:** `source-verified`
- **Тригер:** Inbound public lead or external CRM webhook
- **Workflow:** [Sales Lead → Managed Case](../02-workflows/sales-lead-to-managed-case.md)

**Результати**

- Canonical Sales state exists
- Owner and pipeline state are managed
- Next action or outcome is recorded

**Відповідальність, capabilities і runtime evidence**

| Крок | Owner | Domain | Capability / gap | Вид | Критичний | Executable / evidence mapping |
| --- | --- | --- | --- | --- | --- | --- |
| Lead intake | Sales automation | `sales` | gap: `missing-domain-capability` | `operation` | так | use_case `ReceivePublicLead` [source]<br>use_case `ReceiveCrmWebhook` [source] |
| Process durable CRM inbox | Sales automation | `sales` | gap: `missing-domain-capability` | `operation` | ні | use_case `ProcessCrmInbox` [source] |
| Create or update canonical Sales state | Sales automation | `sales` | gap: `missing-domain-capability` | `state` | так | source `app/Domains/Sales/Automation/Event/LeadCreated.php` · `sales.lead.created` [source] |
| Assign owner | manager | `sales` | gap: `missing-domain-capability` | `operation` | так | use_case `AssignDealOwner` [source]<br>event `sales.deal.owner_assigned` [runtime] |
| Manage pipeline stage | salesperson | `sales` | gap: `missing-domain-capability` | `operation` | так | use_case `ChangeDealStage` [source]<br>source `app/Domains/Sales/Automation/Event/DealStageChanged.php` · `sales.deal.stage_changed` [source] |
| Record activity / call | salesperson | `sales` | gap: `missing-domain-capability` | `operation` | ні | use_case `CompleteSalesCall` [source]<br>source `app/Domains/Sales/Automation/Event/CallCompleted.php` · `sales.call.completed` [source] |
| Schedule next action | salesperson | `sales` | gap: `missing-domain-capability` | `operation` | так | use_case `ScheduleDealFollowup` [source]<br>event `sales.followup.created` [runtime] |
| Record business outcome | salesperson | `sales` | gap: `missing-domain-capability` | `outcome` | так | event `sales.deal.won` [runtime]<br>event `sales.deal.lost` [runtime] |

## Sales Request → Property Match

- **Process ID:** `sales.request-to-property-match`
- **Schema:** `v5`
- **Domain:** `sales`
- **Бізнес-стан:** `as-is`
- **Покриття capabilities:** 1/5 кроків
- **Cross-domain кроки:** 1/5
- **Derived verification:** `source-verified`
- **Тригер:** Sales operator converts an inbound request into a Client Case with referenced Property context
- **Workflow:** [Sales Request → Property Match](../02-workflows/sales-request-to-property-match.md)

**Результати**

- Inbound request is attached to a canonical Client Case
- Referenced Property is resolved through the Property Domain contract
- Sales owns a traceable Property Match and follow-up context

**Відповідальність, capabilities і runtime evidence**

| Крок | Owner | Domain | Capability / gap | Вид | Критичний | Executable / evidence mapping |
| --- | --- | --- | --- | --- | --- | --- |
| Load inbound request and referenced Property context | Sales application | `sales` | gap: `missing-domain-capability` | `operation` | так | source `app/Domains/Sales/Application/Service/SalesInboundService.php` · `createCaseFromRequest(` [source]<br>source `app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlClientCaseCommandRepository.php` · `inboundRequest(` [source] |
| Create Client Case and link inbound request | Sales application | `sales` | gap: `missing-domain-capability` | `state` | так | source `app/Domains/Sales/Application/Service/SalesInboundService.php` · `createCaseFromRequest(` [source]<br>source `app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlClientCaseCommandRepository.php` · `createCase(` [source]<br>source `app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlClientCaseCommandRepository.php` · `attachInboundRequest(` [source] |
| Resolve canonical Property presentation | Property read boundary | `property` | `property.reference` | `operation` | так | contract `Domains\Property\Contract\PropertyReferencePort` [runtime]<br>source `app/Domains/Sales/Infrastructure/Property/SalesPropertyReference.php` · `getPropertyPresentation(` [source] |
| Record Property Match in Sales | Sales application | `sales` | gap: `missing-domain-capability` | `state` | так | source `app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlClientCaseCommandRepository.php` · `upsertPropertyMatch(` [source]<br>source `app/Domains/Sales/Application/Service/SalesInboundService.php` · `PropertyMatchStatus::Interested` [source] |
| Record Sales activity and publish case/lead events | Sales application | `sales` | gap: `missing-domain-capability` | `outcome` | ні | source `app/Domains/Sales/Application/Service/SalesInboundService.php` · `Обʼєкт додано у підбір` [source]<br>source `app/Domains/Sales/Application/Service/SalesInboundService.php` · `ClientCaseCreated::create` [source] |

## Service Request → Ticket Close

- **Process ID:** `service.request-to-close`
- **Schema:** `v4`
- **Domain:** `service`
- **Бізнес-стан:** `as-is`
- **Покриття capabilities:** 7/7 кроків
- **Cross-domain кроки:** 0/7
- **Derived verification:** `source-verified`
- **Тригер:** A service request requires tracked ownership, SLA control and resolution
- **Workflow:** [Service Request → Ticket Close](../02-workflows/service-request-to-close.md)

**Результати**

- Service request and case are traceable
- Ticket ownership and SLA history are preserved
- Escalations are explicit and ordered
- Resolution is recorded before close
- Request and case close automatically when their tickets are complete

**Відповідальність, capabilities і runtime evidence**

| Крок | Owner | Domain | Capability / gap | Вид | Критичний | Executable / evidence mapping |
| --- | --- | --- | --- | --- | --- | --- |
| Create Service Request | service manager | `service` | `service.request` | `state` | так | source `app/Domains/Service/Application/Service/ServiceWorkflowService.php` · `function createRequest(` [source] |
| Create Service Ticket | service manager | `service` | `service.ticket` | `state` | так | source `app/Domains/Service/Application/Service/ServiceWorkflowService.php` · `function createTicket(` [source] |
| Assign Ticket | service manager | `service` | `service.assignment` | `state` | так | source `app/Domains/Service/Application/Service/ServiceWorkflowService.php` · `function assignTicket(` [source] |
| Set SLA | service manager | `service` | `service.sla` | `state` | так | source `app/Domains/Service/Application/Service/ServiceWorkflowService.php` · `function setSla(` [source] |
| Escalate Ticket | service manager | `service` | `service.escalation` | `operation` | ні | source `app/Domains/Service/Application/Service/ServiceWorkflowService.php` · `function escalate(` [source] |
| Resolve Ticket | service assignee | `service` | `service.resolution` | `outcome` | так | source `app/Domains/Service/Application/Service/ServiceWorkflowService.php` · `function resolve(` [source] |
| Close Ticket | service manager | `service` | `service.ticket` | `outcome` | так | source `app/Domains/Service/Application/Service/ServiceWorkflowService.php` · `function close(` [source] |

## Авторитетність і обмеження

- Registry schema `v5` розширює v4 contract-guarded cross-domain кроками. Наявні same-domain definitions v4 залишаються валідними.
- Задекларована capability має резолвитися до vocabulary capabilities Domain кроку; відсутня semantic capability має бути явно представлена як `capability: null` + `capability_gap`.
- Покриття capabilities не виводиться з назв класів, routes або permissions. Воно показує лише канонічні discoverable module capabilities.
- Cross-domain крок легальний лише для schema v5+ і за наявності перевіреного `contract` mapping, чиє module evidence декларує `role: requires` від Domain процесу до Domain кроку.
- Cross-domain виконання не створює shared state ownership: capability кроку належить foreign Domain, а процес залишається у власності root Domain.
- Бізнес-стан (`as-is` / `to-be`) відділений від derived runtime verification.
- Verification ніколи не задається вручну в process JSON. Вона обчислюється з mappings, перевірених через `generate-runtime-evidence.php`, і точних source symbols поточного checkout.
- Evidence типів `use_case` і `command` підтверджується source з канонічних module directories.
- Evidence типу `event` підтверджується runtime з explicit Domain event catalogues; `contract` — з канонічних module cross-domain contract declarations.
- `source` mappings мають резолвитися до наявного repository file і, якщо symbol заданий, містити оголошений symbol.
- `runtime-verified` тут означає структурне підтвердження canonical runtime registries для кожного критичного кроку. Це не означає, що COS спостерігав end-to-end production trace.
- `ProcessDiagram` рендерить core flow, ownership, capability і Domain projections з того самого registry definition.
- Coverage ratios показують повноту architecture/documentation, а не business performance KPI.
