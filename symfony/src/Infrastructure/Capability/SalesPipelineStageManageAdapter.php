<?php
declare(strict_types=1);

namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\Sales\Command\ManageSalesDealCommand;
use DomainException;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Tenant\Model\TenantContext;

final readonly class SalesPipelineStageManageAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private CommandBusInterface $commands) {}
    public function binding():string { return 'command:sales.pipeline.stage.manage'; }

    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId,?\Kernel\Action\Action $approvedAction=null):mixed
    {
        $user=$actor->userId()->value();
        if (!ctype_digit($user) || (int)$user<1 || (string)(int)$user!==$user) throw new DomainException('Invalid deal operator.');
        $receipt=$this->commands->dispatch(new ManageSalesDealCommand($actor->organizationId(),(int)$user,'stage.manage',$input,$key,$correlationId,$approvedAction));
        if (!is_array($receipt) || ($receipt['state']['deal_id']??null)!==($input['deal_id']??null)
            || !is_bool($receipt['changed']??null) || !is_bool($receipt['replayed']??null)) throw new DomainException('Invalid native deal receipt.');
        return $receipt;
    }
}
