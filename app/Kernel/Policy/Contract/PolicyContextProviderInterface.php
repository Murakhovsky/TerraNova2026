<?php
declare(strict_types=1);

namespace Kernel\Policy\Contract;

use Kernel\Action\ActionProposal;

interface PolicyContextProviderInterface
{
    /** @return array<string,mixed> */
    public function context(string $organizationId, ActionProposal $proposal): array;
}
