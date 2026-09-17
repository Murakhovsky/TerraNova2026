<?php
declare(strict_types=1);

namespace Kernel\Workflow\Model;

use InvalidArgumentException;

final readonly class Workflow
{
    public function __construct(
        public string $id,
        public WorkflowDefinition $definition,
        public string $description = '',
        public array $tags = [],
    ) {
        if (trim($id) === '') throw new InvalidArgumentException('Workflow id cannot be empty.');
        if ($id !== $definition->id) throw new InvalidArgumentException('Workflow id must match its definition id.');
    }
}
