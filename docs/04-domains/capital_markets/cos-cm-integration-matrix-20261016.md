---
title: Інтеграція COS Capital Markets
description: Узгодження пулу інструментів, потокових котирувань, глибини ринку та перевірок готовності після Федерації COS.
status: draft
---

# Інтеграція COS Capital Markets із федеративною архітектурою: відповідальність та приймання

Status: **DRAFT / NO MERGE / NO DEPLOY**. Canonical base `main@a75c81d79ca2741a07081299672045ae16bbbafc` after #346 Federation and #370 Instrument Pool.

## Зафіксовані джерела та рішення щодо перетинів

| Input | Pinned SHA | Actual treatment |
| --- | --- | --- |
| #361 source lease + Pool approval | `f20c83e5d088ebce69c2d0f2e582879aad04b8a5` | 124 of 145 changed paths byte-identical in baseline main; preserve main's 21 newer shared paths. Native PDO `:canonical_symbol` fix already present in main. |
| #367 venue-specific L2 reconstruction | `25884fb77d7ddeaeb3575538276a018c99a615a6` | 95 of 98 changed paths already identical; preserve newer main's Runtime, Compose and Symfony DI. |
| #374 streaming, proof, monitoring, replay | `73c55d36a098fa02bd8c78a3bfbd37930c93661d` | Forward-port all 81 changed paths; 76 no-conflict, 5 content-based three-way reconciliation. |
| Readiness branch | `33b1d45d77178e3069609ed4e400f6c38220846b` | Bring only E4 issuer linkage, separate source/venue/Massive scopes, reviewed digest logic, NOT old controllers/tables or weaker soak. |

Five deliberate code/content reconciliations were `.github/workflows/ci.yml`, `.github/workflows/runtime.yml`, `app/Domains/CapitalMarkets/module.php`, `docs/12-reference/module-capabilities.md`, `symfony/config/services.yaml`. Preserve Federation service wiring, Runtime and all migrations. The generated database reference was separately reconciled after adding the scope migration.

## Єдина карта відповідальності

| Operation | Sole authority / store |
| --- | --- |
| Instrument identity, mappings and desired subscriptions | Existing `tn_capital_market_instruments`, source registry, mappings, relationships, `tn_capital_market_subscriptions` |
| Source lease, reconnect and live writer | `MysqlStreamingWorkerLease` + source guard + `StreamingFencedIngestionGateway`; at most one owned WS source |
| Venue L2 snapshot / delta | Existing Binance/Bybit/Kraken recovery processors; verified whole-book to `L2SourceFencedSession` |
| Live provider proof | Current epoch + source-fenced provider ACK + canonical quality/trust, never synthetic quote receipts |
| Production approval | `cos:capital-markets:pool:production` only, `tn_capital_market_pool_external_evidence` only |
| Issuer E4 | Latest independently reviewed issuer document digest AND ACTIVE, effective canonical relationship with six E4 claims |
| Replay / monitoring / incidents | Read-only evidence or separate diagnostics; no trading, no second Action |

## Міграції та порядок введення в дію

Keep module-owned unique ordered SQL files `20261015_000144` (single evidence/controller), `000145` (instrument trust), `000146` (provider ops), `000147` (derivatives), `000148` (replay), `20261016_000149` (additive evidence kind CHECK only). No readiness duplicate `000144`, `tn_capital_market_production_attestations`, or duplicate CLI.

Streaming defaults to OFF. `COS_CM_POOL_PRODUCTION_ARMED=0`; production L2 requires `COS_CM_L2_FENCED_ENABLED=1`. Isolated direct-write QA requires separate flag and is rejected in production. Neither code merge nor CI activates tenants, providers, trades, or new workers.

## Контрольний перелік приймання

- [x] Reconcile original branch file SHA and overlap matrix against post-Federation main.
- [x] Keep one owned pool and streaming writer, single production controller, no migration collision.
- [x] Forward-port source-owned code and add exact E4/latest-digest/venue-scope guards.
- [x] Keep ≥24h real soak, ≥250 independent LIVE BBO, ≥50 LIVE L2, ≥24 hourly and ≥276 five-minute buckets, independent stream trust, failover and quality checks.
- [ ] Green final-sha CI, Runtime, Documentation, Frontend, L2, fresh-and-upgrade MySQL and security review.
- [ ] Verified external licensing and provider-side source/venue entitlement; independent issuer rights review.
- [ ] Isolated no-trading canary and 24h genuine provider receipts, quotas, leases, network fault injection, process metrics and independently signed operator approval.

**No merge/deploy/live promotion** unless every outstanding item has independently persisted evidence. Historic CI fixture data, uploaded spreadsheet symbols, human document attestations and dry-run plans never count as provider-authenticated continuous production data.
