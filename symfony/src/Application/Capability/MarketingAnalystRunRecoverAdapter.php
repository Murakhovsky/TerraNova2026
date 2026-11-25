<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingAnalystReviewService;
use Kernel\Tenant\Model\TenantContext;

final readonly class MarketingAnalystRunRecoverAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingAnalystReviewService $reviews){}
    public function binding():string{return 'command:marketing.analyst.run.recover';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        return $this->reviews->recover($actor,$input['campaign_id'],$input['run_id'],$input['reason'],$input['evidence_ref'],$input['local_and_remote_stopped'],$key);
    }
}
