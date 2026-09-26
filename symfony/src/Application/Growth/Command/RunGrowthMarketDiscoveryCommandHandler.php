<?php
declare(strict_types=1);

namespace App\Application\Growth\Command;

use Domains\Growth\Application\Contract\GrowthMarketDiscoveryBoundary;
use Domains\Growth\Application\Contract\GrowthMarketDiscoveryRepositoryInterface;
use InvalidArgumentException;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Observability\CorrelationId;
use Throwable;

final readonly class RunGrowthMarketDiscoveryCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private GrowthMarketDiscoveryRepositoryInterface $repository,
        private GrowthMarketDiscoveryBoundary $market,
        private ActiveModuleResolver $modules,
        private int $actorId,
        private int $intervalMinutes=60,
        private int $universeLimit=200,
        private int $accountLimit=100,
    ) {
        if($actorId<1)throw new InvalidArgumentException('Growth market scheduler actor id must be positive.');
        if($intervalMinutes<1||$intervalMinutes>1440)throw new InvalidArgumentException('Growth market scheduler interval is invalid.');
        if($universeLimit<1||$universeLimit>1000)throw new InvalidArgumentException('Growth market universe limit is invalid.');
        if($accountLimit<1||$accountLimit>200)throw new InvalidArgumentException('Growth market account limit is invalid.');
    }

    /** @return array<string,mixed> */
    public function __invoke(RunGrowthMarketDiscoveryCommand $command):array
    {
        $at=$command->atUnix??time();
        if($at<1)throw new InvalidArgumentException('Growth market scheduler timestamp is invalid.');
        $bucketSeconds=$this->intervalMinutes*60;
        $bucket=gmdate('YmdHi',intdiv($at,$bucketSeconds)*$bucketSeconds);
        $targets=$this->repository->schedulerUniverses($this->universeLimit);
        $runs=[];$completed=0;$failed=0;$disabled=0;

        foreach($targets as $target){
            $organizationId=trim((string)($target['organization_id']??''));
            $universeId=trim((string)($target['universe_id']??''));
            if($organizationId===''||$universeId===''){$failed++;continue;}
            if(!$this->modules->isEnabled($organizationId,'growth')){
                $disabled++;$runs[]=['organization_id'=>$organizationId,'universe_id'=>$universeId,'status'=>'module_disabled'];continue;
            }
            try{
                $result=$this->market->runUniverse(
                    $organizationId,$this->actorId,CorrelationId::generate()->value(),$universeId,
                    'scheduled-market:'.$bucket.':'.$universeId,$this->accountLimit,
                );
                $status=(string)($result['status']??'completed');
                if($status==='failed')$failed++;else$completed++;
                $runs[]=[
                    'organization_id'=>$organizationId,'universe_id'=>$universeId,'status'=>$status,
                    'run_id'=>$result['run_id']??null,'replayed'=>(bool)($result['replayed']??false),
                ];
            }catch(Throwable $error){
                $failed++;
                $runs[]=[
                    'organization_id'=>$organizationId,'universe_id'=>$universeId,'status'=>'failed',
                    'error'=>mb_substr(trim($error->getMessage())!==''?$error->getMessage():get_class($error),0,500),
                ];
            }
        }

        return [
            'trigger'=>trim($command->trigger)!==''?trim($command->trigger):'scheduler',
            'bucket'=>$bucket,'interval_minutes'=>$this->intervalMinutes,'target_universes'=>count($targets),
            'completed_runs'=>$completed,'failed_runs'=>$failed,'skipped_disabled_organizations'=>$disabled,'runs'=>$runs,
        ];
    }
}
