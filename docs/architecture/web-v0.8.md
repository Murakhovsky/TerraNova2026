# WEB V0.8 — Analytics Workspace

## Goal

WEB V0.8 migrates the existing internal analytics report into the shared Company Workspace shell. It does not create a new Analytics domain and does not change Property funnel calculation rules.

The release also removes one stale CI invocation of the already-deleted `tests/unit/sales_v063.php`. Removing a workflow reference to a file that no longer exists is CI hygiene, not a Sales behaviour change.

## Migrated surface

- `/admin/analytics`

The route and current `PropertyFunnelAnalyticsInterface::report()` source remain unchanged.

## Workspace contract

`AdminController::analyticsAction()` declares:

- `workspaceSection = analytics`;
- `workspaceActive = analytics`;
- `metaRobots = noindex,nofollow`;
- `pageAssetEntries = ['analytics-workspace']`.

The shared layout owns the Workspace shell. The historical `shared/manager_header` call inside `admin/analytics.phtml` remains temporarily in place and becomes inert through the existing layout-owned compatibility guard introduced by WEB V0.6.

## Frontend bundle

WEB V0.8 adds:

- `frontend/entrypoints/analytics-workspace.js`;
- `frontend/features/analytics/workspace.css`;
- `frontend/features/analytics/workspace.js`.

The feature bundle is presentation-only. It supplies responsive report layout and progressive form submit state. It does not calculate conversion metrics, alter report periods or become a second analytics source of truth.

## Data and failure behaviour

The existing analytics service remains authoritative for the report payload. Existing 503 handling remains intact when the report cannot be loaded.

The current report is primarily a Property funnel report. Company-, Sales-, Clients- and COS-wide analytics remain future capabilities and must not be fabricated in the UI before corresponding application contracts exist.

## CI hygiene

`.github/workflows/diagnostic.yml` no longer invokes `tests/unit/sales_v063.php` because that file is absent from the repository. No replacement assertion is invented in WEB V0.8.

## Explicitly outside WEB V0.8

- a new Analytics DDD domain;
- new event aggregation or persistence;
- historical Sales intelligence;
- cross-domain company BI;
- charting libraries;
- changes to Property funnel business rules;
- removal of other legacy tests without direct evidence they are stale.

## Definition of Done

WEB V0.8 is closed when:

1. `/admin/analytics` renders through the shared Workspace shell;
2. Analytics has a dedicated Vite feature bundle;
3. responsive and submit-pending behaviour is scoped to the Analytics workspace;
4. the existing server-side report and failure contract remain unchanged;
5. the deleted `sales_v063.php` test is no longer called by the runtime workflow;
6. a dedicated WEB V0.8 architecture gate validates the migration independently.


## Wave 13 VR-022

Wave 13 retires the temporary WEB V0.8 presentation bridge. `/admin/analytics` now renders through Symfony/Twig `ExecutiveDashboard` using `GetWorkspaceAnalyticsQuery` and the canonical Experience Platform. The historical `analytics-workspace` Vite bundle and `admin/analytics.phtml` are removed; Property funnel calculations remain unchanged.
