<?php
declare(strict_types=1);
namespace App\Infrastructure\Finance;

use Domains\Finance\Application\Contract\MarketingPaidSettlementReadPort;
use Domains\Marketing\Application\Service\MarketingPaidOperationsReadService;
use Kernel\Tenant\Model\TenantContext;

final readonly class MarketingPaidSettlementReadAdapter implements MarketingPaidSettlementReadPort
{
    public function __construct(private MarketingPaidOperationsReadService $marketing){}
    public function marketing(TenantContext $actor,string $planId,string $snapshotRef):array
    {return $this->marketing->settlementSource($actor,$planId,$snapshotRef);}
}
