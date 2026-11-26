<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingExperimentService;
use Kernel\Tenant\Model\TenantContext;

final readonly class MarketingExperimentRegisterAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingExperimentService $experiments){}
    public function binding():string{return 'command:marketing.experiment.register';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        return $this->experiments->register($actor,$input['control_plan_id'],$input['candidate_plan_id'],$input['factor'],$key);
    }
}
