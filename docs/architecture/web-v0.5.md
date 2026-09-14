# WEB V0.5 — Module-aware Navigation

## Purpose

WEB V0.5 makes the shared Web shell reflect the effective module state of the current organization.

The runtime path is:

```text
OrganizationContext
       +
ActiveModuleResolver snapshot
       +
module.php web.navigation extensions
       ↓
ModuleExtensionRegistry
       ↓
Web-owned navigation contributor services
       ↓
ModuleAwareNavigationService
       ↓
workspace / portal navigation
```

A module that is disabled for the current organization must disappear from navigation without changing deployed PHP code or rebuilding frontend assets.

## Ownership boundary

Kernel remains UI-framework neutral.

`Kernel\Module\ModuleContributions` does not declare menu items, Web component paths or navigation structures. Kernel only owns the generic extension-point registry. Domain manifests declare Web navigation service ids through the generic `web.navigation` extension point.

The concrete navigation contributor implementations remain Web-owned:

- `SalesNavigationContributor`;
- `PropertyNavigationContributor`;
- `DiagnosticNavigationContributor`.

`WebApplicationServices` registers those concrete services. `ModuleServices` discovers the active extension declarations generically through `ModuleExtensionPoint::WEB_NAVIGATION` and exposes them as `cosModuleWebNavigationContributors`.

This mirrors the modular-monolith runtime without teaching Kernel about presentation concerns.

## Core vs module navigation

`FrontendNavigation` owns only stable shell navigation:

- Workspace Home;
- COS Control Center;
- Analytics;
- Administration;
- Cabinet utility entry.

Domain-specific UI is contributed separately:

- Sales contributes Sales and Clients workspace sections;
- Property contributes the Property workspace section and Property portal links;
- Diagnostics extends the COS section with Methodology Studio.

Contributors use ordering metadata internally. `ModuleAwareNavigationService` removes that metadata recursively before returning the view contract.

## Tenant behavior

`ModuleAwareNavigationService` resolves `OrganizationContextInterface::id()` every time workspace or portal navigation is built.

Each render resolves exactly one `OrganizationModuleSnapshot` from `ActiveModuleResolver` and evaluates every contributor against that same snapshot. This prevents repeated state reads and guarantees a consistent effective module view during one navigation render.

Therefore:

- switching the active organization changes the visible module navigation on the next render;
- disabled Sales removes Sales and Clients;
- disabled Property removes Property workspace and portal entries;
- disabled Diagnostics removes the Diagnostics COS child;
- one organization's module state cannot leak into another organization's navigation;
- one render cannot mix module states from multiple independently resolved snapshots.

The service does not cache organization identity or effective module state between renders.

## Role behavior

Module state and role-specific navigation are separate dimensions.

Examples:

- Sales can be enabled while `Sales Admin` remains visible only to the admin role;
- Property can be enabled while portal entries still depend on the account role;
- core Administration permissions remain owned by the core navigation rather than a business module.

## Security boundary

Navigation hiding is not authorization.

Kernel route/module access guards enforce module access per request. WEB V0.5 is the presentation projection of the same effective module state, not a substitute for backend authorization or route guards.

## Extension rule

A future Web-capable Domain must:

1. implement a Web-owned `ModuleNavigationContributorInterface` adapter;
2. register the concrete contributor service in Web composition;
3. declare that service id under `contributions.extension_services.web.navigation` in its `module.php`.

Do not add Web menu structures to Kernel manifests. The manifest declares only a generic extension service id; presentation semantics remain in Web.

Future UI extension points such as dashboard widgets, global search or command palette are out of scope for WEB V0.5 and should receive their own contracts only when a real use case requires them.

## Verification

WEB V0.5 is protected by two explicit gates:

- `tests/architecture/web_v05_module_navigation.php` checks ownership boundaries, manifest-driven extension wiring, generic Kernel registration and the shared-shell integration;
- `tests/unit/web_v05_module_navigation.php` verifies real runtime composition for manager/admin/portal roles, module ON/OFF behavior, organization switching, recursive ordering normalization and one effective module snapshot per render.

Both checks are executed by `.github/workflows/diagnostic.yml` in the `Frontend interface architecture` job section.

## Definition of Done

WEB V0.5 is complete when:

- core navigation contains no Sales, Property or Diagnostic-specific entries;
- Domain manifests declare their Web navigation services through the generic extension registry;
- workspace and portal navigation are composed from current organization effective module state;
- disabled modules disappear from every relevant shell surface;
- role-specific entries remain role-aware;
- tenant switches are reflected on the next render without stale cached state;
- each navigation render uses one effective module snapshot;
- ordering metadata does not leak into the view contract;
- route authorization remains independent from navigation visibility;
- architecture and runtime regression checks cover these guarantees.

## WEB V0.5 closure

The closure pass keeps the existing architecture and removes the remaining ambiguity around runtime consistency:

- navigation now consumes one `OrganizationModuleSnapshot` per render instead of repeatedly invoking `ActiveModuleResolver::isEnabled()` for individual contributors;
- architecture checks now enforce the generic `WEB_NAVIGATION` extension registry and manifest declarations;
- runtime tests now enforce one snapshot read per workspace/portal render and verify switching away from and back to an organization;
- documentation now describes the current manifest-driven extension model rather than the earlier explicit aggregate wiring.
