<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Sales\Application\Service\SalesReportService;
use Kernel\Tenant\Model\TenantContext;
use DomainException;

final readonly class SalesReportDataAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private SalesReportService $reports) {}
    public function binding():string {return 'query:sales.reports.data.read';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $raw=$actor->userId()->value();
        if (!ctype_digit($raw)||(int)$raw<1||(string)(int)$raw!==$raw) throw new DomainException('Invalid Sales report actor.');
        return $this->reports->read($actor->organizationId()->value(),(int)$raw,$input);
    }
}
