<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Finance\Application\Contract\FinanceBudgetControlInterface;
use Kernel\Tenant\Model\TenantContext;

final readonly class FinanceBudgetReservationsListAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private FinanceBudgetControlInterface $finance){}
    public function binding():string{return 'query:finance.budget.reservation.list';}
    public function invoke(TenantContext $actor,array $input,string $key,string $trace):mixed
    {
        return ['items'=>$this->finance->reservations($actor,(string)$input['envelope_id'])];
    }
}

