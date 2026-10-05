---
title: Engineering Agent Permissions
description: Межі можливостей п’яти ролей автономної інженерної команди COS.
status: active
updated: 2026-10-04
kind: standard
---

# Повноваження агентів

Manager: repository read, issue management, delegation; no production mutation.

Architect: repository read and architecture-documentation writes under approved docs roots; no production implementation.

Developer: approved feature branch code/tests; no main merge or deploy.

Reviewer: PR/diff/CI read-only; no implementation or acceptance-criteria changes.

QA: verification plus test-only writes under `tests/` and `symfony/tests/`; no production implementation.

Human/CI retain merge-to-main and production deployment authority.
