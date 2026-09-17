# Sales Domain

`app/Domains/Sales/Domain` is the canonical business model for Sales. It is pure PHP and may depend on `Kernel\Shared`, but not Symfony, Phalcon, PDO, Platform runtime services, Infrastructure, or the legacy `Domains\Sales\Model` namespace.

Initial bounded concepts are `Lead`, `Contact`, `Company`, `Opportunity`, `Pipeline`, and `Activity`.

`Opportunity` is the canonical business concept layered over the current ClientCase/Deal persistence. This migration does not create a second opportunity table or duplicate the existing sales pipeline. Legacy models remain available while adapters and use cases move incrementally.

Status compatibility is deliberate: canonical `LeadStatus` preserves current persisted string values; canonical `OpportunityStatus` preserves `ClientCaseStatus` values. A won/lost pipeline outcome remains a property of the terminal pipeline stage, avoiding a second competing lifecycle.

Repository interfaces in the Domain are typed ports. Existing MySQL repositories will be adapted gradually behind these ports.
