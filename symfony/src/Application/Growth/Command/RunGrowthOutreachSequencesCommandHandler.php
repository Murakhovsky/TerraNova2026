<?php
declare(strict_types=1);
namespace App\Application\Growth\Command;
use Domains\Growth\Application\Contract\GrowthOutreachSequenceBoundary;
use Domains\Growth\Application\Contract\GrowthOutreachSequenceRepositoryInterface;
use InvalidArgumentException;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Observability\CorrelationId;
use Throwable;
final readonly class RunGrowthOutreachSequencesCommandHandler implements CommandHandlerInterface
{
    public function __construct(private GrowthOutreachSequenceRepositoryInterface $repository,private GrowthOutreachSequenceBoundary $sequences,private ActiveModuleResolver $modules,private int $actorId,private int $organizationLimit=500){if($actorId<1)throw new InvalidArgumentException('Growth sequence scheduler actor id must be positive.');if($organizationLimit<1||$organizationLimit>1000)throw new InvalidArgumentException('Growth sequence organization limit must be between 1 and 1000.');}
    public function __invoke(RunGrowthOutreachSequencesCommand $command):array{$at=$command->atUnix??time();if($at<1)throw new InvalidArgumentException('Growth sequence command timestamp is invalid.');$bucket=gmdate('YmdHi',$at);$orgs=$this->repository->schedulerOrganizations($this->organizationLimit);$runs=[];$failed=0;$disabled=0;foreach($orgs as $o){if(!$this->modules->isEnabled($o,'growth')){$disabled++;$runs[]=['organization_id'=>$o,'status'=>'module_disabled'];continue;}try{$runs[]=['organization_id'=>$o]+$this->sequences->runOrganization($o,$this->actorId,CorrelationId::generate()->value(),'scheduled-sequences:'.$bucket.':'.$o);}catch(Throwable $e){$failed++;$runs[]=['organization_id'=>$o,'status'=>'failed','error'=>mb_substr($e->getMessage(),0,500)];}}return ['trigger'=>$command->trigger,'bucket'=>$bucket,'target_organizations'=>count($orgs),'failed_organizations'=>$failed,'skipped_disabled_organizations'=>$disabled,'runs'=>$runs];}
}
