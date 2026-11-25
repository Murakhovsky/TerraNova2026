<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Infrastructure\Marketing\HermesAnalystReadinessService;
use Kernel\Tenant\Model\TenantContext;

final readonly class MarketingAnalystRuntimeStatusAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private HermesAnalystReadinessService $readiness){}
    public function binding():string{return 'query:marketing.analyst.runtime.status';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        return $this->readiness->status($actor);
    }
}
