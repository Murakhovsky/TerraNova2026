# COS Tool Runtime

`Kernel/Tool` is the canonical capability execution boundary used by Agents and Workflows.

A Tool implementation describes and performs one capability. `ToolRuntime` owns the execution policy around that capability: registry lookup, canonical permission, input validation, retry policy, audit and terminal result.

## Canonical flow

1. Resolve the tool from `ToolRegistryInterface`.
2. Derive `ToolPermission` (`tool.<tool-name>.execute`).
3. Authorize against tenant/user context from `ToolInvocation`.
4. Validate structured input against the tool definition schema.
5. Execute through `ToolInterface::invoke()`.
6. Apply explicit retry policy.
7. Audit the terminal `ToolExecution` exactly once.
8. Return structured `ToolResult` through `ToolExecution`.

Side-effect tools (`WRITE`, `EXTERNAL`) are never retried automatically unless a retry policy explicitly opts into side-effect retries. The V1 default is one attempt.

Agent Runtime does not bypass this layer. An Agent may request a tool execution, but permissions, validation, retries and audit remain Tool Runtime responsibilities.
