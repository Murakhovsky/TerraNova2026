# Sales EPIC 4 Runbook — Historical Sales Intelligence

Release target: Sales V0.8.6. This runbook covers the historical stage/owner projections and the Director Workspace introduced in V0.8.1–V0.8.5.

## Invariants

- Every operation is tenant-scoped by `organization_id`.
- `COMPLETE` and `PARTIAL` history can carry real timestamps. `ESTIMATED` current-state seeds never become exact facts merely because a rebuild ran.
- Manager performance is attributed only to owner-at-time facts. Current `assigned_user_id` is not applied retroactively.
- Money is returned by currency. Do not create a mixed-currency grand total without an explicit FX layer.
- Forecast is a current snapshot. `/api/sales/director/overview` does not offer historical `as_of` reconstruction.

## Health check

```bash
docker compose -f docker-compose.symfony.yml exec php php bin/console cos:sales:history:health --organization=<organization_id>
```

`HEALTHY` means current Deal state has one matching open stage projection and, where assigned, one matching open owner projection. `ESTIMATED` rows can still exist in a healthy projection; they indicate limited historical precision, not corruption.

`DEGRADED` means at least one missing, mismatched, or duplicate open projection exists. Rebuild is recommended.

## Rebuild

```bash
docker compose -f docker-compose.symfony.yml exec php php bin/console cos:sales:history:rebuild --organization=<organization_id>
```

The command clears and deterministically rebuilds stage and owner projections from canonical tenant events, then adds only explicit `ESTIMATED` current-state seeds where canonical history is unavailable. It finishes by running the health check.

Rebuilds are tenant-scoped and idempotent. If the process is interrupted after one projection has completed, rerun the complete command for the same tenant. Do not manually copy current Deal timestamps into history.

## Director verification

Web: `/sales/director`

API: `/api/sales/director/overview?history_days=30&forecast_days=30`

Verify:

1. monetary rows remain separated by currency;
2. closed win rate and created-cohort win rate remain distinct;
3. forecast coverage is visible rather than silently imputing missing close dates/probabilities;
4. manager attribution coverage is visible;
5. risk rows expose reason codes/evidence;
6. historical time metrics exclude `ESTIMATED` entry timestamps.

## Recovery order

1. Stop any bulk importer that is actively mutating Deals for the affected tenant.
2. Run `sales historyHealth` and save the JSON output with the incident.
3. Run `sales rebuildHistory` for that tenant.
4. Run `sales historyHealth` again. Do not accept `DEGRADED` as repaired.
5. Open Director Workspace and compare counts/currency buckets with current Deals.
6. Resume import/automation traffic.

Never rebuild by deleting canonical `cos_events`. Projection tables are disposable; canonical events are not.
