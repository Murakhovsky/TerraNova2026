# CM-CAPITAL-RISK Architecture Packet

Status: implementation architecture  
Package: `CM-CAPITAL-RISK`  
Flow: Manager → Architect → Developer → Reviewer → QA → Manager Acceptance

## Reuse matrix

| Capability | Existing | Decision |
| --- | --- | --- |
| Portfolio / positions | YES | Extend as derived portfolio state |
| Ledger | YES | Keep financial source of truth |
| Capital reservation | YES | Reuse after allocation approval |
| Trade risk | YES | Extend with portfolio risk, do not fork a V2 engine |
| Economic exposure | PARTIAL | Extend with validated netting + immutable snapshots |
| Strategy scorecards | YES | Consume as allocator input |
| Promotion gates | YES | Live allocation only for eligible strategy lifecycle |
| Allocation | NO | New deterministic constrained allocator |
| Stress | PARTIAL/NO | New deterministic portfolio stress foundation |
| AI Research runtime | YES | Portfolio Agent comes after deterministic simulation |

## Ownership

Ledger owns financial truth. Portfolio owns operational derived state. Risk owns envelopes and deterministic enforcement. Allocation owns proposals and plans, never execution. Execution consumes approved capital reservations. AI can read, simulate, explain and propose but cannot alter hard limits, approve itself, move live capital or mutate Ledger.

## Netting rules

Gross exposure is always preserved. Net exposure is allowed only through a validated economic relationship. Unknown or invalid equivalence becomes `UNKNOWN_EXPOSURE` and is never netted. Derivatives may apply delta equivalence; absence of a trustworthy mapping is conservative, not zero-risk.

## Capital rules

Available capital is not raw cash. Reserved, deployed, locked, margined, unsettled, settlement and emergency buffers are excluded by policy. Capital location is explicit. Cross-venue strategies must have usable capital at every required location.

## Risk hierarchy

SYSTEM → PORTFOLIO → STRATEGY → VENUE → ASSET → INSTRUMENT → POSITION. A lower level may tighten but never weaken a hard upper-level limit. Hard limits are deterministic and non-overridable by AI.

## Allocation V1

V1 is deterministic RULE_BASED / SCORE_BASED constrained allocation. Ranking uses expected net return, confidence, execution probability, strategy quality, capacity, risk, concentration and liquidity penalties. Approved size is constrained by available capital, market capacity and hard risk headroom. Same portfolio snapshot + opportunity set + policy version produces the same plan fingerprint and allocation order.

## State machine

NORMAL → CAUTION → RESTRICTED → REDUCE_ONLY → HALTED → EMERGENCY. REDUCE_ONLY and stronger states reject all new risk while allowing reduce/close execution paths.

## Rebalance

Rebalance is a plan, not an immediate trade. Hysteresis and cooldown prevent allocation thrashing. Costs must be compared with expected risk/return improvement.

## Concurrency and idempotency

Allocation plans have a deterministic input fingerprint with a uniqueness constraint. Approval changes only `PROPOSED → APPROVED` and therefore repeated approval cannot duplicate the transition. Capital reservation remains the execution-side anti-oversubscription authority.

## AI authority boundary

Portfolio Agent may call read/simulation/recommendation tools. It cannot override hard risk, modify envelopes, approve its own proposal, disable a kill switch, mutate Ledger, or directly move live capital.
