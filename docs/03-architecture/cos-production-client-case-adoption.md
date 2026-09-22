---
title: "Впровадження Client Case у production UI"
description: "Контрольована server-component міграція Client Case views без зміни workflow forms, funnel та mutation behavior."
status: active
updated: 2026-09-22
kind: architecture
---

# Впровадження Client Case у production UI

PHASE 12 закриває останній явно зафіксований борг WEB V0.17: server-component міграцію `client_case/index.phtml`, `inbox.phtml` та `show.phtml`.

Принцип той самий, що в попередніх production adoption phases: presentation geometry стає canonical, а Sales/Client Case behavior, routes, CSRF і mutation semantics не змінюються.

## Хвиля 1

### Вхідні заявки (`Client Case Inbox`)

`client_case/inbox.phtml`

- legacy hero замінено на canonical PageHeader;
- action/error/empty states використовують canonical State;
- summary metrics переведено на canonical KPI cards;
- status navigation переведено на canonical Tabs;
- локальний GET filter form замінено на shared FilterBar;
- queue shell переведено на canonical Panel;
- operational `tn-inbox-card` і workflow forms свідомо збережені;
- property/case deep links не змінені;
- `updateInboundRequest`, `createFromInboundRequest`, `linkInboundRequest` routes не змінені;
- `csrf_token`, `return_url` і всі triage field names не змінені.

## Хвиля 2

### Клієнтські кейси (`Client Case Index`)

`client_case/index.phtml`

Index уже мав значну частину canonical composition після WEB V0.17 compatibility bridge, тому хвиля не переписує його повторно.

Доведено до фінального production contract:

- action/error feedback переведено з локальних alerts на canonical State;
- Tabs тепер отримують явний `active` contract і правильно відображають вибраний funnel stage;
- redundant breadcrumb прибрано, бо workspace shell + PageHeader вже задають контекст;
- PageHeader, Tabs, FilterBar, Panel, State, Stage та canonical buttons лишаються базовою UX-мовою;
- create-case, unlinked-inbound triage та quick-update forms не змінені;
- funnel `tn-case-funnel` збережений як domain-specific visualization;
- case list з inline quick-update формою явно позначений як `tn-client-case-operational-grid`, а не маскується під read-only DataTable.

Routes `client-case/create`, `quickUpdate/{id}`, `createFromInboundRequest/{id}`, `linkInboundRequest` та всі CSRF/mutation fields не змінені.

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
- Wave 3: Client Case Workspace / Show;
- Wave 4: WEB V0.17 closure та compatibility cleanup.

## Критерії завершення

- Inbox використовує canonical PageHeader, State, KPI, Tabs, FilterBar і Panel;
- operational cards і mutation forms зберігають існуючу семантику;
- CSRF та return-url contracts не змінені;
- Index використовує canonical PageHeader, State, Tabs, FilterBar і Panel;
- funnel та operational quick-update grid явно зафіксовані як domain-specific interaction boundaries;
- controller/route ownership лишається у ClientCasePageController;
- PHASE 12 architecture gate запускається у CI.
