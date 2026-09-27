<?php
declare(strict_types=1);

namespace App\Application\Growth\Command;

use Domains\Growth\Application\Contract\GrowthAutonomousOutreachBoundary;
use Domains\Growth\Application\Contract\GrowthAutonomousOutreachRepositoryInterface;
use InvalidArgumentException;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Observability\CorrelationId;
use Throwable;

final readonly class RunGrowthAutonomousOutreachCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private GrowthAutonomousOutreachRepositoryInterface $repository,
        private GrowthAutonomousOutreachBoundary $outreach,
        private ActiveModuleResolver $modules,
        private int $actorId,
        private int $organizationLimit=500,
        private int $recommendationLimit=100,
    ) {
        if($actorId<1)throw new InvalidArgumentException('Growth autonomous outreach scheduler actor id must be positive.');
        if($organizationLimit<1||$organizationLimit>1000)throw new InvalidArgumentException('Growth autonomous outreach organization limit must be between 1 and 1000.');
        if($recommendationLimit<1||$recommendationLimit>100)throw new InvalidArgumentException('Growth autonomous outreach recommendation limit must be between 1 and 100.');
    }

    /** @return array<string,mixed> */
    public function __invoke(RunGrowthAutonomousOutreachCommand $command):array
    {
        $organizations=$this->repository->enabledOrganizations($this->organizationLimit);
        $runs=[];$triggered=0;$skipped=0;$replayed=0;$failed=0;$disabledModules=0;

        foreach($organizations as $organizationId){
            if(!$this->modules->isEnabled($organizationId,'growth')){
                $disabledModules++;
                $runs[]=['organization_id'=>$organizationId,'status'=>'module_disabled'];
                continue;
            }

            $policy=$this->outreach->viewPolicy($organizationId);
            $effective=is_array($policy['effective']??null)?$policy['effective']:[];
            $tenantLimit=max(1,min(100,(int)($effective['max_actions_per_run']??1)));
            $pending=$this->repository->pendingPayloads($organizationId,$this->recommendationLimit);
            $tenantTriggered=0;

            foreach($pending as $row){
                if($tenantTriggered>=$tenantLimit)break;
                $candidateId=trim((string)($row['candidate_id']??''));
                $recommendationId=trim((string)($row['recommendation_id']??''));
                if($candidateId===''||$recommendationId===''){
                    $failed++;$runs[]=['organization_id'=>$organizationId,'status'=>'invalid_payload_target'];continue;
                }
                $correlation=CorrelationId::generate()->value();
                try{
                    $result=$this->outreach->triggerRecommendation(
                        $organizationId,$this->actorId,$correlation,$candidateId,$recommendationId,
                        trim($command->trigger)!==''?trim($command->trigger):'scheduler',
                    );
                    $status=(string)($result['status']??'failed');
                    if($status==='triggered'){
                        $triggered++;$tenantTriggered++;
                    }elseif($status==='skipped'){
                        $skipped++;
                    }elseif($status==='replayed'){
                        $replayed++;
                    }else{
                        $failed++;
                    }
                    $runs[]=['organization_id'=>$organizationId,'candidate_id'=>$candidateId,'recommendation_id'=>$recommendationId]+$result;
                }catch(Throwable $error){
                    $failed++;
                    $runs[]=[
                        'organization_id'=>$organizationId,'candidate_id'=>$candidateId,'recommendation_id'=>$recommendationId,
                        'status'=>'failed','error'=>mb_substr(trim($error->getMessage())!==''?$error->getMessage():get_class($error),0,500),
                    ];
                }
            }
        }

        return [
            'trigger'=>trim($command->trigger)!==''?trim($command->trigger):'scheduler',
            'at_unix'=>$command->atUnix??time(),
            'target_organizations'=>count($organizations),
            'triggered'=>$triggered,'skipped'=>$skipped,'replayed'=>$replayed,'failed'=>$failed,
            'skipped_disabled_organizations'=>$disabledModules,'runs'=>$runs,
        ];
    }
}
