<?php
declare(strict_types=1);

namespace App\Application\Growth\Command;

use Domains\Growth\Application\Contract\GrowthSignalCollectorBoundary;
use Domains\Growth\Application\Contract\GrowthSignalPollingTargetRepositoryInterface;
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
        $bucketSeconds=$this->intervalMinutes*60;
        $bucketStart=intdiv($at,$bucketSeconds)*$bucketSeconds;
        $bucket=gmdate('YmdHi',$bucketStart);

        $targets=$this->targets->targets($this->organizationLimit);
        $runs=[];
        $completed=0;
        $failed=0;
        $skippedDisabled=0;

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
                $idempotencyKey='scheduled-poll:'.$bucket.':'.$collectorName;
                $correlationId=CorrelationId::generate()->value();

                try{
                    $result=$this->collectors->runCollector(
                        $organizationId,$this->actorId,$correlationId,$collectorName,$idempotencyKey,null,$this->signalLimit,
                    );
                    $completed++;
                    $runs[]=[
                        'organization_id'=>$organizationId,
                        'collector'=>$collectorName,
                        'status'=>(string)($result['status']??'completed'),
                        'run_id'=>$result['run_id']??null,
                        'replayed'=>(bool)($result['replayed']??false),
                    ];
                }catch(Throwable $error){
                    $failed++;
                    $runs[]=[
                        'organization_id'=>$organizationId,
                        'collector'=>$collectorName,
                        'status'=>'failed',
                        'error'=>mb_substr(trim($error->getMessage())!==''?$error->getMessage():get_class($error),0,500),
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
            'failed_runs'=>$failed,
            'skipped_disabled_organizations'=>$skippedDisabled,
            'runs'=>$runs,
        ];
    }
}
