<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingCampaignService;
use Kernel\Tenant\Model\TenantContext;

final readonly class MarketingCampaignGetAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingCampaignService $marketing){}
    public function binding():string{return 'query:marketing.campaign.get';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        return ['campaign'=>$this->marketing->get($actor,(string)$input['id'])];
    }
}
