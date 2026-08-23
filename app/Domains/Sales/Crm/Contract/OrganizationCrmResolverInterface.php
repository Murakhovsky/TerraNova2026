<?php
declare(strict_types=1);

namespace Domains\Sales\Crm\Contract;

interface OrganizationCrmResolverInterface
{
    public function providerFor(string $organizationId): string;
}
