<?php
declare(strict_types=1);

namespace Kernel\Agent\Model;

use InvalidArgumentException;
use Kernel\Agent\AgentDefinition;

final readonly class Agent
{
    public function __construct(
        public string $id,
        public AgentDefinition $definition,
        public string $description = '',
        public array $tags = [],
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('Agent id cannot be empty.');
        }
    }
}
