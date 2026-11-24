<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingProductDraftCheckService;
use Kernel\Tenant\Model\TenantContext;

final readonly class MarketingProductSelectorSearchAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingProductDraftCheckService $checks){}
    public function binding():string{return 'query:marketing.product.selector.search';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        return $this->checks->search($actor,(string)($input['q']??''),(int)($input['limit']??20),(int)($input['offset']??0));
    }
}
