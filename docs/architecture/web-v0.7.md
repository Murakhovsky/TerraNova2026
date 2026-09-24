# WEB V0.7 — Property Inventory / Property Workspace

## Goal

WEB V0.7 migrates the operational Property surfaces into the shared Company Workspace shell without changing Property business rules, persistence contracts or public catalogue behaviour.

This is a Web delivery migration, not a Property Domain rewrite.

## Migrated internal surfaces

The following existing routes become layout-owned Property Workspace surfaces:

- `/property/manage` — Inventory;
- `/property/listing` — Listing / sales inventory;
- `/property/add` — create internal draft;
- `/property/edit/{id}` — Property Workspace;
- `/property/group/{id}` — location/group workspace;
- `/property/submissions` — moderation queue;
- `/property/submission/{id}` — moderation item.

The routes, form actions and existing Property services remain unchanged. Historical `shared/manager_header` calls may remain in the PHTML views; the shared shell compatibility guard suppresses them when the global layout owns the shell.

## Workspace contract

`PropertyController` prepares migrated surfaces with:

- `workspaceSection = properties`;
- a route-appropriate `workspaceActive` key;
- `noindex,nofollow` metadata;
- the dedicated `property-workspace` Vite bundle;
- any legacy feature bundles still required by media, copy and editor behaviour.

`property/listing` remains a shared route. Team users receive the Company Workspace shell; listing-capable portal roles continue to receive the portal header because the shared header resolves the authenticated role before rendering navigation.

## Frontend bundle

WEB V0.7 adds:

- `frontend/entrypoints/property-workspace.js`;
- `frontend/features/property/workspace.css`;
- `frontend/features/property/workspace.js`.

The bundle supplies responsive operational layout rules, table overflow handling and progressive submit pending state. Existing Property PHTML remains server-rendered.

## Explicitly outside WEB V0.7

The following surfaces remain Public/Portal and are not pulled into Company Workspace:

- `/property/catalog`;
- `/property/show/{slug}`;
- `/property/map`;
- `/property/presentation/{slug}`;
- `/property/submit`;
- `/property/favour`.

`/spatial/*` remains a separate 3D administration surface and is not part of the V0.7 closure.

WEB V0.7 also does **not** claim that legacy Property write paths are fully multi-tenant. WEB V0.4.1 closed the tenant-safe Company Home read boundary; explicit organization ownership across all Property commands remains Property Domain debt.

## Ownership

Property remains owned by the existing Property module. WEB V0.7 does not introduce `Domains/Frontend`, a second Property domain, or a parallel persistence layer. `PropertyNavigationContributor` remains the module-owned source of Property navigation.

## Definition of Done

WEB V0.7 is closed when:

1. internal Property operational pages declare the shared layout-owned Workspace context;
2. public Property surfaces remain outside that context;
3. required media/copy bundles are preserved alongside the new workspace bundle;
4. the Property workspace bundle builds through Vite and is covered by frontend asset validation;
5. responsive and pending-submit behaviour is present;
6. a dedicated WEB V0.7 architecture gate passes independently of unrelated legacy Sales checks.


## Wave 13 Phase 5 retirement

WEB V0.7 описує історичний compatibility етап. Wave 13 Phase 5 завершив його:

- `/property/manage` і `/property/listing` працюють через Symfony/Twig Collection/DataGrid;
- `/property/submissions` працює як Operational Queue;
- `/property/submission/{id}` працює як Entity Workspace;
- dedicated `property-workspace` Vite entrypoint, CSS і JS видалені;
- `/property/map` переведено на public Twig Map / Spatial surface;
- `/spatial/manage` переведено на private Twig Map / Spatial surface.

Історична назва WEB V0.7 gate збережена лише як regression contract.
