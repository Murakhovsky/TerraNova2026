# Service Domain

Service `1.0.0` owns the operational lifecycle of service requests and tickets.

## V1 release status

V1 stabilizes the executable Wave 11 runtime rather than replacing it. The proven persistence schema remains `0.2.0`; a forward-only lifecycle migration advances installed module state to `1.0.0`. The release gate freezes tenant scoping, row-lock serialization, idempotency fingerprint conflicts, transaction-bound Event/Audit writes and the Request → Ticket → Resolution → Close contract.

Canonical Wave 11 flow:

```
Request → Ticket → Assignment/SLA → Escalation → Resolution → Close
```

Persistence belongs to Service and is tenant-scoped. External communication providers do not belong to this Domain.

The executable application boundary is `ServiceApplicationBoundary`; Symfony delivery uses explicit Commands/Queries and never writes Service tables directly.
