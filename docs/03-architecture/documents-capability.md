---
title: Platform Documents
description: Архітектурна межа спільної document capability COS.
status: active
updated: 2026-09-19
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


## Wave 10 runtime

Documents тепер має виконуваний Platform runtime, не перетворюючись на Domain:

```text
Symfony API / business Domain
        ↓
explicit Command / Query
        ↓
DocumentsRuntimeService
        ↓
DocumentsRepositoryInterface + FileStorageInterface
        ↓
MySQL metadata + Platform storage
        ↓
Event + Audit
```

Канонічні write-сценарії: Upload Document, Attach Document до business reference, Create Version, Generate From Template, Request Signature, Sign та Archive.

`DocumentAttachmentPort` є стабільною межею для Sales, Property, HR, Finance та інших bounded contexts. Domain передає tenant, actor/correlation, `documentId` і власний business reference. Він не знає про `cos_document_*`, storage key або Symfony.

## Runtime invariants

- усі записи tenant-scoped через `organization_id`;
- consequential writes вимагають idempotency key;
- один key + інший payload є conflict;
- binary/content зберігається через `FileStorageInterface`, а не в бізнесових таблицях;
- metadata, Version, Relation та Signature зберігаються окремо;
- archive є terminal state для нових Version, Relation та Signature completion;
- Version number серіалізується lock-ом документа;
- DB mutation, Event і Audit відбуваються в одній transaction boundary;
- якщо storage write відбувся, а DB transaction впала, runtime видаляє orphan file;
- filename sanitization та 10 MiB limit застосовуються на Platform boundary;
- template generation приймає лише scalar variables;
- e-signature provider reference є evidence, а не provider SDK у Platform layer.

## Persistence

```text
cos_documents
cos_document_files
cos_document_versions
cos_document_relations
cos_document_templates
cos_document_signatures
cos_document_operation_receipts
```

Ці таблиці належать `Platform`, а не окремому Documents Domain.
