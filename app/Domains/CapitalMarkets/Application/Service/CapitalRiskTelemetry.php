<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Application\Service;
use Domains\CapitalMarkets\Domain\Observability\CapitalMarketsAlertType;
use Kernel\Observability\StructuredLoggerInterface;
use Kernel\Operations\Contract\MetricsRecorderInterface;
final readonly class CapitalRiskTelemetry
{
 public function __construct(private MetricsRecorderInterface $metrics,private StructuredLoggerInterface $logger){}
 public function metric(string $organizationId,string $name,string|int $value,array $labels=[]):void
 {
  $this->metrics->record('capital_markets.'.$name,(float)$value,$organizationId,$labels);
 }
 public function alert(string $organizationId,CapitalMarketsAlertType $type,array $context=[]):void
 {
  $this->metrics->record('capital_markets.alerts_total',1.0,$organizationId,['type'=>$type->value]);
  $this->logger->log('warning','capital_markets.portfolio_alert',['organization_id'=>$organizationId,'alert_type'=>$type->value,...$context]);
 }
}
