# Native WebSocket transport (experimental, disabled)

Generic TLS-only RFC6455 transport created for streaming worker integration. Provides TLS verification, handshake validation, masked outbound frames, bounded inbound frames, message fragmentation, ping reply, close detection, and callback delivery to a provider decoder.

**Not production ready.** There is no provider channel adapter, endpoint allowlist, per-provider heartbeat scheduler, read timeout/reconnect policy integration, raw event ingestion wiring or executable CLI command. Public URL hostname must come from a *separate trusted allowlist*, never tenant-supplied input. Frame handling must undergo protocol/interoperability tests (including partial reads/writes and all provider control frames). Current implementation handles text frames, not binary compression. Worker feature flag remains OFF.

Do not describe this infrastructure class as an active Binance/Bybit/Kraken/Massive connector.
