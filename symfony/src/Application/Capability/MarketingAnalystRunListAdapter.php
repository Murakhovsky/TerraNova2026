<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingAnalystReviewService;
use Kernel\Tenant\Model\TenantContext;

final readonly class MarketingAnalystRunListAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingAnalystReviewService $reviews){}
    public function binding():string{return 'query:marketing.analyst.run.list';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        return ['items'=>$this->reviews->listRuns($actor,$input['campaign_id'],$input['limit']??50,$input['offset']??0)];
    }
}
