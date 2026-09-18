---
title: Platform Documents
description: Архітектурна межа спільної document capability COS.
status: active
updated: 2026-09-18
kind: architecture
contract: architecture-v1
---

# Документи як Platform capability

Documents є Platform capability, а не бізнес-доменом. Нею користуються Sales, Finance, HR, Construction, Procurement та інші bounded contexts.

## Межа відповідальності

Канонічний словник capability: `Document`, `File`, `Template`, `Version`, `Signature`, `Relation`, `Permission`.

Documents не містить предметних правил договору продажу, рахунку, кадрового документа чи будівельного акту. Такі правила належать відповідним Domains.

## Інваріанти

- `Document` не дорівнює binary `File`;
- storage provider не є частиною Platform model;
- зовнішній e-signature provider є Infrastructure adapter;
- Domain не залежить від SDK конкретного storage/signature сервісу;
- Knowledge/RAG document projection не підміняє business-document source of truth.
