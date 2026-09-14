# ADR-001: Games, Economy and legacy Users boundary

Date: 2026-08-27  
Status: accepted, amended 2026-08-28

## Context

The main web bootstrap registered `Games`, `Economy` and `Users` modules and therefore exposed Phalcon generic module routes. The repository has no supported main-site flow or internal caller for those routes. The Economy controller references incomplete models and permits balance mutations without an application authorization boundary. Games already has a separate `public/games.php` / `app/bootstrap_games.php` entrypoint. Users has no web controller and is used only by legacy Telegram/model compatibility code.

## Decision

- Games and its dedicated `public/games.php` / `app/bootstrap_games.php` entrypoints are removed. `/games` remains an explicit HTTP 410 boundary.
- Economy is removed because the repository has no supported runtime flow and the deployed schema has no wallet/transaction tables. `/economy` remains an explicit HTTP 410 boundary.
- Users is not registered as a main web module. Website identity uses Domain contracts and canonical Infrastructure adapters; Telegram identity persistence lives under `Infrastructure/Persistence/Phalcon/Identity`.
- `app/modules`, the `Modules\\` PSR-4 mapping and all module registrations are removed.

## Consequences

The main HTTP application does not expose `/economy/*`, `/games/*`, or `/users/*` generic module routes. Games cannot be started as a separate deployable from this repository. Any future Economy feature requires an explicit schema, authenticated Interface and Domain-owned contract; generic module routing must not return.
