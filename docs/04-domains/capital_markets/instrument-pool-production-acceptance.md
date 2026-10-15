---
title: Приймання Instrument Pool у промислову експлуатацію
description: Контрольований пілотний запуск, добова перевірка потокових котирувань BBO/L2 у MySQL та аварійне відновлення.
status: draft
kind: operations
updated: 2026-10-15
---

# Приймання Instrument Pool до промислової експлуатації

## Що підтверджується і чого CI не доводить

Тести CI використовують фікстури. Вони **не є** доказом живих біржових потоків протягом 24 годин.
Human-reviewed source contracts/issuer documents are not provider API confirmations. Two different authorized human accounts must inspect the external document and approve it; verification of actual vendor rights takes place outside COS.

Canary and soak **enable selected initially-disabled MarketSource records**, but do not spawn WebSocket workers, set feature flags, order subscriptions or start trading. Live workers must already have a production supervisor. The LIVE phase is an acceptance status for the *read-only market-data monitoring pool*, never an order-execution approval.

## Передумови P0

- Deploy the new additive MySQL migration 20261015_000144 and verify database index maintenance capacity.
- Review the external provider data account, redistribution rights, current license profile and credential vault. Never use DELAYED Massive NBBO as LIVE.
- Approve source evidence via two independent authorized operators (instrument_pool.propose and instrument_pool.approve). Tokenized instruments additionally require issuer identity evidence and economic relationships remain separately gated.
- Register active canonical mappings. Use audited Subscription Planner; no ticker-only economic identity merging.
- Provide externally supervised native WebSocket workers with real credentials, reconnect/lease fencing, observed healthy ingestion, and an emergency kill mechanism. Existing Binance BBO batch has capacity up to 100 symbols and Massive NBBO up to 25 per batch; achieving 250 BBO / 50 L2 requires actual provider-permitted sharding, verified connection limits, CPU/RAM, bandwidth and message-rate budget. A single generic worker does not cover 300 instruments.
- For 24h continuous hourly evidence, stock quotes may not pass because US equities close overnight and on weekends. Do not pretend the 24h coverage is present; accept a separately designed session-aware validation policy only after it has been engineered and tested.

## Дії оператора

All commands use the executable:

    php symfony/bin/console cos:capital-markets:pool:production

Production actions are disabled by default. Configure COS_CM_POOL_PRODUCTION_ARMED=1 only on the designated controlled runtime and enable the separate streaming capabilities after operator review.

**Evidence creation and second-person review**

    ... attest --organization=ORG --actor=SUBMITTER --kind=SOURCE_ENTITLEMENT --subject=SOURCE_ID --document-url=https://provider.example/contract.pdf --sha256=HASH64 --expires="2026-11-10 10:00:00"
    ... review --organization=ORG --actor=REVIEWER --evidence-id=ID --decision=approve

For tokenized securities, create and independently review ISSUER_IDENTITY using the canonical instrument ID as subject. The URL must be a permanent HTTPS document URL with no credential in the query, username or password. Proof expires in at most 30 days.

**Пілотний запуск**

    ... canary --organization=ORG --actor=OPERATOR --sources=SOURCE_A --confirm=CANARY_INSTRUMENT_POOL

One source must have been disabled and correctly attested. Independently start its guarded live WebSocket worker. The following stage cannot start until recent LIVE canonical and quality events, healthy lease and attestation exist.

**Контрольоване добове випробування**

    ... soak --organization=ORG --actor=OPERATOR --sources=SOURCE_A,SOURCE_B,SOURCE_C --confirm=SOAK_INSTRUMENT_POOL
    ... status --organization=ORG

Up to 64 distinct sources can be named per rollout; verify real connection limits and host capacity before activating a large set. All newly included sources must originally be disabled and independently attested; the original canary source stays included. Start separately supervised workers and preserve them for a full 24h following this stage. The system reads tenant-scoped real MySQL canonical + quality evidence, every hourly bucket of the last 24h, LIVE mode, recent events within 300 seconds, active fenced source leases, independently reviewed entitlement/issuer, trusted quality score >=80 for every sample, and all source coverage. It needs at least 250 unique trusted BBO and 50 unique trusted L2 streams. No threshold reduction is supported by the production CLI.

**Незалежне приймання та активація**

    ... accept --organization=ORG --actor=REVIEWER --confirm=ACCEPT_INSTRUMENT_POOL
    ... promote --organization=ORG --actor=REVIEWER --confirm=PROMOTE_INSTRUMENT_POOL

Acceptance requires a different operator from rollout initiator and 24h actual elapsed time. Promotion must happen within 10 minutes of acceptance, re-checks all LIVE evidence, and does not enable live trading.

**Безперервний контроль та аварійний відкат**

Configure the following as a monitored externally scheduled job every 5 minutes using a dedicated authorized service operator. If live health fails, watchdog automatically executes tenant-specific emergency rollback:

    ... watchdog --organization=ORG --actor=SERVICE_OPERATOR

Manual emergency rollback:

    ... rollback --organization=ORG --actor=OPERATOR --confirm=ROLLBACK_INSTRUMENT_POOL

Rollback disables only sources initially disabled for this rollout, fences leases and invalidates trusted state and subscriptions. Workers managed by external process supervisors must also be stopped on rollback. Legacy unleased L2 collectors MUST be explicitly terminated; the DB change alone does not prove process shutdown.

## Зовнішні умови приймання

An actual production release requires: confirmed vendor rights and rate quotas, verified primary issuer documents, real enabled source IDs/tenant actor IDs, live supervisor/PID/lease evidence, successful MySQL 24h output, 250 BBO / 50 L2 simultaneous healthy monitored symbols, reconnect fault tests, sustained CPU/RAM and queue lag checks, incident delivery checks, and second-person approval. These cannot be fabricated by CI and are intentionally not self-attested as passed.
