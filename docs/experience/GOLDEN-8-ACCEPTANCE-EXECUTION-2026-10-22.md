# COS Golden 8 acceptance: execution runbook

Specification: COS-GOLDEN-8-ACCEPTANCE-2026-10 v1.0, 2026-10-22.
Status: IN PROGRESS. Automatic evidence is NOT human acceptance.

## Confirmed baseline on 2026-10-22

- main SHA: a4498c4530ad1fcf24051cd32483146b4dd4f555.
- Successful Runtime: https://github.com/Murakhovsky/TerraNova2026/actions/runs/39816615501
- Passed steps: Golden Eight browser QA, Reconcile Golden Eight QA into canonical Page Contract evidence, Upload Golden Eight evidence.
- Artifact: golden-eight-evidence, ID 12441994001.
- All eight canonical review decisions are PENDING; the 8 human checklists have no signed reviews. This is NOT a Golden READY signal.
- Growth overview is 2/5 across five Page Contract scores; UX-003 is OPEN.
- The deploy job succeeding is not enough to verify the SHA served from the target host. Confirm deployed SHA before review.
- After this branch is updated, use the new CI run/SHA. The above is a historical baseline only.

## Evidence collection and scope

Canonical browser QA: 8 pages × desktop 1440×900 and mobile 390×844, 16 observations. Existing QA ingestion remains unchanged.

Supplemental capture: 8 pages × tablet 768×1024 and small mobile 320×700, 16 additional observations. New Node script: tests/browser/golden_eight_review_capture.mjs.

The supplemental script gathers screenshots, Axe reports, focus, overflow, HTTP, archetype and browser errors, saving under tmp/golden-eight/review-support. It discovers a real deal from Sales Pipeline, does not mutate records, and does not write acceptance decisions or quality scores. Errors fail its own step. Its summary is not a second canonical QA registry. Supporting screenshots need human examination.

Run from repository root against disposable staging with fixture accounts:

~~~bash
export GOLDEN_EIGHT_BASE_URL=http://127.0.0.1:8081
export GOLDEN_EIGHT_EMAIL=your-staging-user@example.test
export GOLDEN_EIGHT_PASSWORD=FROM_SECURE_ENVIRONMENT
export GOLDEN_EIGHT_OUTPUT_DIR=tmp/golden-eight
export CHROME_PATH="$(command -v google-chrome || command -v chromium)"
npm run test:golden-eight-quality
npm run test:golden-eight-review-capture
~~~

Never commit passwords. Never use real client records for mutation tests.

## Preconditions

Record environment URL, actually deployed commit SHA, date/time, browser version, CI URL, reviewer, organization ID and tenant. Have U1 (executive/admin), U2 (sales), U3 (restricted), and T2 (another tenant with separate fixture). Test data: ≥10 leads, ≥5 deals in different stages, overdue and current activities, KPI figures, Growth signals/opportunities, ≥10 properties, some without coordinates, and empty states.

Use fixture IDs prefixed G8-TEST. On non-disposable production, mark dangerous mutation scenarios BLOCKED, not PASS.

## Golden 8 page scenario matrix

| Page | Route | Required cases | Proof |
| --- | --- | --- | --- |
| G8-01 Executive | /admin | TC-01.1..01.4 | 30-second comprehension, priority deep link, independently reconciled KPI, U3 denial |
| G8-02 Sales Dashboard | /sales/dashboard | TC-02.1..02.4 | KPI period, Today link, empty state, Dashboard/Pipeline reconciliation |
| G8-03 Sales Today | /sales/today | TC-03.1..03.5 | Task ID before/after, persistence after reload, duplicate prevention, recoverable error |
| G8-04 Sales Leads | /sales/leads | TC-04.1..04.5 | Search/filter reset, created Lead ID, record opening, validation/duplicate rules |
| G8-05 Sales Deal | /sales/deals/{id} | TC-05.1..05.5 | Actual Deal ID, permitted action, history, 404, T2 restriction |
| G8-06 Pipeline | /sales/pipeline | TC-06.1..06.5 | Correct columns, Deal deep link, valid and invalid transitions, mobile/filter |
| G8-07 Growth | /growth | TC-07.1..07.4 | Priority signal, source, business next step, Sales receipt or explicit workflow gap, UX-003 |
| G8-08 Property Map | /property/map | TC-08.1..08.6 | Coordinate fixtures, property ID, map context after Back, filter, invalid-coordinate handling |

Required E2E:
- E2E-01: Executive KPI -> concrete business problem -> contextual module.
- E2E-02: Sales Dashboard -> Today -> Lead/Deal -> Pipeline, with one persisted real test mutation.
- E2E-03: Growth -> Opportunity -> Sales handoff, if implemented; otherwise document exactly where it stops, never invent PASS.
- E2E-04: Property Map -> Property Detail -> Back, with selection and spatial context checked.

Test server-side permissions via direct URL and API; a hidden button is not an authorization test. For mutable cases record before/after, IDs, operation receipts and behavior after reload. For failing or absent workflows, record FAIL/BLOCKED, not N/A.

## Human quality review

For each page evaluate with dated evidence all seven required criteria:
1. task_success
2. visual_hierarchy
3. responsive_behavior
4. keyboard_access
5. states (loading, empty, error, denied)
6. content_clarity
7. data_integrity

Each result: PASS, FAIL, BLOCKED or justified N/A. Exercise Tab, Shift+Tab, Enter, Space, Escape; take desktop/tablet/mobile screenshots. Confirm no misleading success before completion.

Assign separate scores 0–5 to Functionality, UX, Visual, Responsive, Accessibility and Content. All SIX must be ≥4. An average ≥4 with any deficient category does not pass. P0/P1 defects block, relevant P2 may block, P3 is an explicitly documented residual issue.

Defect record fields: ID, page ID, test ID, severity, expected, observed, URL/role/tenant, SHA, screenshot/log, owner, fix commit, retest outcome. Growth UX-003 stays OPEN until actual human re-evaluation.

## Canonical approval and release gates

Source-controlled records:
- resources/experience/golden-review-checklist.yaml
- resources/experience/golden-decisions.yaml

For each page the checklist must contain reviewer, reviewed_at, exact commit_sha, browser, viewport, criteria, evidence, notes, decision. For ACCEPT the decision record must include actor, decided_at and non-empty evidence. For REQUEST_CHANGES include actor, decided_at and note. No automated QA job may grant approval or silently promote a Page Contract to V1_READY.

Run from symfony directory in a configured app:

~~~bash
php bin/console cos:experience:golden
php bin/console cos:experience:golden:review
php bin/console cos:experience:golden:decisions
php bin/console cos:experience:contracts:audit --json
php bin/console cos:experience:release --json
~~~

After all criteria, fixes, quality scores and signed decisions:

~~~bash
php bin/console cos:experience:golden --strict
php bin/console cos:experience:release --strict
~~~

Do not relax the strict checks. Golden 8 READY and Experience V1 READY are separate states. Mass UI migration remains blocked until the actual Golden Gate passes.

## Acceptance deliverables (pending)

- Golden 8 Test Report (all page cases and four E2E).
- Defect Register with observed evidence and retests.
- Quality Scorecard (six ratings per page).
- Evidence Pack (screenshots, before/after, CI run).
- Eight attributable operator decisions.
- Release Gate Report.
