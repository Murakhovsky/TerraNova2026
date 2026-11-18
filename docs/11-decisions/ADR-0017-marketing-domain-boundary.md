---
title: ADR-0017 Marketing Domain ownership
description: Маркетинг як окремий bounded context, Meta як адаптер, без паралельного виконавця.
status: proposed
updated: 2026-11-18
kind: adr
---

# ADR-0017: Межі домену Marketing

Status: proposed. Marketing owns Marketing Campaign, Brief, Audience targeting, Creative plan, paid placements and provider-reported spend. Meta Ads is a Paid Media adapter, not a new Domain.

Growth retains market intelligence, ICP, OpportunityCandidate, pre-handoff outreach and Growth Learning. Product Management owns products/stock/prices, Sales owns lead/deal outcomes, Finance owns budget envelope, reservations, expenses and FX.

A Marketing Campaign may link to multiple provider executions, without confusing COS planning state and provider effective state. Imported records are externally managed. Agent proposals do not grant execution authority.

Paid writes require accepted Finance F1, independent Approval, canonical Platform Workflow and native Meta readback. No blind retries. Until these gates, only local drafts and read-only provider observations are allowed.

The module remains disabled by default pending tenant-level acceptance.
