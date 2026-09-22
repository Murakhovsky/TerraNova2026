<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Application\Contract\GrowthRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthSignalCollectorBoundary;
use Domains\Growth\Application\Contract\GrowthSignalIntakeRepositoryInterface;
use Domains\Growth\Application\DTO\CollectedSignal;
use Domains\Growth\Application\DTO\SignalCollectionRequest;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Domain\Signal;
use Domains\Growth\Domain\SignalCollectorRunStatus;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use Throwable;

final readonly class GrowthSignalCollectorService implements GrowthSignalCollectorBoundary
{
    public function __construct(
        private SignalCollectorRegistry $registry,
        private GrowthSignalIntakeRepositoryInterface $intake,
        private GrowthRepositoryInterface $growth,
        private GrowthMutationReceiptInterface $receipts,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private AuditRepositoryInterface $audit,
    ) {}

    public function runCollector(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $collectorName,
        string $idempotencyKey,
        ?string $cursor = null,
        int $limit = 100,
    ): array {
        $organizationId=$this->bounded(trim($organizationId),'organizationId',64);
        $collectorName=$this->bounded(trim($collectorName),'collectorName',120);
        $idempotencyKey=$this->bounded(trim($idempotencyKey),'idempotencyKey',191);
        if($cursor!==null){
            $cursor=trim($cursor);
            if($cursor===''||mb_strlen($cursor)>1000)throw new InvalidArgumentException('Growth collector cursor is invalid.');
        }
        if($limit<1||$limit>500)throw new InvalidArgumentException('Growth collector limit must be between 1 and 500.');

        $collector=$this->registry->get($collectorName);
        $runId='GCRN-'.$this->stableId($organizationId.':collector_run:'.$idempotencyKey);
        $fingerprint=$this->fingerprint(['collector'=>$collectorName,'cursor'=>$cursor,'limit'=>$limit]);

        $replay=$this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$collectorName,$cursor,$limit,$idempotencyKey,$runId,$fingerprint
        ):?array{
            if(!$this->receipts->claim($organizationId,'run_signal_collector',$idempotencyKey,$fingerprint)){
                return ($this->intake->viewRun($organizationId,$runId)
                    ?? throw new InvalidArgumentException('Growth collector receipt exists but run was not found.'))
                    + ['replayed'=>true];
            }
            $this->intake->createRun($organizationId,$runId,$collectorName,$cursor,$limit,$actorId);
            $this->publish(GrowthEventType::COLLECTOR_RUN_STARTED,$organizationId,'growth_collector_run',$runId,[
                'collector'=>$collectorName,'cursor'=>$cursor,'limit'=>$limit,
            ],$actorId,$correlationId);
            return null;
        });
        if($replay!==null)return $replay;

        try{
            $batch=$collector->collect(new SignalCollectionRequest($organizationId,$cursor,$limit));
            if(count($batch->items)>$limit){
                throw new InvalidArgumentException('Growth collector returned more items than requested.');
            }
        }catch(Throwable $error){
            $summary=$this->errorSummary([$error]);
            return $this->transactions->transactional(function()use(
                $organizationId,$actorId,$correlationId,$collectorName,$runId,$idempotencyKey,$summary
            ):array{
                $this->intake->failRun($organizationId,$runId,$summary);
                $this->publish(GrowthEventType::COLLECTOR_RUN_FAILED,$organizationId,'growth_collector_run',$runId,[
                    'collector'=>$collectorName,'error'=>$summary,
                ],$actorId,$correlationId);
                $this->appendAudit($organizationId,$actorId,$correlationId,'growth.collector.failed','growth_collector_run',$runId,$idempotencyKey,[
                    'collector'=>$collectorName,'error'=>$summary,
                ]);
                return $this->intake->viewRun($organizationId,$runId)
                    ?? throw new InvalidArgumentException('Failed Growth collector run could not be read back.');
            });
        }

        $accepted=0;
        $duplicates=0;
        $failed=0;
        $errors=[];

        foreach($batch->items as $item){
            try{
                $result=$this->ingestItem($organizationId,$actorId,$correlationId,$collectorName,$item);
                if($result==='accepted')$accepted++; else $duplicates++;
            }catch(Throwable $error){
                $failed++;
                if(count($errors)<5)$errors[]=$error;
            }
        }

        $status=$failed>0?SignalCollectorRunStatus::Partial:SignalCollectorRunStatus::Completed;
        $summary=$errors===[]?null:$this->errorSummary($errors);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$collectorName,$runId,$idempotencyKey,$batch,
            $accepted,$duplicates,$failed,$status,$summary
        ):array{
            $this->intake->completeRun(
                $organizationId,$runId,$status->value,count($batch->items),$accepted,$duplicates,$failed,$batch->nextCursor,$summary,
            );
            $this->publish(GrowthEventType::COLLECTOR_RUN_COMPLETED,$organizationId,'growth_collector_run',$runId,[
                'collector'=>$collectorName,'status'=>$status->value,'collected_count'=>count($batch->items),
                'accepted_count'=>$accepted,'duplicate_count'=>$duplicates,'failed_count'=>$failed,'next_cursor'=>$batch->nextCursor,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'growth.collector.completed','growth_collector_run',$runId,$idempotencyKey,[
                'collector'=>$collectorName,'status'=>$status->value,'accepted'=>$accepted,'duplicates'=>$duplicates,'failed'=>$failed,
            ]);
            return $this->intake->viewRun($organizationId,$runId)
                ?? throw new InvalidArgumentException('Completed Growth collector run could not be read back.');
        });
    }

    public function collectors(): array
    {
        return $this->registry->names();
    }

    private function ingestItem(
        string $organizationId,int $actorId,string $correlationId,string $collectorName,CollectedSignal $item
    ): string {
        $fingerprint=$this->fingerprint($item->fingerprintPayload());
        $signalId='GSIG-'.$this->stableId($organizationId.':collector:'.$collectorName.':'.$item->externalKey);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$collectorName,$item,$fingerprint,$signalId
        ):string{
            if(!$this->intake->claimSource(
                $organizationId,$collectorName,$item->externalKey,$fingerprint,$signalId
            )){
                return 'duplicate';
            }

            $signal=new Signal(
                $signalId,OrganizationId::fromString($organizationId),$item->subjectType,$item->subjectId,$item->signalType,
                $item->facts,$item->sourceReference,$item->confidence,$item->occurredAt,$this->now(),
            );
            $this->growth->createSignal($signal,$actorId);
            $this->publish(GrowthEventType::SIGNAL_DETECTED,$organizationId,'growth_signal',$signalId,[
                'collector'=>$collectorName,'external_key'=>$item->externalKey,'subject_type'=>$item->subjectType,
                'subject_id'=>$item->subjectId,'signal_type'=>$item->signalType,'source_reference'=>$item->sourceReference,
                'confidence'=>$item->confidence,
            ],$actorId,$correlationId);
            $this->appendAudit(
                $organizationId,$actorId,$correlationId,'growth.signal.ingest','growth_signal',$signalId,
                $collectorName.':'.$item->externalKey,['collector'=>$collectorName,'external_key_hash'=>hash('sha256',$item->externalKey)],
            );
            return 'accepted';
        });
    }

    /** @param list<Throwable> $errors */
    private function errorSummary(array $errors): string
    {
        $messages=[];
        foreach($errors as $error){
            $message=trim($error->getMessage());
            $messages[]=$message===''?get_class($error):get_class($error).': '.$message;
        }
        return mb_substr(implode(' | ',$messages),0,2000);
    }

    private function bounded(string $value,string $field,int $limit): string
    {
        if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');
        return $value;
    }

    /** @param array<string,mixed> $payload */
    private function publish(string $type,string $organizationId,string $aggregateType,string $aggregateId,array $payload,int $actorId,string $correlationId): void
    {
        $this->events->publish(new DomainEvent(
            bin2hex(random_bytes(16)),$organizationId,$type,$aggregateType,$aggregateId,$payload,
            new EventMetadata($correlationId,null,'SYSTEM',(string)$actorId),$this->now(),
        ));
    }

    /** @param array<string,mixed> $data */
    private function appendAudit(
        string $organizationId,int $actorId,string $correlationId,string $action,string $subjectType,
        string $subjectId,string $idempotencyKey,array $data=[]
    ): void {
        $this->audit->append(new AuditEntry(
            bin2hex(random_bytes(16)),$organizationId,'growth.collector','SYSTEM',(string)$actorId,$subjectType,$subjectId,null,
            ['action'=>$action,'idempotency_key_hash'=>hash('sha256',$idempotencyKey),'result'=>$data],$correlationId,$this->now(),
        ));
    }

    private function stableId(string $value): string { return strtoupper(substr(hash('sha256',$value),0,20)); }

    /** @param array<string,mixed> $value */
    private function fingerprint(array $value): string
    {
        $normalize=function(mixed $item)use(&$normalize):mixed{
            if(!is_array($item))return $item;
            if(array_is_list($item))return array_map($normalize,$item);
            ksort($item,SORT_STRING);
            foreach($item as $key=>$nested)$item[$key]=$normalize($nested);
            return $item;
        };
        return hash('sha256',(string)json_encode($normalize($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
    }

    private function now(): DateTimeImmutable { return new DateTimeImmutable('now',new DateTimeZone('UTC')); }
}
