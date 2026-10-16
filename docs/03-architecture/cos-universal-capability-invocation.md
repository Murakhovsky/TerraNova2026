---
title: COS Canonical Capability Library
status: pilot
updated: 2026-10-16
---

# One catalog, many callers, existing Domain execution

The existing \`CanonicalCapabilityCatalog\` is the metadata authority, populated
by module \`capability_contracts\`. The Gateway is a projection/admission boundary,
not a new CommandBus, SQL writer or Action executor.

Read: \`GET /api/v1/capabilities\`, \`GET /api/v1/capabilities/{id}\`.
UI: \`/workspace/capabilities\`, authenticated tenant manager only.

Statuses:
- \`unavailable\`: declaration only, inactive module, missing permission or binding;
- \`read_only\`: Domain-owned bound QueryBus adapter;
- \`direct_human\`: low-risk INTERNAL Domain CommandBus with CSRF and idempotency;
- \`workflow_approval_required\`: canonical Action with a real handler, *not*
  dispatchable by the direct Gateway.

Pilot bindings: \`query:property.asset.search\` and
\`command:property.asset.create\`. Both are registered on Property's module
manifest and delegate to the existing \`SearchPropertiesQuery\` /
\`CreatePropertyCommand\`. The latter uses canonical Property transaction,
idempotency receipts and audit. It never publishes a Listing.

Human interactive API:
\`POST /api/v1/capabilities/property.asset.create/invoke\` with authenticated
manager session, valid session CSRF, header \`X-Idempotency-Key\` and a JSON
object with \`type_code\` and \`city\`. Server obtains tenant, actor and
correlation. Forbidden IDs in payload are rejected. Same idempotency key and
payload route to the existing Domain receipt.

**No AI or CRON mutation authority is granted by this package.** Autonomous
work must obtain a separately approved Plan through Platform Workflow,
Kernel Action / Policy / Approval and authoritative receipts. Caller origin
is fixed by the HTTP adapter, not the JSON; normal session security applies.
A compromised session remains a compromised session, not a machine-attestation
mechanism.

## Remaining acceptance

This is a safe implementation pilot, not full P0.1–P0.7 acceptance.
Still needed: comprehensive command/adapter inventory for each Domain, formal
input/output schema runtime validation, machine/service identity, AI/CRON
transport governance, a live Workflow adapter for arbitrary registered Actions,
UI confirmation and import workflow, native outcome verification, MySQL
negative/concurrency/failure E2E and controlled production rollout.
No new SQL migrations or automatic live cutover.
