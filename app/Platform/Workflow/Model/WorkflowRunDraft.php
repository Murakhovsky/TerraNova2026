<?php
declare(strict_types=1);

namespace Platform\Workflow\Model;

use InvalidArgumentException;

/** Immutable invocation snapshot, not an authorization grant. */
final readonly class WorkflowRunDraft
{
    public function __construct(
        public WorkflowRunIdentity $identity,
        public string $definitionId,
        public string $definitionVersion,
        public string $inputDigest,
        public string $actorId,
        public string $correlationId,
        public ?string $sourcePlanId = null,
        public ?string $approvalReference = null,
        public ?string $sourcePlanDigest = null,
        public ?WorkflowDefinitionSnapshot $definition = null,
    ) {
        foreach ([$definitionId, $definitionVersion, $actorId, $correlationId] as $required) {
            if (trim($required) === '') {
                throw new InvalidArgumentException('Workflow invocation requires pinned definition, actor and correlation.');
            }
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $inputDigest)) {
            throw new InvalidArgumentException('Workflow invocation input must have a SHA-256 digest.');
        }
        if (($sourcePlanId === null) !== ($sourcePlanDigest === null)
            || ($sourcePlanDigest !== null && !preg_match('/^[a-f0-9]{64}$/', $sourcePlanDigest))) {
            throw new InvalidArgumentException('Workflow approved Plan binding requires an immutable SHA-256 digest.');
        }
        if ($definition !== null && ($definition->id !== $definitionId || $definition->version !== $definitionVersion)) {
            throw new InvalidArgumentException('Workflow definition snapshot must match pinned identity/version.');
        }
    }
}
