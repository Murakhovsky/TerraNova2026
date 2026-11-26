<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingAnalystContextService;
use Kernel\Tenant\Model\TenantContext;

final readonly class MarketingAnalystContextGetAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingAnalystContextService $contexts){}
    public function binding():string{return 'query:marketing.analyst.context.get';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        return $this->contexts->build($actor,$input['campaign_id'],$input['campaign_revision'],$input['creative_id'],
            $input['creative_revision'],$input['adset_id']??null,$input['adset_revision']??null,$input['paid_plan_id']??null,$input['snapshot_ref']??null);
    }
}
