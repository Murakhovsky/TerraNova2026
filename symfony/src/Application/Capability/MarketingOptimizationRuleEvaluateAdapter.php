<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingOptimizationRuleService;
use Kernel\Tenant\Model\TenantContext;

final readonly class MarketingOptimizationRuleEvaluateAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingOptimizationRuleService $rules){}
    public function binding():string{return 'query:marketing.optimization.rule.evaluate';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        return $this->rules->evaluate($actor,$input);
    }
}
