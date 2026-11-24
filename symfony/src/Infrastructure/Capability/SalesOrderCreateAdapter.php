<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Sales\Application\Service\SalesOrderService;
use Kernel\Tenant\Model\TenantContext;
use DomainException;

final readonly class SalesOrderCreateAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private SalesOrderService $service){}
    public function binding():string{return 'command:sales.order.create';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $raw=$actor->userId()->value();
        if (!ctype_digit($raw)||(int)$raw<1||(string)(int)$raw!==$raw) throw new DomainException('Invalid Sales actor.');
        return $this->service->create($actor->organizationId()->value(),(int)$raw,$input,$key,$correlationId);
    }
}
