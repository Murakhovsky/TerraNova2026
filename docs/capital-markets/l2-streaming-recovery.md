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


## Supervised CLI and live-venue acceptance (2026-10-14)

**Verified code boundary:** `symfony/src/Command/CapitalMarketsL2StreamCommand.php`
registers `cos:capital-markets:l2:stream` through Symfony `#[AsCommand]`
and injects `MarketDataIngestionInterface`, registered sources, an L2
worker factory and Platform Notification alerts. The optional Compose
`l2-stream` service now provides real process supervision (PID 1 init,
SIGTERM, restart, database dependencies) under profile `l2`.
`L2ManagedWorker` still performs in-process reconnect with fresh epochs.
They are complementary: **a process restart is not a recovery of an old trusted book**.

### Enable the supervised worker (no auto-enablement)

Populate the following settings in the Docker Compose environment file:

```dotenv
FOS_L2_ENABLED=1
FOS_L2_ORGANIZATION=default
FOS_L2_SOURCE_ID=source:registered-binance-btc
FOS_L2_VENUE=BINANCE_SPOT
FOS_L2_SYMBOL=BTCUSDT
FOS_L2_BASE=BTC
FOS_L2_QUOTE=USDT
FOS_L2_DEPTH=50
FOS_L2_MESSAGE_BUDGET=1000000
FOS_L2_MAX_ATTEMPTS=20
FOS_L2_STALE_SECONDS=30
```

The example source ID is a **placeholder**, not a provisioned source.
First register an enabled `l2.verified` source in the correct organization,
with a supported instrument and venue mapping. Production ingestion cannot
start successfully against an unregistered source. No API keys are required
for the selected public order-book endpoints.

```bash
docker compose --env-file .env.docker --profile l2 config --quiet
docker compose --env-file .env.docker --profile l2 up -d --build l2-stream
docker compose --env-file .env.docker --profile l2 ps l2-stream
docker compose --env-file .env.docker --profile l2 logs -f l2-stream
docker compose --env-file .env.docker --profile l2 stop l2-stream
```

The dedicated service attaches to `frontend` for outgoing TLS exchange
connections and `backend` for private MySQL. The service is not started
in the default Compose profile and `FOS_L2_ENABLED` defaults to `0`.
Compose restarts the finite-run CLI when it exits, while the CLI handles
individual connection retries internally. SIGTERM triggers graceful stop.
The `Runtime` workflow verifies that the command is *actually registered*
in the Symfony container and rejects a dry-run with a nonexistent source.

### Real public WebSocket reconnect acceptance

`tests/live/l2_public_reconnect.php` uses production L2 parsers,
checksums, subscription acknowledgement and REST snapshot recovery.
The test opens a **real public WSS** connection for the selected venue,
accepts only a verified full book, deliberately breaks the next socket read,
requires `BACKOFF`, reconnects with a fresh session and obtains a second
verified full book before reporting PASS. It performs no orders or writes to
the production ingestion database.

The test covers `BINANCE_SPOT`, `BINANCE_FUTURES`, `BYBIT_SPOT`,
`BYBIT_LINEAR`, and `KRAKEN_SPOT`. Binance Spot uses the officially
supported WSS port 443 instead of port 9443.

```bash
composer install
FOS_L2_LIVE_TEST_ENABLED=1 timeout 120s \
  php tests/live/l2_public_reconnect.php --venue=BINANCE_SPOT
FOS_L2_LIVE_TEST_ENABLED=1 timeout 120s \
  php tests/live/l2_public_reconnect.php --venue=BYBIT_SPOT
FOS_L2_LIVE_TEST_ENABLED=1 timeout 120s \
  php tests/live/l2_public_reconnect.php --venue=KRAKEN_SPOT
```

