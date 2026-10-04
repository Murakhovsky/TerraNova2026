---
title: Engineering Architecture Standard
description: Обов’язкові архітектурні обмеження для інженерних агентів COS.
status: active
updated: 2026-10-04
kind: standard
---

# Стандарт архітектури

Every production feature requires an Architecture Decision and Implementation Plan. Domain ownership, bounded context, dependencies, public interfaces, tenant isolation, identity/auth, permissions, database/API/event impact, migration/rollback, compatibility, observability and testing strategy must be explicit.

Developer may implement only approved files and interfaces. Architecture drift routes back to Principal Architect. New bounded contexts, breaking contracts, destructive migrations and ambiguous security choices require human authority.
