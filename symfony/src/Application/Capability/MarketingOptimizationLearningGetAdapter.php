<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingOptimizationLearningService;
use Kernel\Tenant\Model\TenantContext;

final readonly class MarketingOptimizationLearningGetAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingOptimizationLearningService $service){}
    public function binding():string{return 'query:marketing.optimization.learning.get';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {return $this->service->get($actor,$input['learning_id']);}
}
