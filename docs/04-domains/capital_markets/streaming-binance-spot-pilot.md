# Binance Spot streaming BBO pilot (CM-STREAM-BINANCE)

This PR adds a **feature-gated single-symbol** transport-to-ingestion integration for Binance Spot public WebSocket @bookTicker. It is *not* a production rollout nor L2 orderbook reconstruction.

## Safe invocation

Prerequisites:
- Apply the registered additive SQL worker lease migration after review.
- Configure the existing enabled Binance Spot REST source, exactly one ACTIVE BBO subscription, and its venue/instrument mapping.
- Set the domain, MarketData, MarketDataBinance and MarketDataStreaming feature flags for the organization, **and** COS_CM_STREAMING_BINANCE_SPOT_PILOT=1 on the worker host.
- Never set the pilot env toggle on a production service without security and QA acceptance.

Example:

```bash
COS_CM_STREAMING_BINANCE_SPOT_PILOT=1 php symfony/bin/console cos:capital-markets:market-data:stream:binance-spot ORG_ID SOURCE_ID --seconds=60
```

Runtime flow: existing source/subscription repository -> exactly one BBO target -> TLS public Binance WS -> provider subscribe and ACK -> strictly increasing BBO update ID and verified decimal strings -> RawMarketEvent with original WS payload and normalized parser evidence -> leased transaction + health + MarketDataIngestionService -> canonical state.

The provider's bookTicker update ID is **not** a contiguous depth sequence, so it is recorded as evidence but not used as L2 continuity. Never compare BBO IDs with depth snapshot IDs to claim orderbook recovery.

## Known blockers / do not promote yet

- No test evidence from real Binance WebSocket or MySQL environment; PHP fixture script is committed, not proven run.
- Command supports just one symbol per source and does not support L2, trades, derivatives, Kraken, Bybit or Massive.
- It uses a fixed source-scoped shard, no HA worker supervisor, no reconnect loop/backoff and no per-symbol lease checkpoint.
- Existing REST polling may still race with market-state updates from the same source and may require source-mode governance.
- Real stale detection, expired lease sweeper, protocol interoperability and error reporting still need integration tests.
- The CLI has a bounded duration by default. SIGTERM handling requires PHP pcntl.
- Binance server bookTicker messages do not include a trusted exchange timestamp: use received_at and display provenance accordingly.
- Operator UI, metrics, data licensing and authenticated market feed checks remain unresolved.

Never enable real trading. Default streaming flag remains OFF.
