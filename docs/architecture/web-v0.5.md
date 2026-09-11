# WEB V0.5 — Module-aware Navigation

## Purpose

WEB V0.5 makes the shared Web shell reflect the effective module state of the current organization.

The rule is simple:

```text
OrganizationContext
       +
ActiveModuleResolver
       +
Web-owned module navigation contributors
       ↓
ModuleAwareNavigationService
       ↓
workspace / portal navigation
```

A module that is disabled for the current organization must disappear from navigation without changing the deployed PHP code or rebuilding frontend assets.

## Ownership boundary

Kernel remains UI-framework neutral.

Module manifests and `Kernel\Module\ModuleContributions` do not declare menu items, Web component paths or navigation services. Web-specific extension contracts live under `Interfaces\Web\Navigation`.

The Web composition root registers the locally deployed UI adapters:

- `SalesNavigationContributor`;
- `PropertyNavigationContributor`;
- `DiagnosticNavigationContributor`.

This mirrors the backend modular-monolith model without teaching Kernel about presentation concerns.

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

Contributors use ordering metadata internally. `ModuleAwareNavigationService` removes that metadata before returning the view contract.

## Tenant behavior

`ModuleAwareNavigationService` resolves `OrganizationContextInterface::id()` every time navigation is built and checks each contributor through `ActiveModuleResolver::isEnabled()`.

Therefore:

- switching the active organization changes the visible module navigation on the next render;
- disabled Sales removes Sales and Clients;
- disabled Property removes Property workspace and portal entries;
- disabled Diagnostics removes the Diagnostics COS child;
- one organization's module state cannot leak into another organization's navigation.

The service does not cache organization identity.

## Security boundary

Navigation hiding is not authorization.

Kernel V0.8.6/V0.8.7 already enforce module access on contributed routes per request. WEB V0.5 is the presentation projection of the same effective module state, not a substitute for backend guards.

## Extension rule

A future Web-capable Domain adds a Web-owned `ModuleNavigationContributorInterface` adapter and registers it in `WebApplicationServices`.

Do not add Web navigation fields to Kernel manifests merely to avoid one composition-root registration. Deployment-time UI adapters are intentionally explicit; tenant ON/OFF remains dynamic.

Future UI extension points such as dashboard widgets, global search or command palette are out of scope for WEB V0.5 and should receive their own contracts only when a real use case requires them.
