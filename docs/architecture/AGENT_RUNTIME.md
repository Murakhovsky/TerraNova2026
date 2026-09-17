# COS Agent Runtime

`Kernel/Agent` is the canonical framework-independent Agent Runtime core.

## Model

- `Agent` is the stable logical capability.
- `AgentDefinition` is a versioned immutable behavior/configuration definition.
- `AgentInstance` binds an Agent to an Organization and tenant-specific configuration.
- `AgentRun` is one execution lifecycle.
- `AgentStep` is one traceable unit inside a run.
- `AgentContext` is the immutable execution input/context snapshot.
- `AgentOutput` is provider-neutral output.

Canonical run statuses are: `created`, `queued`, `running`, `waiting`, `completed`, `failed`, `cancelled`.

## LLM boundary

Agent Runtime depends only on `LlmProviderInterface`. OpenAI, Anthropic, local-model and other provider adapters belong to Infrastructure. Provider SDKs and HTTP clients must never be imported into `Kernel/Agent`.

The existing `AgentRuntime::run()` and `LlmClientInterface` remain compatibility surfaces while existing domain flows migrate. New runtime work targets `AgentRuntimeInterface`, `AgentRuntimeEngine`, and `LlmProviderInterface`.

## Relationship to Tool and Workflow

Agent Runtime decides and reasons. It does not directly own tool permissions, retries, audit or business orchestration. Tool execution is delegated to Tool Runtime; long-lived orchestration is delegated to Workflow Engine.
