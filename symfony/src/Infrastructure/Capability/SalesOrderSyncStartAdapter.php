<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Sales\Application\Service\SalesOrderSyncService;
use Kernel\Tenant\Model\TenantContext;
use DomainException;

final readonly class SalesOrderSyncStartAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private SalesOrderSyncService $service){}
    public function binding():string{return 'command:sales.order.source.sync.start';}
    public function invoke(TenantContext $context,array $input,string $key,string $correlationId):mixed
    {
        $raw=$context->userId()->value();
        if(!ctype_digit($raw)||(int)$raw<1||(string)(int)$raw!==$raw)throw new DomainException('Invalid Sales actor.');
        $actor=(int)$raw;$org=$context->organizationId()->value();
        return $this->service->start($org,$actor,$input,$key,$correlationId);
    }
}
