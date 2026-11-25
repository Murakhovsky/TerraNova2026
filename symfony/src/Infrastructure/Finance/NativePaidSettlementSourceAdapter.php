<?php
declare(strict_types=1);
namespace App\Infrastructure\Finance;

use Domains\Finance\Application\Contract\FinancePaidSettlementSourcePort;
use Domains\Finance\Application\Contract\MarketingPaidSettlementReadPort;
use Domains\Finance\Application\Contract\DocumentsPaidSettlementReadPort;
use Kernel\Tenant\Model\TenantContext;

/** Composition only. Separate typed Domain owner adapters are mandatory. */
final readonly class NativePaidSettlementSourceAdapter implements FinancePaidSettlementSourcePort
{
    public function __construct(private MarketingPaidSettlementReadPort $marketing,private DocumentsPaidSettlementReadPort $documents){}
    public function marketing(TenantContext $actor,string $planId,string $snapshotRef):array
    {return $this->marketing->marketing($actor,$planId,$snapshotRef);}
    public function document(TenantContext $actor,string $documentRef):array
    {return $this->documents->document($actor,$documentRef);}
}
