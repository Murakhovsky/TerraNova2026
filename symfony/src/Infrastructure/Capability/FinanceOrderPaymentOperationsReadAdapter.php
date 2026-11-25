<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Finance\Infrastructure\Persistence\MySql\MysqlCommerceOrderPaymentStore;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use DomainException;
final readonly class FinanceOrderPaymentOperationsReadAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MysqlCommerceOrderPaymentStore $payments){}
    public function binding():string{return 'query:finance.order.payment.operations.read';}
    public function invoke(TenantContext $actor,array $input,string $key,string $trace):mixed
    {
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE))throw new DomainException('Finance tenant manager required.');
        return $this->payments->operations($actor->organizationId()->value(),$input['order_id'],$input['offset']??0,$input['limit']??25);
    }
}
