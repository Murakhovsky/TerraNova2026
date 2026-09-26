<?php
declare(strict_types=1);

namespace App\Application\Growth\Command;

use Domains\Growth\Application\Contract\GrowthAutonomousContentBoundary;
use Domains\Growth\Application\Contract\GrowthAutonomousContentRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthAutonomousOutreachRepositoryInterface;
use InvalidArgumentException;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Observability\CorrelationId;
use Throwable;

final readonly class RunGrowthAutonomousContentCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private GrowthAutonomousOutreachRepositoryInterface $autonomy,
        private GrowthAutonomousContentRepositoryInterface $repository,
        private GrowthAutonomousContentBoundary $content,
        private ActiveModuleResolver $modules,
        private int $actorId,
        private int $intervalMinutes=5,
        private int $organizationLimit=500,
        private int $draftLimit=20,
    ) {
        if($actorId<1)throw new InvalidArgumentException('Growth autonomous content scheduler actor id must be positive.');
        if($intervalMinutes<1||$intervalMinutes>1440)throw new InvalidArgumentException('Growth autonomous content interval must be between 1 and 1440 minutes.');
        if($organizationLimit<1||$organizationLimit>1000)throw new InvalidArgumentException('Growth autonomous content organization limit must be between 1 and 1000.');
        if($draftLimit<1||$draftLimit>100)throw new InvalidArgumentException('Growth autonomous content draft limit must be between 1 and 100.');
    }

    /** @return array<string,mixed> */
    public function __invoke(RunGrowthAutonomousContentCommand $command):array
    {
        $at=$command->atUnix??time();
        if($at<1)throw new InvalidArgumentException('Growth autonomous content command timestamp is invalid.');
        $bucketSeconds=$this->intervalMinutes*60;
        $bucket=gmdate('YmdHi',intdiv($at,$bucketSeconds)*$bucketSeconds);
        $organizations=$this->autonomy->enabledOrganizations($this->organizationLimit);
        $runs=[];$generated=0;$autoApproved=0;$pendingReview=0;$failed=0;$disabledModules=0;

        foreach($organizations as $organizationId){
            if(!$this->modules->isEnabled($organizationId,'growth')){
                $disabledModules++;$runs[]=['organization_id'=>$organizationId,'status'=>'module_disabled'];continue;
            }
            foreach($this->repository->draftCandidates($organizationId,$this->draftLimit) as $row){
                $candidateId=trim((string)($row['candidate_id']??''));$recommendationId=trim((string)($row['recommendation_id']??''));
                if($candidateId===''||$recommendationId===''){
                    $failed++;$runs[]=['organization_id'=>$organizationId,'status'=>'invalid_candidate'];continue;
                }
                try{
                    $result=$this->content->generateDraft(
                        $organizationId,$this->actorId,CorrelationId::generate()->value(),$candidateId,$recommendationId,
                        'scheduled-content:'.$bucket.':'.$recommendationId,'SYSTEM',
                    );
                    $draft=is_array($result['latest_draft']??null)?$result['latest_draft']:(is_array($result['draft']??null)?$result['draft']:null);
                    $status=is_array($draft)?(string)($draft['status']??'generated'):'generated';
                    $generated++;
                    if($status==='approved')$autoApproved++;
                    elseif($status==='pending_review')$pendingReview++;
                    $runs[]=['organization_id'=>$organizationId,'candidate_id'=>$candidateId,'recommendation_id'=>$recommendationId,'status'=>$status];
                }catch(Throwable $error){
                    $failed++;
                    $runs[]=[
                        'organization_id'=>$organizationId,'candidate_id'=>$candidateId,'recommendation_id'=>$recommendationId,'status'=>'failed',
                        'error'=>mb_substr(trim($error->getMessage())!==''?$error->getMessage():get_class($error),0,500),
                    ];
                }
            }
        }
        return [
            'trigger'=>trim($command->trigger)!==''?trim($command->trigger):'scheduler','bucket'=>$bucket,
            'target_organizations'=>count($organizations),'generated_drafts'=>$generated,'auto_approved_drafts'=>$autoApproved,
            'pending_review_drafts'=>$pendingReview,'failed_drafts'=>$failed,'skipped_disabled_organizations'=>$disabledModules,'runs'=>$runs,
        ];
    }
}
