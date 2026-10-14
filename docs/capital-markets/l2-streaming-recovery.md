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

## Verification checkpoint (2026-10-14)

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
