# Terra Nova Frontend / Interface Architecture

Status: **WEB V0.1**

## 1. Boundary

Frontend is **not** a DDD domain. It is the human-facing presentation layer over the capabilities exposed by the application and domain layers.

```text
Business Domain
    ↓
Application use cases
    ↓
Interfaces / API
    ↓
Frontend feature projection
    ↓
Human workflow
```

The repository must not introduce `Domains/Frontend`.

Server-side web concerns live under `app/Interfaces/Web`. Browser-side concerns live under `frontend`.

## 2. Canonical surfaces

Terra Nova has three distinct interface surfaces. They share design tokens and primitives but must not share the same information density or navigation model.

### Public

```text
Public
├── Home
├── Real Estate
│   ├── Catalog
│   ├── Map
│   ├── Property
│   ├── Compare
│   └── Presentation
├── Services
├── Partners
├── Company
└── COS product site
```

Primary public navigation is intentionally limited to:

`Нерухомість | Послуги | Партнерам | Terra Nova | COS`

Blog, cases, team, vacancies and other informational pages belong to contextual navigation and footer navigation, not to the primary header.

### Portal

For client / partner / realtor / developer / seller roles.

```text
Portal
├── Overview
├── Real Estate
├── Favourites
├── My Properties     [role dependent]
└── Submit Property   [role dependent]
```

Portal is user-centric: "my data, my properties, my requests".

### Company Workspace

For manager / admin roles.

```text
Workspace
├── Home
├── Sales
│   ├── Overview
│   ├── Today
│   └── Pipeline
├── Clients
│   ├── Inbox
│   └── Cases
├── Properties
│   ├── Inventory
│   ├── Listing
│   ├── Moderation
│   ├── 3D / Spatial
│   └── Public Catalog
├── COS
│   ├── Control Center
│   └── Diagnostics
├── Analytics
└── System
    ├── Users          [admin]
    ├── Content
    └── Cabinet
```

Workspace primary navigation is capped at six sections. Sub-pages belong inside the relevant section instead of competing for the top navigation level.

## 3. Route policy for WEB V0.1

WEB V0.1 changes information architecture, not backend route ownership. Existing URLs remain canonical for now:

- Sales: `/sales/*`
- Client cases: `/client-case/*`
- Properties: `/property/*`
- Internal COS: `/cos/control-center`
- Diagnostics: `/admin/diagnostics/*`
- Administration: `/admin/*`
- Portal: `/cabinet`

A future `/app/*` route namespace may be introduced only as a separate migration with aliases / redirects. UI structure must not depend on such a migration.

## 4. Physical architecture

```text
app/Interfaces/Web/
├── Controller/
├── Navigation/
│   └── FrontendNavigation.php
├── Routing/
└── View/
    ├── components/
    │   ├── workspace_sidebar.phtml
    │   ├── workspace_topbar.phtml
    │   └── workspace_mobile_nav.phtml
    ├── shared/
    └── <feature views>/

frontend/
├── core/
│   └── workspace-shell.js
├── components/           # reusable browser-side primitives when behavior is required
├── features/             # feature projections are added here as features migrate
├── layouts/              # browser layout behavior when required
├── entrypoints/
│   └── terranova-interface.js
└── styles/
    ├── tokens.css
    ├── foundation.css
    ├── components.css
    ├── patterns.css
    ├── workspace.css
    └── interface.css
```

Do not create placeholder classes or directories merely to satisfy this diagram. A directory appears when the first real implementation needs it.

## 5. Frontend feature rule

A browser feature is a projection of an existing business capability, not a new source of business truth.

Examples:

```text
Domains/Sales
  → Sales application/read API
  → frontend feature: sales

Domains/Diagnostic
  → Diagnostic API
  → frontend feature: diagnostics
```

Frontend code may own:

- rendering and interaction state;
- filters and view preferences;
- optimistic / loading / empty / error states;
- local UI composition;
- accessibility behavior.

Frontend code must not own:

