---
title: Engineering Git Standard
description: Правила branches, revisions, PR та merge для автономної розробки.
status: active
updated: 2026-10-04
kind: standard
---

# Стандарт Git

Feature work uses `engineering/{featureId}`. Every architecture, development, review and QA result is tied to an exact repository revision. Reviewer reviews the exact implementation head; QA tests the exact Reviewer-approved head.

Agents may create feature commits and PRs only within their runtime permissions. No agent may write directly to main, force-push main, merge its own PR or deploy production. DONE requires externally verified human merge evidence.
