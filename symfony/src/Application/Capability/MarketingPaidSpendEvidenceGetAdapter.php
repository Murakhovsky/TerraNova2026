<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingPaidReadService;
use Kernel\Tenant\Model\TenantContext;

/** Typed Finance-facing readback: read-only, tenant-scoped, no provider or budget write. */
final readonly class MarketingPaidSpendEvidenceGetAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingPaidReadService $paid){}
    public function binding():string{return 'query:marketing.paid.spend.evidence.get';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        return [
            'evidence'=>$this->paid->spendEvidence(
                $actor,(string)$input['account_id'],(string)$input['source_snapshot_ref']
            ),
            'readback_status'=>'MARKETING_DB_SNAPSHOT',
            'finance_expense_confirmed'=>false,
        ];
    }
}
