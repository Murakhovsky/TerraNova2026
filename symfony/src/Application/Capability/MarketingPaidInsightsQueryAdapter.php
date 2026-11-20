<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingPaidReadService;
use Kernel\Tenant\Model\TenantContext;

final readonly class MarketingPaidInsightsQueryAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingPaidReadService $paid){}
    public function binding():string{return 'query:marketing.paid.insights.query';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        return ['snapshots'=>$this->paid->insights($actor,(string)$input['account_id'],(string)$input['grain'],(string)$input['from'],(string)$input['to'])];
    }
}
