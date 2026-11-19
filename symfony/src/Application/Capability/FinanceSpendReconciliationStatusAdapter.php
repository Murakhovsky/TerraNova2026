<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Finance\Application\Service\FinanceSpendObservationService;
use Kernel\Tenant\Model\TenantContext;

/** Finance-only read; not an observation write or an accounting Expense. */
final readonly class FinanceSpendReconciliationStatusAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private FinanceSpendObservationService $finance){}
    public function binding():string{return 'query:finance.ad_spend.reconciliation.status';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        return $this->finance->status($actor,
            (string)$input['ad_account_ref'],(string)$input['window_start'],(string)$input['window_end'],
            (string)$input['currency'],(string)$input['timezone'],(string)$input['attribution_hash']);
    }
}
