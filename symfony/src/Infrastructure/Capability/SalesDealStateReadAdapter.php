<?php
declare(strict_types=1);

namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use DomainException;
use Domains\Sales\Application\Contract\DealStageRepositoryInterface;
use Domains\Sales\Application\Support\DealStateSnapshot;
use Kernel\Tenant\Model\TenantContext;

final readonly class SalesDealStateReadAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private DealStageRepositoryInterface $deals) {}
    public function binding():string { return 'query:sales.deal.state.read'; }

    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $id=$input['deal_id']??null;
        if (count($input)!==1 || !is_int($id) || $id<1) throw new DomainException('Invalid deal identity.');
        $org=$actor->organizationId()->value();$row=$this->deals->getForStageChange($org,(string)$id);
        return ['found'=>$row!==null,'state'=>$row===null?null:DealStateSnapshot::fromNative($row,$org,$id)];
    }
}
