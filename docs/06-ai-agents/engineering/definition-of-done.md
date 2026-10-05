---
title: Engineering Definition of Done
description: Детермінований READY gate автономного інженерного циклу COS.
status: active
updated: 2026-10-04
kind: standard
---

# Визначення готовності

READY_FOR_HUMAN_APPROVAL requires architecture approved; development complete; independent review approved; QA PASS; CI PASS; blocking Acceptance Criteria verified; tenant/auth/authz verified or explicitly not applicable; migration/rollback/API compatibility verified or N/A; static analysis and required tests PASS; smoke PASS when required; documentation impact checked; no HIGH/CRITICAL finding; no blocking human decision; no running task; exact revision consistency.

A language-model assertion never overrides deterministic evidence.


## Static-quality gate у V0.1

The repository does not currently install PHPStan or Psalm. Therefore V0.1 does not pretend that a dedicated analyzer exists: the deterministic static-quality gate is the GitHub CI `fast` job, which executes PHP syntax validation plus the complete current architecture/unit contract suite. A dedicated analyzer may replace or extend this gate later without weakening the READY contract.
