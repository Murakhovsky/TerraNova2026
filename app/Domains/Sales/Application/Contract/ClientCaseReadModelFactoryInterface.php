<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface ClientCaseReadModelFactoryInterface
{
    public function forOrganization(string $organizationId): ClientCaseReadModelInterface;
}
