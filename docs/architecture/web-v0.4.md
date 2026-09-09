# WEB V0.4 — Company Home

## Purpose

Company Home is the workspace-level operating overview for a manager. It is not a business domain and it does not own cross-domain business truth.

The projection is intentionally thin:

```text
Sales / Property / Kernel module state / COS operations
                    ↓
          canonical read contracts
                    ↓
      Interfaces\Web\Service\CompanyHomeService
                    ↓
             /admin workspace Home
```

## Canonical dependencies

`CompanyHomeService` may compose only existing read boundaries:

- `SalesWorkspaceReadModelInterface` for Sales KPIs, Today and leads;
- `OperationsReadModelInterface` for COS operational statistics and runtime health;
- `ActiveModuleResolver` for organization-specific module activation state.

It must not contain SQL, persistence adapters, business transition rules, scoring rules or duplicated automation logic.

## Module-aware behavior

Kernel V0.7 introduced organization-specific module activation. Company Home therefore treats Sales and Property as optional runtime capabilities:

- if a module is disabled, its read model is not called;
- Property is currently shown as enabled/disabled only because its existing catalog/management reads are not safely organization-scoped end to end; Home intentionally refuses to manufacture cross-tenant Property KPIs;
- if an enabled module fails, only that section becomes unavailable;
- COS runtime health is independent from domain module enablement;
- the module panel shows the installed manifest version and effective ON/OFF state.

This keeps Home compatible with the intended COS composition model where organizations can assemble different sets of domain modules.

## UI contract

The `/admin` route remains the canonical workspace Home for backward compatibility, but the old monolithic Admin/CRM dashboard is replaced by the shared workspace shell.

The screen contains:

1. Company pulse KPIs from canonical sources only;
2. Today operational focus from Sales;
3. COS action/approval/runtime attention;
4. new demand snapshot;
5. Property capability status without unsafe cross-tenant metrics;
6. effective runtime module state;
7. a compact decision queue when COS has pending work.

The screen uses the shared WEB V0.2 UI primitives and a dedicated `company-home` Vite entrypoint. There is no page-owned global header.

## Legacy boundary

`Domains\Identity\Infrastructure\ReadModel\MySql\AdminDashboardService` remains registered only because Administration still uses it for user management. Company Home no longer depends on its cross-domain dashboard queries.

That legacy service can be reduced later when Administration receives its dedicated WEB stage. WEB V0.4 does not move that old query surface into a new domain or copy it into another service.

## Future domains

A future domain may appear on Company Home only after it exposes a stable read contract. Home must not query a domain's tables directly merely to manufacture a dashboard tile.
