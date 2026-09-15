---
title: Security Operations
description: Operational security baseline for identity, tenant isolation, secrets, integrations, agents and audit.
status: active
updated: 2026-09-15
kind: operations
---

# Security Operations

Security в COS проходить через всі execution layers. Вона не живе в одному middleware з героїчною назвою `SecurityService`.

## Operational baseline

### Identity and tenant isolation

Кожна consequential operation має працювати в explicit identity/organization context. Tenant isolation перевіряється не лише UI/session layer, а й application/persistence boundaries.

### Least authority

Permissions/capabilities і Policies дають мінімально необхідне право. Agent, worker або integration не отримує глобальну mutation authority «для зручності».

### Secrets

Provider credentials, API keys, DB passwords і signing secrets:

- не зберігаються в Domain records як plain business data;
- не потрапляють у prompts/logs/audit payloads;
- мають controlled configuration/secret resolution;
- rotation не повинна вимагати зміни Domain model.

### External input

API/webhooks/messages/documents/LLM context є untrusted input до моменту validation/normalization. Provider authenticity та payload validity є окремими checks.

### Agent boundary

Prompt/tool injection не повинна давати Agent нові capabilities. Registered tools/actions, schema validation, Policy та Approval залишаються authority boundary незалежно від тексту model output.

## Audit-sensitive operations

Особливо важливі mutations повинні залишати достатній audit trail для відповіді: actor, tenant, operation, authority decision, before/after або semantic change, external effect/result.

## Incident response

Для security incident потрібен мінімальний flow:

```text
Detect
→ contain credentials/capabilities
→ preserve audit evidence
→ identify affected tenants/data/actions
→ rotate/revoke
→ repair/reconcile state
→ document root cause
```

## Dependency and deployment hygiene

Production deployment повинен використовувати pinned/reviewed dependencies, CI checks, non-secret artifacts та environment-specific secrets. Dev convenience не переноситься автоматично в production authority.

## Related

- [Actors & Authority](../01-product/actors-and-authority.md)
- [LLM Governance](../06-ai-agents/llm-governance.md)
- [API & Webhooks](../07-api-integrations/api-and-webhooks.md)
