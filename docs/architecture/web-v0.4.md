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
- `PropertyWorkspaceReadModelInterface` for organization-scoped Property catalog KPIs and featured inventory;
- `OperationsReadModelInterface` for COS operational statistics and runtime health;
- `ActiveModuleResolver` for organization-specific module activation state.

It must not contain SQL, persistence adapters, business transition rules, scoring rules or duplicated automation logic.

## Module-aware behavior

Kernel module activation is organization-specific. Company Home therefore treats Sales and Property as optional runtime capabilities:

- if a module is disabled, its read model is not called;
- Property Home reads are scoped by `tn_properties.organization_id` and never fall back to the global public catalog;
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
5. Property catalog pulse from the tenant-safe workspace read model;
6. effective runtime module state;
7. a compact decision queue when COS has pending work.

The screen uses the shared WEB V0.2 UI primitives and a dedicated `company-home` Vite entrypoint. There is no page-owned global header.

## WEB V0.4.1 closure

WEB V0.4.1 removes the deliberate Property placeholder that remained in the original V0.4 release. The closure adds a Property-owned workspace read contract and a tenant discriminator on `tn_properties`, so Company Home can display real Property totals and featured inventory without issuing cross-tenant catalog reads.

Existing Property rows are migrated to the `default` organization for backward compatibility. This closes the Company Home read boundary only; it does not pretend that every legacy Property write path is already a complete multi-tenant implementation. Write-side tenant propagation must be completed inside Property ownership rather than inside Company Home.

The HEAD audit also found that Property and Diagnostic module manifests still targeted Kernel `<0.11.0` while the runtime is Kernel `0.11.8`. Because Company Home depends on `ActiveModuleResolver`, this stale compatibility metadata could prevent the module catalog from being constructed before the page rendered. V0.4.1 aligns those non-runtime module manifests with Kernel `0.11.x`; Property is bumped to `0.1.1` because it also owns the new schema migration.

The Home UI no longer hard-codes a Kernel release number in the module-state caption. Runtime module versions already come from the active module manifests.

## Legacy boundary

`Domains\Identity\Infrastructure\ReadModel\MySql\AdminDashboardService` remains registered only because Administration still uses it for user management. Company Home no longer depends on its cross-domain dashboard queries.

That legacy service can be reduced later when Administration receives its dedicated WEB stage. WEB V0.4 does not move that old query surface into a new domain or copy it into another service.

## Future domains

A future domain may appear on Company Home only after it exposes a stable read contract. Home must not query a domain's tables directly merely to manufacture a dashboard tile.
