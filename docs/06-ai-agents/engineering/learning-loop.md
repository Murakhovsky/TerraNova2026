---
title: Engineering Learning Loop
description: Перетворення пропущених дефектів на постійні правила, regression tests та evals.
status: active
updated: 2026-10-04
kind: standard
---

# Feedback → правило → regression → eval

Every serious escaped defect must produce a learning record containing defect id, root cause, failed role/gate, new rule, regression test, eval case and documentation impact.

The correction is not “tell the agent to be careful”. A durable correction changes at least one deterministic validator, test, standard or eval. The V0.1 evaluation dataset must contain at least 30 deterministic cases and remain green in CI.
