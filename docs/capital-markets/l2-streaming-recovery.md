# L2 streaming recovery: current implementation

## Scope

Supported venue identifiers: BINANCE_SPOT, BINANCE_FUTURES, BYBIT_SPOT,
BYBIT_LINEAR, KRAKEN_SPOT. The package is a public market-data ingestion
foundation, **not** an order-execution connector.

## Components

1. L2SubscriptionConfig and L2SubscriptionEncoder choose public endpoints
   and subscription messages.
2. L2WebSocketTransport implements TLS WebSocket upgrade, client masking,
   fragmented text messages, ping/pong and bounded frame reads.
3. L2FrameDispatch enforces frame-size limits, separates control messages
   and validates venue-specific payloads.
4. L2DomainBookFactory maps decimal strings to domain MarketOrderBook values.
5. BinanceL2StreamProcessor combines diff-depth buffering with REST snapshots
   and BinanceL2RecoveryCoordinator sequence checks.
6. BybitL2StreamProcessor handles V5 snapshots, updates and restart snapshots.
7. KrakenL2StreamProcessor delegates CRC verification to KrakenL2BookRecovery.
8. L2StreamWorker handles one bounded connection and publishes verified books.
9. L2RetryRunner offers a bounded retry budget. A caller must create a new
   recovery epoch per retry. Publish callbacks must be idempotent.
10. L2StreamHealth and L2StreamSupervisor expose staleness and trust state.

## Local checks

Run individual PHP smoke tests from the repository root:

```bash
php tests/CapitalMarkets/L2SubscriptionConfigSmoke.php
php tests/CapitalMarkets/L2FrameDispatchSmoke.php
php tests/CapitalMarkets/L2DomainBookFactorySmoke.php
php tests/CapitalMarkets/BinanceL2StreamProcessorSmoke.php
php tests/CapitalMarkets/BybitL2StreamProcessorSmoke.php
php tests/CapitalMarkets/L2StreamWorkerSmoke.php
php tests/CapitalMarkets/L2RetryRunnerSmoke.php
php tests/CapitalMarkets/L2WebSocketTransportSmoke.php
```

## Remaining production gates

- Run the smoke and integration suites in the actual application runtime.
- Verify exchange-specific snapshots and deltas against live traffic and
  recorded fixtures, including Kraken checksum and Binance futures pu.
- Add long-lived process orchestration, health reporting and alerting.
- Validate Binance snapshot bridging under concurrent WebSocket buffering:
  the current single-threaded worker fetches after the first received frame
  and may require reconnect when the REST snapshot outruns buffered deltas.
- Integrate trusted-book publications with the existing MarketData ingestion
  contract and downstream data quality pipeline.
- Implement operational feature flags, graceful shutdown and safe replay.
- Test production failure conditions: TLS failures, stale connections,
  sequence gaps, API throttling, malformed frames and reconnect exhaustion.

Do not label the package production-ready before these gates pass.

## Historical verification checkpoint (2026-10-14)

The current feature branch includes fail-closed subscription acknowledgement,
a worker budget check for incomplete Binance recovery, a verified ingestion
callback, and a publication freshness watchdog. The new Kraken processor
fixture tests a valid snapshot, a valid delta and CRC mismatch invalidation.

**Important:** These are source changes and test definitions. No passing
GitHub Actions job or complete local application suite has been observed
during this checkpoint. The GitHub connector's commit workflow lookup
returned no PR-triggered runs and the combined commit status returned no
statuses. Neither result proves that the workflow passed or failed.

Remaining acceptance gates:
1. Execute the complete L2 smoke suite and fix all failures.
2. Exercise a full worker -> verified ingestion -> canonical state flow
   using the application normalizer and persistence services.
3. Verify real Binance spot/futures, Bybit spot/linear and Kraken v2 feeds,
   including reconnect and venue-side subscription rejection.
4. Integrate lifecycle supervision, operational metrics and alerts into
   a managed worker process with graceful shutdown.
5. Validate stale-data revocation and replay idempotency across process
   restarts. Do not merge or deploy as production-ready before these gates.

## Exchange adapter boundary (2026-10-14)

The pure Domain owns `L2RecoverySession`, `MarketOrderBook`, and
`OrderBookRebuilder`. It must not depend on any venue identifier or wire
protocol. `L2RecoverySession::bufferedPayloads()` exposes generic recovery
payloads; the Binance stream processor interprets `U/u` fields in
Infrastructure.

The following exchange-specific components live under
`Infrastructure/MarketData/L2` and must not be moved back into Domain:
`BinanceL2SequenceValidator`, `BinanceL2RecoveryCoordinator`,
`BybitL2RecoveryCoordinator`, `KrakenL2Checksum`, and
`KrakenL2BookRecovery`. Stream processors and smoke fixtures import their
Infrastructure namespaces. The existing Capital Markets architecture gate
enforces the absence of venue-specific names in the pure Domain.

### Observed CI evidence

PR #367 runs `L2 Recovery Smoke` with a MySQL 8.4 service. Commit
`d49d5ae158835411c01cf4d82f398639511f8179` has passing
`l2-smoke` and `l2-mysql-ingestion` jobs in workflow run
`38553506247`. This verifies ingestion persistence but does not prove
live-venue operational readiness. Runtime and other repository checks must
be checked independently before merging.

## Managed runtime primitives (2026-10-14)

`L2ManagedStreamRunner` coordinates bounded retries and recovery epochs;
`L2ManagedWorker` requires a new `L2StreamWorker` instance per attempt.
`L2ShutdownSignal` provides a stop token for SIGTERM/SIGINT in a CLI process,
and `L2OperationalMetrics` records reconnects, publications, failures and
staleness. The runner exposes diagnostics including an optional metrics snapshot.

`L2WorkerRuntimeConfig::fromEnvironment()` validates a stream's explicit
venue, symbol, depth, message budget, retry limit, stale threshold and enable
flag. The enable flag is fail-closed: only `0` or `1` are accepted, and
the caller must check `enabled` before starting the worker. Required keys:
`FOS_L2_VENUE`, `FOS_L2_SYMBOL`, `FOS_L2_ENABLED`. Optional keys:
`FOS_L2_DEPTH` (50), `FOS_L2_MESSAGE_BUDGET` (1000),
`FOS_L2_MAX_ATTEMPTS` (20), `FOS_L2_STALE_SECONDS` (30).

These are **library primitives**, not a deployed process supervisor or a
fully wired Symfony console command. The remaining gates are process wiring,
persisted health/alerts, live venue capture and replay/restart verification.
