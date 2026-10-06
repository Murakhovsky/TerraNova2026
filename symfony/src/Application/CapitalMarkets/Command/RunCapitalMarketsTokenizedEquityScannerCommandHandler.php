<?php
declare(strict_types=1);

namespace App\Application\CapitalMarkets\Command;

use Domains\CapitalMarkets\Application\Contract\TokenizedEquityScannerRepositoryInterface;
use Domains\CapitalMarkets\Application\Service\TokenizedEquityScannerService;
use InvalidArgumentException;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Module\ActiveModuleResolver;
use Throwable;

final readonly class RunCapitalMarketsTokenizedEquityScannerCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private TokenizedEquityScannerRepositoryInterface $repository,
        private TokenizedEquityScannerService $scanner,
        private ActiveModuleResolver $modules,
        private int $intervalMinutes=5,
        private int $organizationLimit=500,
        private int $targetLimit=500,
    ){
        if($this->intervalMinutes<1||$this->intervalMinutes>1440)throw new InvalidArgumentException('Capital Markets scanner interval is invalid.');
        if($this->organizationLimit<1||$this->organizationLimit>5000)throw new InvalidArgumentException('Capital Markets scanner organization limit is invalid.');
        if($this->targetLimit<1||$this->targetLimit>2000)throw new InvalidArgumentException('Capital Markets scanner target limit is invalid.');
    }

    /** @return array<string,mixed> */
    public function __invoke(RunCapitalMarketsTokenizedEquityScannerCommand $command):array
    {
        $at=$command->atUnix??time();
        if($at<1)throw new InvalidArgumentException('Capital Markets scanner timestamp is invalid.');
        $seconds=$this->intervalMinutes*60;
        $bucket=gmdate('YmdHi',intdiv($at,$seconds)*$seconds);
        $organizations=$this->repository->schedulerOrganizations($this->organizationLimit);
        $runs=[];$completed=0;$failed=0;$disabled=0;

        foreach($organizations as $organizationId){
            if(!$this->modules->isEnabled($organizationId,'capital_markets')){
                $disabled++;$runs[]=['organization_id'=>$organizationId,'status'=>'MODULE_DISABLED'];continue;
            }
            try{
                $result=$this->scanner->run(
                    $organizationId,
                    trim($command->trigger)!==''?trim($command->trigger):'scheduler',
                    'scheduled-tokenized-equity:'.$bucket,
                    $this->targetLimit,
                );
                if(($result['status']??null)==='FAILED')$failed++;else $completed++;
                $runs[]=['organization_id'=>$organizationId,...$result];
            }catch(Throwable $error){
                $failed++;
                $runs[]=[
                    'organization_id'=>$organizationId,'status'=>'FAILED',
                    'error'=>mb_substr(trim($error->getMessage())!==''?$error->getMessage():get_class($error),0,500),
                ];
            }
        }

        return [
            'trigger'=>$command->trigger,'bucket'=>$bucket,'interval_minutes'=>$this->intervalMinutes,
            'organization_count'=>count($organizations),'completed_runs'=>$completed,'failed_runs'=>$failed,
            'skipped_disabled_organizations'=>$disabled,'runs'=>$runs,
        ];
    }
}
