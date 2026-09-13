<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface CrmIngressResolverInterface
{
    /** @return array{organization_id:string,provider:string} */
    public function resolve(int $integrationId): array;
}
