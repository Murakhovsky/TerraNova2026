<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingAnalystReviewService;
use Kernel\Tenant\Model\TenantContext;

final readonly class MarketingAnalystRunReviewAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingAnalystReviewService $reviews){}
    public function binding():string{return 'command:marketing.analyst.run.review';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        return $this->reviews->review($actor,$input['campaign_id'],$input['run_id'],$input['decision'],$input['reason'],$key);
    }
}
