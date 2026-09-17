<?php
declare(strict_types=1);

namespace Kernel\Agent\Model;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class AgentInstance
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public Agent $agent,
        public array $configuration = [],
        public bool $enabled = true,
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('Agent instance id cannot be empty.');
        }
    }
}
