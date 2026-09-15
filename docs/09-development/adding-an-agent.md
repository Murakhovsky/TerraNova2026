---
title: Adding an Agent
description: How to add an AI agent as governed decision logic without granting uncontrolled mutation authority.
status: active
updated: 2026-09-15
kind: how-to
---

# Adding an Agent

У COS Agent є decision component, а не привілейований користувач із магічним доступом до бази.

## Required path

```text
Business Event / Request
        ↓
Domain-owned context
        ↓
Agent
        ↓
Structured proposal
        ↓
Policy
   ├─ AUTO
   ├─ APPROVAL_REQUIRED
   └─ DENIED
        ↓
Action handler / Use Case
        ↓
Result Event + Audit
```

## Sequence

1. Визначте Domain owner та конкретне рішення, яке Agent допомагає приймати.
2. Опишіть minimum context contract. Не віддавайте Agent весь tenant database «бо раптом знадобиться».
3. Визначте structured output schema для proposal/decision evidence.
4. Зареєструйте тільки дозволені tools/ports.
5. Додайте Policy, яка вирішує AUTO / APPROVAL_REQUIRED / DENIED.
6. Mutation виконує application use case/action handler, не Agent transport.
7. Запишіть audit: context references, agent/version, proposal, policy result, approval і execution result.
8. Додайте evaluation cases для invalid output, insufficient context, unsafe proposal та retry/idempotency behavior.

## Guardrails

- Agent не затверджує власну дію.
- Sensitive context не зберігається unredacted без потреби.
- Tool permission є окремою authority boundary від здатності моделі запропонувати tool call.
- Deterministic domain invariant не переноситься в prompt лише заради моди.

## Read next

- [Agent Runtime](../06-ai-agents/agent-runtime.md)
- [Context & Tools](../06-ai-agents/context-and-tools.md)
- [LLM Governance](../06-ai-agents/llm-governance.md)
- [Policies & Approvals](../05-runtime/policies-and-approvals.md)
