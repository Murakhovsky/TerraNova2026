# Sales V0.6 — Operational Workspace

Sales V0.6 extends the existing Sales V0.5 Manager Operations vertical slice into the two high-frequency manager surfaces: Pipeline and Today.

## Contract

- Pipeline stage movement must call the canonical `POST /api/sales/deals/{id}/stage` endpoint.
- Drag-and-drop is a UI interaction only. Business transition validation remains in `ChangeDealStage`.
- Today does not invent parallel actions. Each operational signal deep-links into the relevant Deal Workspace section (`#work`, `#intelligence`, `#timeline`).
- Manager actions continue to flow through Sales Application use cases and Kernel runtime mechanisms.
- Pipeline remains configurable from `pipeline_id + stage_id`; UI must not hard-code business stage transitions.

## UX goal

A manager should be able to start from Today or Pipeline, identify the deal requiring attention, move directly to the relevant action surface, execute the operation, and see the resulting state/event in the same Sales Workspace.
