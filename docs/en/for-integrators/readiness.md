---
title: Readiness check
status: active
updated: 2026-10-01
kind: how-to
---

# Readiness check

A process is not ready for production because the happy path worked once.

Use this checklist before go-live.

## Process

- [ ] The business result is measurable.
- [ ] Start and finish are defined.
- [ ] Main stages and transitions are documented.
- [ ] Exceptions, rejection and return paths are known.
- [ ] Every critical step has an owner.
- [ ] Overdue work has a defined response.

## Data

- [ ] Every critical fact has a system of record.
- [ ] Write authority is defined.
- [ ] Duplicate handling is defined.
- [ ] Required fields are validated.
- [ ] Historical migration scope is clear.
- [ ] Migrated data has been checked.

## Access and authority

- [ ] Roles receive only required permissions.
- [ ] Critical actions have explicit limits.
- [ ] Human approval is configured where needed.
- [ ] Access can be revoked.
- [ ] Sensitive data is not exposed through unnecessary logs or services.

## Integrations

- [ ] Authentication works.
- [ ] Direction of data flow is clear.
- [ ] External downtime is handled.
- [ ] Retry does not create duplicates.
- [ ] Failed operations can be found and replayed.
- [ ] Critical failures have an owner.

## Automation and AI

- [ ] Every automated action has an authority level.
- [ ] Human confirmation points are known.
- [ ] Invalid and incomplete inputs were tested.
- [ ] Low-confidence AI behavior is safe.
- [ ] A manual fallback exists.
- [ ] Critical decisions keep history and evidence.

## Users

- [ ] Main roles tested real scenarios.
- [ ] People know what changed in their work.
- [ ] Support and escalation paths are clear.
- [ ] A short daily-use instruction exists.
- [ ] Process ownership after launch is defined.

## Operations

- [ ] Critical components have health monitoring.
- [ ] Important failures generate visible logs or alerts.
- [ ] Recovery procedures are known.
- [ ] Critical backups are verified.
- [ ] Updates have a controlled deployment path.

## Measurement

- [ ] Baseline metrics were recorded.
- [ ] The first comparison date is defined.
- [ ] One person owns the result review.
- [ ] Pilot success criteria are explicit.
- [ ] The team knows what to change if expected value is not achieved.

## Go-live condition

The process is ready when the team can say not only “the main scenario works,” but also **“we know what happens when something goes wrong.”**

Next: [developer documentation](../for-developers/).
