---
title: "Впровадження Client Case у production UI"
description: "Контрольована server-component міграція Client Case views без зміни workflow forms, funnel та mutation behavior."
status: active
updated: 2026-09-24
kind: architecture
---

# Впровадження Client Case у production UI

Wave 13 Phase 4 закриває Client Case compatibility bridge повністю: усі три production surfaces працюють через Symfony/Twig Experience Platform.

Принцип той самий, що в попередніх production adoption phases: presentation geometry стає canonical, а Sales/Client Case behavior, routes, CSRF і mutation semantics не змінюються.

## Хвиля 1

### Вхідні заявки (`Client Case Inbox`)

`symfony/templates/experience/client_case/inbox.html.twig`

- legacy hero замінено на canonical PageHeader;
- action/error/empty states використовують canonical State;
- summary metrics переведено на canonical KPI cards;
- status navigation переведено на canonical Tabs;
- локальний GET filter form замінено на shared FilterBar;
- queue shell переведено на canonical Panel;
- operational item винесено у domain component `ClientCaseInboxItem`, а workflow forms лишаються server-first;
- property/case deep links не змінені;
- `updateInboundRequest`, `createFromInboundRequest`, `linkInboundRequest` routes не змінені;
- `csrf_token`, `return_url` і всі triage field names не змінені.

## Хвиля 2

### Клієнтські кейси (`Client Case Index`)

`symfony/templates/experience/client_case/index.html.twig`

Index уже мав значну частину canonical composition після WEB V0.17 compatibility bridge, тому хвиля не переписує його повторно.

Доведено до фінального production contract:

- action/error feedback переведено з локальних alerts на canonical State;
- Tabs тепер отримують явний `active` contract і правильно відображають вибраний funnel stage;
- redundant breadcrumb прибрано, бо workspace shell + PageHeader вже задають контекст;
- PageHeader, Tabs, FilterBar, Panel, State, Stage та canonical buttons лишаються базовою UX-мовою;
- create-case та unlinked-inbound triage forms не змінені;
- funnel рендериться як Sales-specific `ClientCaseFunnel`;
- Collection використовує стабільну комбінацію `EntityList + FilterBar`;
- editable row належить `ClientCaseCollectionItem`, а не generic DataGrid;
- quick-update form зберігає `client-case/quickUpdate/{id}`, CSRF, `return_url`, `stage_id`, `status`, `priority` та `assigned_user_id`;
- row actions зберігають submit `ОК` та deep-link `Відкрити`.

Routes `client-case/create`, `quickUpdate/{id}`, `createFromInboundRequest/{id}`, `linkInboundRequest` та mutation semantics не змінені.

## Хвиля 3

### Робочий простір кейсу (`Client Case Workspace`)

`symfony/templates/experience/client_case/show.html.twig`

- legacy case hero замінено на canonical EntityHeader;
- entity identity поєднує case public id та person public id;
- status відображається через semantic Status;
- type, stage, manager, budget і next contact винесені у entity metadata;
- missing/action/error states використовують canonical State;
- базові секції переведено з `tn-admin-panel` на canonical Panel;
- context summary переведено на KPI cards;
- redundant breadcrumb та legacy dark/ghost button shell прибрані.

Свідомо збережені specialized operational patterns:

- AI Intelligence recommendation/action block;
- activity timeline;
- inbound-request relation table;
- property-match cards та inline mutation form;
- presentation share/PDF actions.

Mutation contracts `client-case/update/{id}`, `activity/{id}`, `updatePropertyMatch/{id}`, COS execute/approval actions та Property presentation sharing не змінені. CSRF і `return_url` поля збережені.

## Хвиля 4

### Завершення WEB V0.17

PHASE 12 закриває compatibility bridge Client Case повністю:

- `frontend/features/clients/workspace.js` видалено як зайвий scoping script;
- `clients-workspace` Vite entrypoint видалено повністю;
- compatibility-only CSS для legacy hero, metrics, tabs, admin panels, breadcrumbs та empty states видалено;
- WEB V0.6 gate переведено з bridge assumptions на canonical Client Case views;
- WEB V0.17 gate напряму перевіряє `index/inbox/show`;
- WEB V0.17 CI перевіряє три canonical Client Case Twig surfaces;
- `docs/architecture/web-v0.17.md` більше не описує Client Case як тимчасовий bridge.

Спільний production submit-state runtime лишається єдиним власником pending/aria-busy behavior.

## Межа operational cards

Inbox card одночасно містить:

- request identity та контекст;
- property/case relations;
- inline workflow mutation;
- create/attach case actions;
- manager note/activity capture.

Це не read-only DataTable і не проста EntityCard. Тому Wave 1 стандартизує shell, navigation, filtering і states, але не намагається симулювати operational card через сирий generic component contract.

Наступний canonical pattern для цього класу UI має бути окремий operational work item/card із first-class forms/actions.

## Наступні хвилі

- Wave 2: Client Case Index — виконано;
- Wave 3: Client Case Workspace / Show — виконано;
- Wave 4: WEB V0.17 closure та compatibility cleanup — виконано.

## Критерії завершення

- Inbox використовує canonical PageHeader, KPI, FilterBar, EntityList/Operational Queue patterns;
- `ClientCaseInboxItem` і mutation forms зберігають існуючу семантику;
- CSRF та return-url contracts не змінені;
- Index використовує canonical PageHeader, FilterBar, EntityList і `ClientCaseFunnel`;
- funnel лишається domain-specific interaction boundary, а editable row належить `ClientCaseCollectionItem`;
- Show використовує `CosWorkspace`, canonical EntityHeader, KPI summary, Timeline та ActionBar;
- AI, timeline, property-match і presentation-share patterns зберігають існуючу workflow семантику;
- read ownership розділений між `ClientCaseInboxController`, `ClientCaseCollectionController`, `ClientCaseWorkspaceController`; mutation ownership централізований у `ClientCaseMutationController`;
- legacy Client Case PHTML/CSS/Vite bridge видалено повністю;
- WEB V0.6/V0.17 та Wave 13 gates захищають canonical Client Case contracts;
- PHASE 12 architecture gate запускається у CI;
- WEB V0.17 production migration debt закрито.
