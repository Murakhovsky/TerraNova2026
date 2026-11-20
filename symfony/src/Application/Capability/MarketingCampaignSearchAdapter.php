<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingCampaignService;
use Kernel\Tenant\Model\TenantContext;

final readonly class MarketingCampaignSearchAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingCampaignService $marketing){}
    public function binding():string{return 'query:marketing.campaign.search';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        return ['items'=>$this->marketing->search($actor,(string)($input['q']??''),(int)($input['limit']??50))];
    }
}
