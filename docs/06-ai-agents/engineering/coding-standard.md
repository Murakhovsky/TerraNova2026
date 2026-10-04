---
title: Engineering Coding Standard
description: Контракт якості коду для автономної реалізації та рев’ю.
status: active
updated: 2026-10-04
kind: standard
---

# Стандарт коду

Use existing repository conventions and bounded-context ownership. Prefer the smallest implementation that satisfies the approved Feature Specification. Avoid duplicate services, hidden coupling, unnecessary abstractions and silent behavior changes.

Changed behavior requires behavior-focused tests. Error paths must be explicit. Developer must not disable tests, weaken validation, bypass security checks or mutate files outside the approved plan.
