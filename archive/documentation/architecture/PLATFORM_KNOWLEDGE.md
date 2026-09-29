# COS Platform Knowledge / Context

`Platform\\Knowledge` owns normalized knowledge ingestion and context assembly for Agents and Workflows.

## Canonical model

`Source → Document → Chunk → Embedding → RetrievalResult → Context`

A `KnowledgeBase` groups sources. `ContextRequest` carries the tenant, query, references, filters and explicit token budget.

## Agent boundary

```text
Agent Runtime
    ↓ AgentContextBuilderInterface
Infrastructure Knowledge adapter
    ↓
Platform Knowledge ContextBuilder
    ↓
Retriever / Source / Embedding contracts
    ↓
Infrastructure adapters (DB, files, CRM, Google, vector store...)
```

The Agent never opens PDO connections, reads files, calls CRM APIs or talks to vector stores directly. It receives a bounded, source-traceable `Context`.

`ContextBuilder` ranks retrieval results, removes duplicate chunks, enforces item/token budgets and preserves source/document/chunk identity for auditability.
