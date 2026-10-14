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

### Health semantics

`L2HealthEvaluator` maps supervisor and publication metrics to
`STOPPED`, `RECOVERING`, `DEGRADED`, `STALE` or `HEALTHY`.
Only a trusted stream with a sufficiently recent publication is healthy.
`DEGRADED` and `STALE` raise an alert flag. The evaluator does not
send notifications itself; alert delivery and persistence remain an
integration gate. No simulated market data should be published as live
trusted data during recovery.

## Symfony CLI integration

Canonical entrypoint: `symfony/bin/console cos:capital-markets:l2:stream`.
Run inside the Symfony application directory.

```bash
FOS_L2_ENABLED=0 php bin/console cos:capital-markets:l2:stream --organization=ORG --source=source:REGISTERED_L2 --venue=BINANCE_SPOT --symbol=BTCUSDT --base=BTC --quote=USDT --dry-run
FOS_L2_ENABLED=1 php bin/console cos:capital-markets:l2:stream --organization=ORG --source=source:REGISTERED_L2 --venue=BINANCE_SPOT --symbol=BTCUSDT --base=BTC --quote=USDT
```

The source must exist, be enabled, and have adapter type `l2.verified`.
The organization, instrument mapping and ingestion database must be provisioned.
Dry-run validates source and options without connecting to an exchange.
Live operation requires `FOS_L2_ENABLED=1` and the CLI pcntl extension.
The worker factory creates a new socket, processor and recovery state on each attempt.
Verified books flow through `L2VerifiedIngestionCallback` to the canonical ingestion service.
Failed ingestion aborts the connection; SIGTERM and SIGINT request shutdown.

Production process-manager installation, external exchange live acceptance and
long-duration operations testing are separate deployment gates and are not
implied by registering this command.

## Durable L2 operator alerts (2026-10-14)

CLI execution emits operational transitions only for actual stream incidents:
`DEGRADED` for connection/ingestion failures, `STALE` after a publication
staleness failure, `EXHAUSTED` when the retry budget is used up, and
`RECOVERED` only after a subsequent verified book is successfully ingested.
Deliberate SIGTERM/SIGINT shutdown does not generate a false failure alert.

The `L2AlertLifecycle` suppresses repeated transition emails within a single
process incident. It retries transient notification enqueue errors using
the **same** notification ID and payload, so the existing n8n outbox enforces
durable idempotency. Each new incident gets a distinct correlation ID.
Failure of the notification infrastructure does not force an exchange
reconnect. An enqueue failure is logged; remaining unsent alerts are tracked
in `alerts.pending` and produce an unsuccessful command exit when detected.
This is per-process incident deduplication, not global cross-process
incident coordination.

Enable alerts explicitly in the environment provided to the Symfony PHP
process (Docker Compose forwards these variables to its application services):

```dotenv
FOS_L2_ENABLED=1
FOS_L2_ALERT_ENABLED=1
FOS_L2_ALERT_EMAIL=operator@example.com
FOS_L2_ALERT_LOCALE=uk
```

With `FOS_L2_ALERT_ENABLED=1`, a valid alert email address is mandatory;
supported locales are `uk` and `en`. With alerts disabled, the market
data command continues but **no operator email is queued**. The CLI
`--dry-run` preflight validates the settings and reports `alerts_enabled`.

Operational delivery path:

```text
L2ManagedStreamRunner → L2AlertLifecycle → L2AlertDelivery
  → Platform Notification Dispatcher → N8nEmailNotificationChannel
  → tn_integration_outbox (notification.email)
  → integration-worker / cos:integration:n8n:process
  → configured N8N_OUTBOUND_URL → n8n provider email workflow
```

A successful alert enqueue means durable `QUEUED` acceptance, **not**
that the email has reached the operator. Confirm `integration-worker`
is running, `N8N_OUTBOUND_URL` / `N8N_OUTBOUND_SECRET` are configured,
and the target n8n workflow processes `notification.email` envelopes.
Inspect `tn_integration_outbox` and `cos_notification_deliveries`
to diagnose failures. Live provider email delivery is not exercised
by the fake-dispatcher smoke tests.