- deal state transition rules;
- permission truth;
- diagnostic scoring rules;
- property publication rules;
- COS action policy;
- business invariants already owned by domains/application services.

## 6. Design system

One token system supports two density modes:

- Public: spacious, visual, editorial.
- Workspace: compact, information-dense, operational.

Layer order:

```text
tokens
→ foundation
→ components
→ patterns
→ feature styles
→ page exceptions
```

New global monolithic CSS files are forbidden. Existing `terranova-club.css` remains legacy-compatible and is migrated incrementally.

Core primitives for subsequent iterations:

- Button / Icon Button
- Input / Search / Select / Combobox
- Checkbox / Radio / Switch
- Tabs
- Badge / Status
- Avatar
- Dropdown / Tooltip
- Modal / Drawer / Toast
- Card / KPI Card
- Table / Data Grid / Pagination
- Filter Bar
- Empty State / Skeleton
- Timeline
- Kanban Card
- Activity Item
- Alert / Approval Card

WEB V0.1 introduces the shared styling contract and the primitives needed by the shell. Feature-specific components should be added only when a real screen consumes them.

## 7. Workspace shell contract

Desktop:

- expanded sidebar: `256px`;
- collapsed sidebar: `72px`;
- topbar: `68px`;
- primary sections: maximum six;
- utility/system navigation is visually separated.

Tablet:

- compact sidebar by default;
- full content remains available.

Mobile:

- sidebar becomes a drawer;
- bottom navigation exposes Overview / Today / Sales / More;
- content must reserve space for the bottom navigation.

The collapsed desktop state is a UI preference stored locally. Authorization remains server-side.

## 8. Command palette

WEB V0.1 provides a navigation command palette. It is deliberately not branded as AI search yet.

Current responsibility:

- fast navigation through rendered Workspace destinations;
- `/` keyboard shortcut;
- accessible Escape/close behavior.

Future responsibility may include global entity search and Ask COS only after a backend search/agent contract exists. The UI must not pretend a capability exists before the runtime exposes it.

## 9. UI states

Every new data-driven screen must define:

- Loading
- Loaded
- Empty
- Filtered empty
- Partial data
- Error
- Permission denied
- API unavailable
- Saving
- Saved
- Validation error

Raw backend errors are never the final user interface.

## 10. Accessibility and responsive baseline

Target: WCAG 2.2 AA.

Minimum rules:

- keyboard-operable controls;
- visible focus;
- semantic landmarks;
- `aria-current` for active navigation;
- statuses must not rely on color alone;
- touch targets should be approximately 44px where practical;
- reduced-motion preference is respected.

Breakpoints retain the existing project baseline:

- mobile: `<= 650px`;
- tablet: `651–1050px`;
- desktop: `> 1050px`;
- wide layouts may optimize at `>= 1440px`.

## 11. Migration sequence after WEB V0.1

1. Workspace Home
2. Sales Overview / Today / Pipeline
3. Client Inbox / Case Workspace
4. Property Inventory / Property Workspace
5. COS Control Center
6. Diagnostics
7. Analytics
8. Portal refinement
9. Public surface refinement
10. Legacy CSS extraction and deletion

Each screen migration should consume the shared shell and tokens instead of inventing a page-local mini design system, a cherished human tradition that this architecture explicitly declines to continue.

## 12. Definition of Done for WEB V0.1

- Frontend is represented as Interface/Presentation, not a DDD domain.
- Public / Portal / Workspace are distinct interface surfaces.
- Workspace has no more than six primary sections.
- Role-aware navigation has one server-side source of truth.
- Workspace sidebar, topbar and mobile navigation are reusable PHTML components.
- New interface JS is isolated in a dedicated Vite entrypoint.
- New CSS follows token/foundation/component/pattern/workspace layering.
- Existing domain APIs and web routes are not renamed.
- Existing `terranova-club` behavior remains loaded independently.
- Sales and internal COS pages are marked private for search engines in the global layout.
