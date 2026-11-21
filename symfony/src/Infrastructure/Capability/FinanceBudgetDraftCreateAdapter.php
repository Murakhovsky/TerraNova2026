<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Finance\Application\Contract\FinanceBudgetControlInterface;
use Kernel\Tenant\Model\TenantContext;

final readonly class FinanceBudgetDraftCreateAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private FinanceBudgetControlInterface $finance){}
    public function binding():string{return 'command:finance.budget.envelope.draft.create';}
    public function invoke(TenantContext $actor,array $input,string $key,string $trace):mixed
    {
        return $this->finance->createDraft($actor,$input,$key);
    }
}