The dedicated GitHub Actions workflow `L2 Live Venue Reconnect`
also runs a five-venue strict matrix after changes on this feature branch
and on manual `workflow_dispatch`; evidence logs are retained as
individual artifacts. Venue failures fail the corresponding job, including
if geographic/IP access restrictions prevent testing from GitHub runners.
**Only observed PASS evidence proves external live acceptance.**
The `L2 Forced Reconnect Smoke` runs offline and separately verifies
epoch change, trust revocation, forced socket failure, close, and recovery.

Operational caveats: the current Binance WebSocket->REST bridge is
sequential (REST fetch temporarily blocks socket reads); extended
live-duration testing, multi-stream coordination and an automated
deployment to your own host remain distinct acceptance gates.

### Live-venue findings and adapter corrections (2026-10-14)

The first actual five-venue GitHub Actions matrix exposed differences not
visible in static fixture tests:

- **Bybit Spot:** PASS on real WSS with exactly two validated snapshots,
  forced socket loss, BACKOFF and fresh TRUSTED recovery.
- **Bybit Linear:** sends a data frame before its control acknowledgement.
  The worker now validates such frames internally but never publishes an
  order book before the subscription is acknowledged. This is bounded to
  32 pre-ACK data frames.
- **Kraken Spot:** v2 JSON book levels can be JSON numeric literals, rather
  than the strings used by Bybit/Binance. The Kraken infrastructure adapter
  preserves exact numeric lexemes before decoding to avoid float rounding
  and checksum corruption; exponent syntax is rejected, never rounded.
- **Binance Spot:** uses Binance's official unauthenticated market-data-only
  WSS and REST hosts `data-stream.binance.vision` and
  `data-api.binance.vision`.
- **Binance Futures:** upgraded to the post-April-2026 public depth stream
  path `fstream.binance.com/public/ws/...`. REST snapshots still require
  the Binance futures REST endpoint. A provider HTTP 403/451 due to runner
  geolocation remains an external acceptance failure, not a passing test.

Re-run the strict `L2 Live Venue Reconnect` workflow after these updates.
An offline smoke pass is not evidence of public exchange availability.

### Binance Futures: public WS depth snapshot fallback

A strict live matrix on the GitHub-hosted runner showed valid Futures diff-depth
WebSocket traffic but REST depth returning a non-200 response before trust could
be established. The HTTPS snapshot client now reports the actual HTTP code.
Only for Futures REST status **403/451**, it may request a depth snapshot from
the independently documented *public* Binance Futures WebSocket API
`wss://ws-fapi.binance.com/ws-fapi/v1`, using method `depth`,
the configured symbol and a bound request ID. It validates response status,
request ID, sequence, prices and quantities through the same snapshot decoder.
No credentials or trading methods are used. It does **not** switch on
429 (throttling), TLS problems or invalid exchange data.
The fallback also depends on the hosting jurisdiction and may be unavailable
to CI runners. An actual live PASS is required before accepting Futures.

### Verified full live matrix, commit 87baf14 (2026-10-14)

GitHub Actions `L2 Live Venue Reconnect`, run `38629499249`, has
**5/5 passing independent real WSS jobs**:

- BINANCE_SPOT (official market-data-only host)
- BINANCE_FUTURES (public Futures WSS snapshot fallback for blocked REST)
- BYBIT_SPOT
- BYBIT_LINEAR
- KRAKEN_SPOT

Every test observed two verified full books from distinct recovery epochs,
one intentionally closed connection, supervisor BACKOFF and recovery to
TRUSTED. Results and protocol metrics are recorded per venue in workflow
job logs/artifacts. These are **public API test subscriptions only**:
no real order execution and no production database writes.
The deterministic L2 Recovery Smoke suite and MySQL ingestion pipeline have
separate acceptance jobs. The Runtime workflow additionally checks a
dry-run rejection of an unknown registered source and boots the actual
Compose-profile L2 worker image with `--help` as a non-network preflight.

Successful GitHub Actions live checks prove the five exchange endpoints were
reachable *from that GitHub runner at the run time*. They do not prove that
the operator's production deployment has the correct organization/source
records or that its server can maintain the same streams indefinitely.
