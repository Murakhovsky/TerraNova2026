<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;

use Kernel\Tenant\Model\TenantContext;

/** Advisory runtime boundary. No adapter is authorized to create business Actions. */
interface MarketingAnalystRuntimePort
{
    /** @param array<string,mixed> $envelope @return array<string,mixed> */
    public function recommend(TenantContext $actor,array $envelope,string $correlationId):array;
}
