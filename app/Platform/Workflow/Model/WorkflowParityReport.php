<?php
declare(strict_types=1);

namespace Platform\Workflow\Model;

/** Immutable, read-only report; never a permission to mutate/dispatch. */
final readonly class WorkflowParityReport
{
    /** @param list<string> $differences */
    public function __construct(
        public string $source,
        public string $sourceRunId,
        public WorkflowParityStatus $status,
        public array $differences,
    ) {}
}
