---
title: Операційна безпека
description: Операційний security baseline для identity, tenant isolation, secrets, integrations, agents та audit.
status: active
updated: 2026-09-16
kind: operations
---

# Операційна безпека

Security у COS проходить через усі execution layers. Вона не живе в одному middleware з героїчною назвою `SecurityService`.

## Базові правила

### Identity та tenant isolation

Кожна consequential operation має працювати в explicit identity/organization context.

Tenant isolation перевіряється не лише в UI/session layer, а й на application/persistence boundaries. CLI workers і background jobs отримують tenant context зі своєї operation/job/event семантики, а не через Web session.

### Мінімально необхідні повноваження

Permissions/capabilities і Policies дають мінімально необхідне право. Agent, worker або integration не отримує глобальну mutation authority «для зручності».

### Секрети

Provider credentials, API keys, DB passwords і signing secrets:

- не зберігаються в Domain records як plain business data;
- не потрапляють у prompts/logs/audit payloads;
- мають controlled configuration/secret resolution;
- можуть бути rotated без зміни Domain model;
- мають бути scoped настільки вузько, наскільки дозволяє provider/runtime.

### Зовнішні дані

API/webhooks/messages/documents/LLM context є untrusted input до validation/normalization.

Provider authenticity, payload validity і business authorization є різними перевірками. Успішна signature verification не означає автоматичне право змінити Domain state.

### Межа Agent

Prompt/tool injection не повинна давати Agent нові capabilities.

Registered tools/actions, schema validation, Policy та Approval залишаються authority boundary незалежно від тексту model output. Agent не затверджує власну дію і не обходить application use case через прямий доступ до persistence.

## Операції, чутливі до аудиту

Особливо важливі mutations мають залишати достатній audit trail для відповіді на питання:

```text
actor
+ tenant
+ operation
+ authority decision
+ semantic change / before-after reference
+ external effect
+ final result
```

Audit trail не повинен містити secrets або необмежені sensitive payloads лише тому, що «так зручніше для дебагу».

## Реагування на security incident

Мінімальний flow:

```text
Detect
→ contain credentials/capabilities
→ preserve audit evidence
→ identify affected tenants/data/actions
→ rotate/revoke
→ repair/reconcile state
→ verify recovery
→ document root cause
```

Для consequential external actions окремо перевіряйте, чи не потрібно reconcile фактичний стан у provider після containment.

## Dependency та deployment hygiene

Production deployment використовує pinned/reviewed dependencies, CI checks, non-secret artifacts і environment-specific secrets.

Dev convenience не переноситься автоматично в production authority. Debug routes, broad credentials, permissive CORS, temporary admin shortcuts та test keys не повинні виживати лише тому, що ніхто не згадав їх прибрати.

## Пов’язані сторінки

- [Учасники та повноваження](../01-product/actors-and-authority.md)
- [LLM Governance](../06-ai-agents/llm-governance.md)
- [API та Webhooks](../07-api-integrations/api-and-webhooks.md)
- [Спостережуваність та інциденти](./observability-and-incidents.md)
