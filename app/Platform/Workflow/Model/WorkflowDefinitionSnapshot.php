<?php
declare(strict_types=1);

namespace Platform\Workflow\Model;

use DomainException;

/**
 * Pinned graph projection for step selection. A Plan/definition owner controls
 * approval/version provenance separately; this DTO cannot grant execution.
 */
final readonly class WorkflowDefinitionSnapshot
{
    /** @param list<array<string,mixed>> $steps */
    public function __construct(
        public string $id,
        public string $version,
        public array $steps,
    ) {
        if (trim($id) === '' || trim($version) === '') {
            throw new DomainException('Workflow definition identity and version are required.');
        }
    }
}
