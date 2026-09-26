# WEB V0.6 — Clients Workspace

## Purpose

WEB V0.6 introduced Clients as a Sales-owned Workspace projection without creating a separate Clients Domain.

Canonical routes remain:

- `/client-case/inbox`;
- `/client-case`;
- `/client-case/show/{id}`.

## Current canonical runtime

Wave 13 Phase 4 completed the original WEB V0.6 migration path:

```text
/client-case/inbox
  → Operational Queue
  → ClientCaseInboxController
  → GetClientCaseInboxQuery
  → Twig

/client-case
  → Collection
  → ClientCaseCollectionController
  → GetClientCaseCollectionQuery
  → Twig

/client-case/show/{id}
  → Entity Workspace
  → ClientCaseWorkspaceController
  → GetClientCaseWorkspaceQuery
  → CosWorkspace / Twig
```

All read composition now follows Application Query → ViewModel/Presenter → canonical archetype/patterns.

## Domain ownership

Clients remains a presentation family inside Sales.

There is no `Domains/Clients`.

Client cases, inbound requests, pipeline stages, activities, matches and write services remain Sales-owned.

The Entity Workspace is registered as `sales.client_case`, but its underlying governed entity reference is `sales.deal`. This allows Client Case to reuse Sales UIActions and Workspace extensions without duplicating business semantics.

## Mutation ownership

All Client Case POST routes are centralized in:

`App\Web\Sales\ClientCaseMutationController`

It preserves:

- create/update opportunity;
- quick update;
- activity creation;
- inbound request update;
- lead → opportunity conversion;
- request attachment;
- property-match update;
- CSRF and return-url contracts.

Read controllers do not own writes.

## Frontend ownership

The historical dedicated bundle is retired:

- `frontend/entrypoints/clients-workspace.js` — deleted;
- `frontend/features/clients/workspace.css` — deleted;
- `ClientCasePageController` — deleted;
- three Client Case PHTML views — deleted.

Canonical Client Case styling belongs to Symfony visual system. The only domain-specific visual code is `symfony/assets/styles/domains/client-case.css`, currently used for funnel geometry and built from COS tokens.

## Regression contract

`tests/architecture/web_v06_clients_workspace.php` verifies:

- three canonical read controllers and archetypes;
- Sales ownership and absence of a Clients Domain;
- `sales.client_case` Workspace registration;
- centralized mutation ownership;
- canonical Twig surfaces;
- zero Client Case PHTML;
- zero legacy Clients Vite/CSS bundle.

## Definition of Done

WEB V0.6 is complete when Clients uses the shared COS Experience Platform end-to-end, retains Sales business ownership and server-side workflow semantics, and has no separate legacy presentation runtime.

Wave 13 Phase 4 satisfies this definition.
