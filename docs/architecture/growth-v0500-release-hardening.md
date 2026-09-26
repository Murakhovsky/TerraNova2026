# Growth V0.50 — Release Hardening

V0.50 freezes the Growth V0.x feature surface and hardens the current implementation before Production Candidate work. No new growth concept is introduced here. The point is less glamorous and more useful: make the thing survive retries, crashes, malformed providers and humans clicking twice.

## Release gate

The V0.50 gate covers:

- architecture gates and the complete Growth migration chain;
- tenant isolation on every Market Discovery persistence path;
- security at external provider boundaries and credential redaction;
- idempotency before external side effects;
- retries and crash recovery;
- observability for accepted and rejected provider records;
- regression coverage from V0.1 through the COS-for-COS V0.49 golden path;
- documentation truth sync with the executable runtime.

## Market Discovery recovery model

A discovery run now has an explicit database lease:

```text
idempotency receipt
    ↓
durable run row
    ↓
acquire lease token + expiry
    ↓
provider I/O
    ↓
Account / ICP processing
    ↓
complete run with the same lease token
```

Only one live worker may own a running discovery attempt. A concurrent replay sees the running row but cannot acquire a non-expired lease and returns it as `in_progress` instead of performing duplicate provider I/O. After an expired run lease, a retry may acquire a new lease and resume the same durable run. Completion requires the active lease token, so a stale worker cannot overwrite a newer recovery attempt.

The lease is an execution guard, not business state. The stable idempotency receipt still identifies the logical run.

## Partial provider batches

Provider parsing no longer silently drops malformed rows. A batch carries accepted items, rejected count and bounded error summaries. The run's collected count is the total observed provider count.

A **partial run does not advance the Universe cursor**. Successful records are safe to replay because Account discovery, snapshots, ICP matching, memberships and Candidate materialization use stable idempotency keys. The next run retries the same provider page; already accepted records replay while previously rejected or transiently failed records get another chance.

A completed run advances to the provider's next cursor. A failed run keeps the current cursor. All terminal states update runtime timestamps and emit run/audit evidence including whether the run was resumed and whether the cursor advanced.

## Security

The first Market source continues to require HTTPS, pins the resolved public IPv4 address, rejects private/reserved/localhost targets, disables redirects, bounds response size and uses the platform Credential Vault. Provider credential material is never stored in Growth Events, Audit, Market run rows, or operator projections.

The source now also rejects a provider response that exceeds the requested page size instead of quietly truncating it. Malformed rows are summarized without persisting raw provider payloads.

## Tenant isolation

Universe, run and membership reads/writes remain scoped by `organization_id`. HTTP controllers obtain organization identity from `TenantContext`; request payloads do not choose the tenant. Scheduler discovery enumerates enabled Universes globally only to obtain tenant + Universe identifiers, then re-enters the tenant-scoped application boundary and checks module enablement.

## Regression and truth

The process workflow runs every Growth architecture/unit generation through V0.50 and lints the V0.48–V0.50 market/closed-loop additions. The V0.49 COS-for-COS flow remains the release golden path:

```text
Market → Account → Signal → WHY NOW → Opportunity → Committee → Outreach → Reply → Route → Sales → Outcome → Learning
```

V0.50 is ready to move to Production Candidate work only after the pull-request CI is green. There is no “probably fine” release state. Computers are irritatingly literal about that sort of optimism.
