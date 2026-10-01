---
title: Data and integrations
status: active
updated: 2026-10-01
kind: how-to
---

# Data and integrations

COS should not copy every piece of information it can reach.

For each important fact, decide **where it originates, who owns it, who may change it and how it enters the process**.

## 1. Build a source map

A simple table is enough:

| Data | System of record | Who changes it | Who reads it | Update timing |
| --- | --- | --- | --- | --- |
| Customer contact | CRM | sales manager | sales, service | immediately |
| Document | file storage | responsible role | approval process | on event |
| Payment | accounting | finance | fulfillment | after confirmation |

The goal is to find conflicting ownership before software makes that conflict faster.

## 2. Choose the integration mode

For each external system choose a clear mode:

- read;
- write;
- two-way synchronization;
- migration;
- reference only.

Do not choose two-way synchronization by default. It requires explicit conflict rules.

## 3. Define identity and deduplication

For contacts, companies, properties, documents and other objects, define how COS knows that two records represent the same thing.

Check external IDs, duplicates, merge rules and historical records.

## 4. Define the exchange contract

For an API integration document:

- authentication;
- available operations;
- rate limits;
- data format;
- retry behavior;
- idempotency;
- partial success;
- error logging.

If no API exists, evaluate controlled file import, database exchange, messaging or another explicit mechanism.

## 5. Design failure behavior

For every integration answer:

- what happens if the external system is unavailable;
- when to retry;
- when a person must intervene;
- whether the same request may run twice;
- how a failed transfer is found;
- how synchronization is restored.

## 6. Limit access

Connections should receive only the permissions they need.

Document sensitive data, storage location, external sharing rules, secret handling and credential revocation.

## 7. Separate migration from integration

Historical data migration is a separate project concern.

Decide what data is needed, what is obsolete, how duplicates are cleaned and how repeated imports remain safe.

Next: [automation and AI](./automation-and-ai.md).
