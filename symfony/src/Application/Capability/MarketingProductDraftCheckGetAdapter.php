<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingProductDraftCheckService;
use Kernel\Tenant\Model\TenantContext;

/** Canonical Query only, with Product Management as the source of truth. */
final readonly class MarketingProductDraftCheckGetAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingProductDraftCheckService $checks){}
    public function binding():string{return 'query:marketing.product.draft_check.get';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        return $this->checks->check($actor,(string)$input['product_id'],
            isset($input['variant_id'])?(string)$input['variant_id']:null,(string)$input['currency']);
    }
}
