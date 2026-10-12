---
title: Production streaming implementation status
status: draft
updated: 2026-10-12
kind: engineering
---

# CM-PRODUCTION-STREAMING implementation status

Source of truth: GitHub issue #360 and draft PR #361.

## Delivered as code, not yet integrated
- Fail-closed StreamingRecoveryGate and session generation fencing.
- StreamingSubscriptionReconciler for desired versus acknowledged subscriptions.
- StreamingHeartbeatWatchdog for independent protocol liveness and market-data activity.
- StreamingSubscriptionPlanner reading current tenant-scoped persistent subscriptions.
- StreamingSessionCoordinator composing these components without opening sockets.
- Standalone unit and architecture test scripts (not yet verified as executed).

## Not delivered
No live WebSocket transport, provider codec/reconstruction, persistent lease, process supervisor, durable recovery checkpoints, MarketState invalidation, data-plane persistence integration, health/readiness endpoint, production monitoring, soak evidence or connected provider tests. The coordinator's trusted() result cannot authorize MarketState publication without integration and provider-specific protocol evidence.

## Deployment safeguard
`capital_markets.market_data.streaming.enabled` remains OFF. REST/polling is unchanged. Do not promote streaming to production or remove this disclaimer until verified worker integration and full protocol QA exist.
