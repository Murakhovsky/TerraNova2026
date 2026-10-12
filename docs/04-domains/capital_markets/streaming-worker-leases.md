# Streaming worker lease (implementation slice)

The MySQL lease is intended for a future supervised worker.

- Lease key: organization, MarketSourceId, logical shard.
- Acquiring uses transaction + row lock and increments fencing token even if the same owner reacquires.
- Renew succeeds only while the same owner/token is unexpired. Losing ownership must close the session and revoke its trust.
- Expiry is calculated in database UTC time; no local-clock trust.
- Fencing token MUST be validated at the current-state durable write boundary, not just before opening a socket. This boundary is **not yet integrated**.
- An owner lease does not verify a provider event or assert market-data integrity.

**Safety:** Do not activate streaming. No long-running WebSocket runtime or provider integration is shipped in this slice. Apply the additive SQL migration via the standard module migration registry once reviewed.
