# Service Domain

Service owns the operational lifecycle of service requests and tickets.

Canonical Wave 11 flow:

```
Request → Ticket → Assignment/SLA → Escalation → Resolution → Close
```

Persistence belongs to Service and is tenant-scoped. External communication providers do not belong to this Domain.

The executable application boundary is `ServiceApplicationBoundary`; Symfony delivery uses explicit Commands/Queries and never writes Service tables directly.
