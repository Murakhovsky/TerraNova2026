<?php
declare(strict_types=1);
namespace Domains\Federation\Application\Contract;

use Kernel\Tenant\Model\TenantContext;

/** Historical evidence only; never grants current approval or execution authority. */
interface FederationTrustedActionOutcomeReadPort
{
    public function read(TenantContext $actor,string $runId,string $evaluationId,string $capability):array;
}
