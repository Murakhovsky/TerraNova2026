<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use Domains\Growth\Application\Contract\GrowthSignalPollingIncidentBoundary;
use Domains\Growth\Application\Contract\GrowthCollectorAlertSubscriptionRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthCollectorIncidentAlertGatewayInterface;
use Domains\Growth\Application\Contract\GrowthSignalPollingIncidentRepositoryInterface;
use Domains\Growth\Automation\Event\GrowthEventType;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use Throwable;

final readonly class GrowthSignalPollingIncidentService implements GrowthSignalPollingIncidentBoundary
{
    public function __construct(
        private GrowthSignalPollingIncidentRepositoryInterface $incidents,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private AuditRepositoryInterface $audit,
        private GrowthCollectorAlertSubscriptionRepositoryInterface $alertSubscriptions,
        private GrowthCollectorIncidentAlertGatewayInterface $alerts,
        private int $failureThreshold=3,
    ) {
        if($failureThreshold<1||$failureThreshold>100){
            throw new InvalidArgumentException('Growth collector incident failure threshold must be between 1 and 100.');
        }
    }

    public function recordFailure(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $collectorName,
        int $consecutiveFailures,
        string $errorSummary,
        DateTimeImmutable $failedAt,
        DateTimeImmutable $nextRetryAt,
    ): ?array {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $collectorName=$this->bounded($collectorName,'collectorName',120);
        $correlationId=$this->bounded($correlationId,'correlationId',191);
        $errorSummary=$this->bounded($errorSummary,'errorSummary',2000);
        if($actorId<1)throw new InvalidArgumentException('Growth collector incident actor id must be positive.');
        if($consecutiveFailures<1)return null;
        if($nextRetryAt<=$failedAt)throw new InvalidArgumentException('Growth collector incident retry timestamp is invalid.');
        if($consecutiveFailures<$this->failureThreshold)return null;

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$collectorName,$consecutiveFailures,
            $errorSummary,$failedAt,$nextRetryAt
        ):array{
            $existing=$this->incidents->openIncident($organizationId,$collectorName);
            $incidentId=$existing['incident_id']??null;
            $isNew=!is_string($incidentId)||trim($incidentId)==='';
            if($isNew){
                $incidentId='GCIN-'.$this->stableId(
                    $organizationId.':'.$collectorName.':'.$failedAt->format('Y-m-d H:i:s.u')
                );
            }

            $incident=$this->incidents->upsertOpen(
                $organizationId,$incidentId,$collectorName,$consecutiveFailures,$errorSummary,
                $failedAt,$failedAt,$nextRetryAt,
            );

            if($isNew){
                $payload=[
                    'incident_id'=>$incidentId,
                    'collector'=>$collectorName,
                    'failure_count'=>$consecutiveFailures,
                    'next_retry_at'=>$nextRetryAt->format(DATE_ATOM),
                ];
                $this->publish(
                    GrowthEventType::COLLECTOR_INCIDENT_OPENED,$organizationId,'growth_collector_incident',
                    $incidentId,$payload,$actorId,$correlationId,
                );
                $this->appendAudit(
                    $organizationId,$actorId,$correlationId,'growth.collector.incident_opened',
                    'growth_collector_incident',$incidentId,$payload,
                );
                $this->queueAlertsAfterCommit($organizationId,$incident,'opened',$correlationId);
            }

            return $incident;
        });
    }

    public function recordRecovery(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $collectorName,
        DateTimeImmutable $recoveredAt,
    ): ?array {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $collectorName=$this->bounded($collectorName,'collectorName',120);
        $correlationId=$this->bounded($correlationId,'correlationId',191);
        if($actorId<1)throw new InvalidArgumentException('Growth collector incident actor id must be positive.');

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$collectorName,$recoveredAt
        ):?array{
            $open=$this->incidents->openIncident($organizationId,$collectorName);
            if($open===null)return null;

            $resolved=$this->incidents->resolveOpen($organizationId,$collectorName,$recoveredAt)
                ?? throw new InvalidArgumentException('Growth collector incident could not be resolved.');
            $incidentId=(string)$resolved['incident_id'];
            $payload=[
                'incident_id'=>$incidentId,
                'collector'=>$collectorName,
                'failure_count'=>(int)($resolved['failure_count']??0),
                'resolved_at'=>$recoveredAt->format(DATE_ATOM),
            ];
            $this->publish(
                GrowthEventType::COLLECTOR_INCIDENT_RESOLVED,$organizationId,'growth_collector_incident',
                $incidentId,$payload,$actorId,$correlationId,
            );
            $this->appendAudit(
                $organizationId,$actorId,$correlationId,'growth.collector.incident_resolved',
                'growth_collector_incident',$incidentId,$payload,
            );
            $this->queueAlertsAfterCommit($organizationId,$resolved,'resolved',$correlationId);
            return $resolved;
        });
    }

    public function activeIncidents(string $organizationId): array
    {
        return $this->incidents->activeIncidents($this->bounded($organizationId,'organizationId',64));
    }

    /** @param array<string,mixed> $incident */
    private function queueAlertsAfterCommit(
        string $organizationId,array $incident,string $transition,string $correlationId
    ):void {
        $this->transactions->afterCommit(function()use($organizationId,$incident,$transition,$correlationId):void{
            foreach($this->alertSubscriptions->listEnabled($organizationId,100) as $subscription){
                try{
                    $this->alerts->queue($organizationId,$subscription,$incident,$transition,$correlationId);
                }catch(Throwable $error){
                    error_log(sprintf(
                        'Growth collector incident alert failed for %s/%s: %s',
                        $organizationId,(string)($subscription['subscription_id']??'unknown'),$error->getMessage(),
                    ));
                }
            }
        });
    }

    /** @param array<string,mixed> $payload */
    private function publish(
        string $type,string $organizationId,string $aggregateType,string $aggregateId,
        array $payload,int $actorId,string $correlationId
    ): void {
        $this->events->publish(new DomainEvent(
            bin2hex(random_bytes(16)),$organizationId,$type,$aggregateType,$aggregateId,$payload,
            new EventMetadata($correlationId,null,'SYSTEM',(string)$actorId),new DateTimeImmutable(),
        ));
    }

    /** @param array<string,mixed> $data */
    private function appendAudit(
        string $organizationId,int $actorId,string $correlationId,string $action,
        string $subjectType,string $subjectId,array $data
    ): void {
        $this->audit->append(new AuditEntry(
            bin2hex(random_bytes(16)),$organizationId,'growth.collector_incident','SYSTEM',(string)$actorId,
            $subjectType,$subjectId,null,['action'=>$action,'result'=>$data],$correlationId,new DateTimeImmutable(),
        ));
    }

    private function bounded(string $value,string $field,int $limit): string
    {
        $value=trim($value);
        if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');
        return $value;
    }

    private function stableId(string $value): string
    {
        return strtoupper(substr(hash('sha256',$value),0,20));
    }
}
