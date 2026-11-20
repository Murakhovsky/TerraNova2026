<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingPaidReadService;
use Kernel\Tenant\Model\TenantContext;

final readonly class MarketingPaidAccountListAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingPaidReadService $paid){}
    public function binding():string{return 'query:marketing.paid.account.list';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        return ['accounts'=>$this->paid->accounts($actor)];
    }
}
