<?php
declare(strict_types=1);

namespace App\Application\Growth\Command;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\Contract\GrowthSignalCollectorBoundary;
use Domains\Growth\Application\Contract\GrowthSignalPollingHealthRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthSignalPollingIncidentBoundary;
use Domains\Growth\Application\Contract\GrowthSignalPollingTargetRepositoryInterface;
use Domains\Growth\Domain\SignalPollingBackoffPolicy;
use InvalidArgumentException;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Observability\CorrelationId;
use Throwable;

final readonly class RunGrowthSignalPollingCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private GrowthSignalPollingTargetRepositoryInterface $targets,
        private GrowthSignalCollectorBoundary $collectors,
        private GrowthSignalPollingHealthRepositoryInterface $health,
        private GrowthSignalPollingIncidentBoundary $incidents,
        private SignalPollingBackoffPolicy $backoff,
        private ActiveModuleResolver $modules,
        private int $actorId,
        private int $intervalMinutes=15,
        private int $organizationLimit=500,
        private int $signalLimit=100,
    ) {
        if($actorId<1)throw new InvalidArgumentException('Growth polling scheduler actor id must be positive.');
        if($intervalMinutes<1||$intervalMinutes>1440){
            throw new InvalidArgumentException('Growth polling interval must be between 1 and 1440 minutes.');
        }
        if($organizationLimit<1||$organizationLimit>1000){
            throw new InvalidArgumentException('Growth polling organization limit must be between 1 and 1000.');
        }
        if($signalLimit<1||$signalLimit>500){
            throw new InvalidArgumentException('Growth polling signal limit must be between 1 and 500.');
        }
    }

    /** @return array<string,mixed> */
    public function __invoke(RunGrowthSignalPollingCommand $command):array
    {
        $at=$command->atUnix??time();
        if($at<1)throw new InvalidArgumentException('Growth polling command timestamp is invalid.');
        $now=(new DateTimeImmutable('@'.$at))->setTimezone(new DateTimeZone('UTC'));
        $bucketSeconds=$this->intervalMinutes*60;
        $bucketStart=intdiv($at,$bucketSeconds)*$bucketSeconds;
        $bucket=gmdate('YmdHi',$bucketStart);

        $targets=$this->targets->targets($this->organizationLimit);
        $runs=[];
        $completed=0;
        $degraded=0;
        $failed=0;
        $skippedDisabled=0;
        $skippedBackoff=0;

        foreach($targets as $target){
            $organizationId=trim((string)($target['organization_id']??''));
            $collectorNames=$target['collectors']??null;
            if($organizationId===''||!is_array($collectorNames)||!array_is_list($collectorNames)){
                $failed++;
                $runs[]=[
                    'organization_id'=>$organizationId,
                    'collector'=>null,
                    'status'=>'invalid_target',
                ];
                continue;
            }
            if(!$this->modules->isEnabled($organizationId,'growth')){
                $skippedDisabled++;
                $runs[]=[
                    'organization_id'=>$organizationId,
                    'collector'=>null,
                    'status'=>'module_disabled',
                ];
                continue;
            }

            foreach($collectorNames as $collectorName){
                if(!is_string($collectorName)||trim($collectorName)===''){
                    $failed++;
                    $runs[]=[
                        'organization_id'=>$organizationId,
                        'collector'=>null,
                        'status'=>'invalid_collector',
                    ];
                    continue;
                }
                $collectorName=trim($collectorName);
                $state=$this->health->state($organizationId,$collectorName);
                $nextRetryAt=$this->dateOrNull($state['next_retry_at']??null);
                if($this->backoff->isCoolingDown($nextRetryAt,$now)){
                    $skippedBackoff++;
                    $runs[]=[
                        'organization_id'=>$organizationId,
                        'collector'=>$collectorName,
                        'status'=>'cooling_down',
                        'consecutive_failures'=>(int)($state['consecutive_failures']??0),
                        'next_retry_at'=>$nextRetryAt?->format(DATE_ATOM),
                    ];
                    continue;
                }

                $idempotencyKey='scheduled-poll:'.$bucket.':'.$collectorName;
                $correlationId=CorrelationId::generate()->value();

                try{
                    $result=$this->collectors->runCollector(
                        $organizationId,$this->actorId,$correlationId,$collectorName,$idempotencyKey,null,$this->signalLimit,
                    );
                    $status=strtolower(trim((string)($result['status']??'completed')));
                    $replayed=(bool)($result['replayed']??false);
                    $summary=$this->summary($result['error_summary']??null);

                    if($status==='failed'){
                        $failed++;
                        $nextRetry=$this->backoff->nextRetryAt(
                            $now,
                            max(1,(int)($state['consecutive_failures']??0)+1),
                        );
                        if(!$replayed){
                            $failureCount=max(1,(int)($state['consecutive_failures']??0)+1);
                            $failureSummary=$summary??'Collector returned failed status.';
                            $this->health->markFailed(
                                $organizationId,$collectorName,$now,$nextRetry,$failureSummary,
                            );
                            $this->incidents->recordFailure(
                                $organizationId,$this->actorId,$correlationId,$collectorName,
                                $failureCount,$failureSummary,$now,$nextRetry,
                            );
                        }
                        $runs[]=[
                            'organization_id'=>$organizationId,
                            'collector'=>$collectorName,
                            'status'=>'failed',
                            'run_id'=>$result['run_id']??null,
                            'replayed'=>$replayed,
                            'next_retry_at'=>$nextRetry->format(DATE_ATOM),
                            'error'=>$summary,
                        ];
                        continue;
                    }

                    $completed++;
                    if($status==='partial'){
                        $degraded++;
                        if(!$replayed){
                            $this->health->markDegraded($organizationId,$collectorName,$now,$summary);
                            $this->incidents->recordRecovery(
                                $organizationId,$this->actorId,$correlationId,$collectorName,$now,
                            );
                        }
                    }elseif(!$replayed){
                        $this->health->markHealthy($organizationId,$collectorName,$now);
                        $this->incidents->recordRecovery(
                            $organizationId,$this->actorId,$correlationId,$collectorName,$now,
                        );
                    }

                    $runs[]=[
                        'organization_id'=>$organizationId,
                        'collector'=>$collectorName,
                        'status'=>$status===''?'completed':$status,
                        'run_id'=>$result['run_id']??null,
                        'replayed'=>$replayed,
                    ];
                }catch(Throwable $error){
                    $failed++;
                    $summary=mb_substr(trim($error->getMessage())!==''?$error->getMessage():get_class($error),0,500);
                    $nextRetry=$this->backoff->nextRetryAt(
                        $now,
                        max(1,(int)($state['consecutive_failures']??0)+1),
                    );
                    $failureCount=max(1,(int)($state['consecutive_failures']??0)+1);
                    $this->health->markFailed($organizationId,$collectorName,$now,$nextRetry,$summary);
                    $this->incidents->recordFailure(
                        $organizationId,$this->actorId,$correlationId,$collectorName,
                        $failureCount,$summary,$now,$nextRetry,
                    );
                    $runs[]=[
                        'organization_id'=>$organizationId,
                        'collector'=>$collectorName,
                        'status'=>'failed',
                        'error'=>$summary,
                        'next_retry_at'=>$nextRetry->format(DATE_ATOM),
                    ];
                }
            }
        }

        return [
            'trigger'=>trim($command->trigger)!==''?trim($command->trigger):'scheduler',
            'bucket'=>$bucket,
            'interval_minutes'=>$this->intervalMinutes,
            'target_organizations'=>count($targets),
            'completed_runs'=>$completed,
            'degraded_runs'=>$degraded,
            'failed_runs'=>$failed,
            'skipped_disabled_organizations'=>$skippedDisabled,
            'skipped_backoff_runs'=>$skippedBackoff,
            'runs'=>$runs,
        ];
    }

    private function dateOrNull(mixed $value): ?DateTimeImmutable
    {
        if($value===null||$value==='')return null;
        if(!is_string($value))throw new InvalidArgumentException('Growth polling health retry timestamp is invalid.');
        try{return new DateTimeImmutable($value,new DateTimeZone('UTC'));}
        catch(Throwable){throw new InvalidArgumentException('Growth polling health retry timestamp is invalid.');}
    }

    private function summary(mixed $value): ?string
    {
        if($value===null)return null;
        if(!is_scalar($value))return null;
        $value=trim((string)$value);
        return $value===''?null:mb_substr($value,0,500);
    }
}
