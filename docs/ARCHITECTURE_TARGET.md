# COS canonical architecture target

The Symfony runtime is the canonical COS application shell and composition root. Business code remains framework-independent under `app/` and legacy behavior is pulled behind adapters during migration.

## Dependency direction

```text
Domain
  ↑
Application
  ↑
Infrastructure / Symfony
```

Domain and Shared Kernel code must never depend on Symfony, Phalcon, HTTP, persistence, queue or other infrastructure concerns.

## Target root

```text
app/
  Kernel/
    Shared/
    Identity/
    Tenant/
    Agent/
    Tool/
    Workflow/
    Integration/
    Audit/
    Knowledge/
  Domains/
    Sales/
    Service/
    Finance/
    Procurement/
    HR/
    Construction/
    Documents/
    RealEstate/
  Interface/
    Http/
    Console/
    Webhook/
  Infrastructure/
    Persistence/
    Queue/
    Cache/
    AI/
    External/
config/
migrations/
tests/
docker/
```

## Shared Kernel decisions

- Canonical tenant primitive is `OrganizationId`, matching the current COS data model and `organization_id`. `CompanyId` is not introduced until it represents a distinct business concept.
- `Organization` is the tenant business identity. `Tenant` is not a duplicate entity; `Kernel/Tenant` owns runtime context, tenant isolation and authorization boundaries around an Organization.
- New request/application code consumes `TenantContextProviderInterface -> TenantContext`. The older `Kernel\Tenant\OrganizationContextInterface` remains only as a legacy delivery compatibility bridge during migration.
- Shared Kernel contains only stable cross-domain primitives and contracts.
- `Clock` is a contract in Shared; concrete system/frozen clock implementations belong outside Domain.
- `Money` stores integer minor units and a normalized three-letter currency code; floats are not part of the domain contract.
- Repository contracts stay domain-specific. A generic repository base interface is intentionally not added because it would erase aggregate-specific semantics.
- Existing `Kernel\Event\DomainEvent` remains untouched in the first slice. It is a legacy event envelope and will be adapted to the new `Kernel\Shared\Domain\DomainEvent` contract incrementally rather than renamed across the codebase in one migration.

## Migration rule

The temporary `symfony/legacy/*` copy is an adapter bridge, not the target architecture. Canonical Kernel and Domain source lives in root `app/`; each migrated vertical slice should reduce the copied legacy surface until the bridge disappears.
