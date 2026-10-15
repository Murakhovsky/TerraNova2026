---
title: Binance Spot multi-symbol WebSocket BBO pilot
description: Safe bounded subscription batch with per-symbol BBO trust fencing
status: draft
kind: engineering
updated: 2026-10-15
---

# Binance Spot multi-symbol BBO pilot

This opt-in pilot consumes 2..100 active **BBO-only** Binance Spot subscriptions
for exactly one tenant-scoped MarketSource and venue, using one socket, one source
lease and one durable fencing token. It is **not** a horizontally scaled streaming
service and must not be connected to live trading.

## Preconditions

- All instruments, venue mappings, source and active BBO subscriptions must
  already exist in the canonical Capital Markets repository; unmapped, repeated
  or cross-venue instruments block startup.
- Domain, Market Data, Binance and Streaming feature flags must all be enabled,
  and environment variable `COS_CM_STREAMING_BINANCE_SPOT_BATCH_PILOT=1` supplied.
- A second shard (including the existing single-symbol pilot) for this source
  cannot acquire the source-exclusive lease. The expired lease sweeper supports
  `binance-spot-multi-bbo` as a fail-closed recovery candidate.

## Run (controlled)

```bash
COS_CM_STREAMING_BINANCE_SPOT_BATCH_PILOT=1 php symfony/bin/console \
  cos:capital-markets:market-data:stream:binance-spot-batch ORG SOURCE --seconds=60
```

This is a public Binance Spot `@bookTicker` feed. Initial batch ACK
is required before publishing any symbol. For each symbol, last received time and
monotonic (non-contiguous) update ID are separate. Repeated update IDs cannot
republish. A stale ticker gets a soft per-instrument trust revocation and may
recover only on a newer valid full BBO. A malformed, crossed or sequence-rollback
ticker needs isolated unsubscribe ACK, subscribe ACK and a new full BBO before
recovery. Healthy symbols remain live. Source-wide socket, auth, lease or
unknown-symbol failures still require whole-session fail-closed recovery.

## Not yet production accepted

No live WebSocket+MySQL evidence was collected in this increment; neither 72-hour
nor 7-day soak, chaos, provider-wide discovery, rate-limit adaptation or operator
incident screens are complete. Streaming remains OFF by default and no live
trading is allowed. Enabling this pilot is a manual controlled canary action.
