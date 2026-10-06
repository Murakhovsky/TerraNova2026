<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Kernel\Observability\StructuredLoggerInterface;
use Kernel\Operations\Contract\MetricsRecorderInterface;

final readonly class ResearchTelemetry
{
    public function __construct(
        private MetricsRecorderInterface $metrics,
        private StructuredLoggerInterface $logger,
    ){}

    /** @param array<string,string|int|float|bool|null> $labels */
    public function metric(string $organizationId,string $name,float $value=1.0,array $labels=[]):void
    {
        $this->metrics->record('capital_markets.research.'.$name,$value,$organizationId,$labels);
    }

    /** @param array<string,mixed> $context */
    public function event(string $organizationId,string $event,array $context=[]):void
    {
        $this->logger->log('info','capital_markets.research.'.$event,[
            'organization_id'=>$organizationId,
            ...$context,
        ]);
    }

    /** @param array<string,mixed> $context */
    public function failure(string $organizationId,string $event,array $context=[]):void
    {
        $this->metric($organizationId,'failures_total',1.0,['event'=>$event]);
        $this->logger->log('warning','capital_markets.research.'.$event,[
            'organization_id'=>$organizationId,
            ...$context,
        ]);
    }
}
