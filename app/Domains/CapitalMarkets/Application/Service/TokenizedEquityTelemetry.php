<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Domain\Observability\CapitalMarketsAlertType;
use Kernel\Observability\StructuredLoggerInterface;
use Kernel\Operations\Contract\MetricsRecorderInterface;

final readonly class TokenizedEquityTelemetry
{
    public function __construct(
        private MetricsRecorderInterface $metrics,
        private StructuredLoggerInterface $logger,
    ){}

    /** @param array<string,string|int|float|bool|null> $labels */
    public function metric(string $organizationId,string $name,float $value=1.0,array $labels=[]):void
    {
        $this->metrics->record('capital_markets.'.$name,$value,$organizationId,$labels);
    }

    /** @param array<string,mixed> $context */
    public function alert(string $organizationId,CapitalMarketsAlertType $type,array $context=[]):void
    {
        $this->metric($organizationId,'alerts_total',1.0,['type'=>$type->value]);
        $this->logger->log('warning','capital_markets.alert',[
            'organization_id'=>$organizationId,
            'alert_type'=>$type->value,
            ...$context,
        ]);
    }
}
