# COS Workflow Engine

`Kernel/Workflow` is the canonical executable business-orchestration runtime. It is intentionally distinct from both `Kernel/Process` and Symfony Workflow.

- `Kernel/Process` describes the business process: actors, outcomes, as-is/to-be process graph and cross-domain ownership.
- `Kernel/Workflow` executes an orchestration: instances, step execution, conditions, assignments, waiting and runtime transitions.
- Symfony Workflow is an Infrastructure state-machine implementation used to validate Workflow execution lifecycle transitions. Symfony types do not enter `Kernel/Workflow`.

## Canonical model

- `Workflow` — stable orchestration capability.
- `WorkflowDefinition` — versioned executable graph.
- `WorkflowInstance` — Organization-bound configuration.
- `WorkflowExecution` — one runtime execution and trace.
- `Transition` — edge between steps with an optional typed `Condition`.
- `Assignment` — human ownership target.

Step types are `HumanStep`, `AgentStep`, `ToolStep`, `SystemStep`, `DecisionStep`, and `WaitStep`.

## Runtime rules

`HumanStep` and `WaitStep` suspend an execution. `AgentStep` delegates to `AgentRuntimeInterface`; `ToolStep` delegates to `ToolRuntimeInterface`; neither execution path may bypass those runtimes. `DecisionStep` selects a transition using typed conditions. `SystemStep` delegates to `SystemStepHandlerInterface`.

Workflow data is addressed through explicit context paths (`input`, `steps`, `variables`). Step templates may reference values with `$.input...`, `$.steps...`, or `$.variables...`; arbitrary code evaluation is forbidden.

The synchronous V1 engine executes until it reaches a waiting or terminal state. An inline-step limit prevents accidental infinite loops. Persistence, timers, queue wake-ups and distributed locking remain Infrastructure concerns and are intentionally outside the pure Kernel model.
