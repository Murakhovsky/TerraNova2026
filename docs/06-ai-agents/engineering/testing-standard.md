---
title: Engineering Testing Standard
description: Контракт планування та перевірки тестів для інженерних агентів COS.
status: active
updated: 2026-10-04
kind: standard
---

# Стандарт тестування

QA creates TEST_PLAN before architecture. Tests are selected by applicability across unit, integration, functional, E2E and smoke suites. Every blocking Acceptance Criterion requires observable evidence.

QA PASS requires zero failed tests, every required suite PASS, all applicable COS invariants PASS, meaningful evidence and no unresolved BLOCKER/MAJOR security or quality finding. QA may mutate only tests/ and symfony/tests/.
