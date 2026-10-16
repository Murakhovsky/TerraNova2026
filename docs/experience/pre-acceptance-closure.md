# Experience V1 pre-acceptance consolidation

## Єдині канонічні UX-реєстри

Machine-consumed Product UX governance from #362 remains the one source of truth at:
`resources/experience/personas.yaml`, `journeys.yaml`, `terminology.yaml`, `ux-debt.yaml`, `design-system/gaps.yaml`.
The incompatible draft registries from #366 are not deployed a second time.

## Preserved complementary #366 policy

- `resources/experience/priority-policy.yaml` ties P0–P3 to measurable impact and does not automatically reclassify routes.
- `resources/experience/golden-review-checklist.yaml` remains PENDING for all eight pages. Automated browser checks **never** constitute human approval.
- Release candidates exclude DEPRECATED, SUPERSEDED, EXEMPT while retired contracts remain visible in inventory.
- DSG-001, DSG-002 are adapted to the existing canonical `design-system/gaps.yaml` structure, not a second file.
- Unresolved action/state quality and evidence-provenance concerns are tracked as UX-005 and UX-006 in `ux-debt.yaml`.
- #362 already reclassified inflated priorities and removed generic goal placeholders; old #366 draft claims must not override this reviewed canonical inventory.

Do not auto-set V1_READY or rewrite `golden-decisions.yaml`. Human visual/product acceptance remains external to CI.
