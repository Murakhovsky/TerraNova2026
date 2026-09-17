<?php
declare(strict_types=1);

namespace Kernel\Agent\Model;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;

final readonly class AgentContext
{
    public function __construct(
        public OrganizationId $organizationId,
        public string $correlationId,
        public array $input = [],
        public array $data = [],
        public ?UserId $requestedBy = null,
        public array $metadata = [],
    ) {
        if (trim($correlationId) === '') {
            throw new InvalidArgumentException('Agent correlation id cannot be empty.');
        }
    }
}
