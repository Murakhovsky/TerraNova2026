# Sales V0.6.4 — Runtime Integration Hardening

V0.6.3 completed the updated EPIC 2 workspace, but the main COS runtime workflow still carried a legacy V0.5 expectation that New Replies deep-linked to Deal Timeline.

The current product contract is intentional: `New Replies → Deal #communications`, because communications are now first-class and actionable. Timeline remains available as historical context, but it is no longer the operational destination for an inbound reply.

This hardening commit:

- updates legacy manager/workspace regression tests to validate the current semantic mappings (`work`, `intelligence`, `communications`);
- keeps verifying that every Today destination exists in Deal Workspace;
- adds V0.6.2 runtime and V0.6.3 projection/UX contracts to the main `COS Runtime Checks` workflow, which gates dev deployment;
- leaves the focused `Sales V0.6.3 Contract` workflow in place as a fast Sales-only signal.

No Sales or Kernel runtime behavior changes in this commit. It repairs integration enforcement so CI matches the product architecture instead of an obsolete UI destination.
