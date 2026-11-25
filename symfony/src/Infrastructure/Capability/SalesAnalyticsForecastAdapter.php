<?php
declare(strict_types=1);

namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Sales\Application\Service\SalesCommercialAnalyticsService;
use Kernel\Tenant\Model\TenantContext;
use DomainException;

final readonly class SalesAnalyticsForecastAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private SalesCommercialAnalyticsService $analytics) {}
    public function binding():string {return 'query:sales.analytics.forecast.read';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $raw=$actor->userId()->value();
        if (!ctype_digit($raw)||(int)$raw<1||(string)(int)$raw!==$raw) throw new DomainException('Invalid Sales analytics actor.');
        return $this->analytics->read($actor->organizationId()->value(),(int)$raw,'forecast',$input);
    }
}
