---
title: Automation and AI
status: active
updated: 2026-10-01
kind: how-to
---

# Automation and AI

During implementation, the important question is not only **what the system can do**, but also **what it is allowed to do without a person**.

## Authority levels

A practical scale for each action:

1. **Suggest**: the system only recommends an action.
2. **Prepare**: the system creates a draft and a person confirms it.
3. **Controlled execution**: the system acts within defined limits.
4. **Automatic execution**: the action may run without manual approval.
5. **Blocked**: the system is not allowed to perform the action.

Set authority per action, not “for AI in general.”

## Prefer deterministic automation when

Use normal rules or code when:

- conditions are clear;
- the result can be checked exactly;
- no interpretation of free text is required;
- mistakes are expensive;
- behavior must be predictable.

A language model is an impressive way to solve many problems. Arithmetic does not need to become one of them.

## Use AI when

AI can be useful for:

- classifying text or requests;
- extracting facts from unstructured information;
- summarizing;
- preparing a draft response;
- comparing a situation with criteria;
- finding anomalies;
- suggesting the next action.

## Give AI the minimum useful context

Provide:

- facts required for the current decision;
- current process state;
- allowed actions;
- rules and limits;
- relevant previous events;
- required output format.

Do not send the entire company database “just in case.”

## Define verification

For every AI-assisted action define:

- how correctness is checked;
- what output is unacceptable;
- when human approval is required;
- what happens when confidence is low;
- what happens when the model is unavailable;
- what data must never be sent;
- how the decision and its evidence are recorded.

## Human approval

Require human approval when an action creates material legal or financial obligations, changes critical data, affects another person's rights or cannot be verified reliably.

## Failure behavior

A failed automation needs a visible state, retry rules, an owner, audit history and a safe manual path.

Next: [readiness check](./readiness.md).
