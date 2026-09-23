<?php
declare(strict_types=1);

namespace App\Application\Growth\ReadModel;

use Domains\Growth\Application\Contract\GrowthSignalPollingTargetRepositoryInterface;
use InvalidArgumentException;
use Kernel\Module\ActiveModuleResolver;

final readonly class GrowthSignalPollingStatusProvider
{
    public function __construct(
        private GrowthSignalPollingTargetRepositoryInterface $targets,
        private ActiveModuleResolver $modules,
        private bool $enabled=false,
        private int $intervalMinutes=15,
        private int $actorId=0,
        private int $signalLimit=100,
    ) {
        if($intervalMinutes<1||$intervalMinutes>1440){
            throw new InvalidArgumentException('Growth polling status interval must be between 1 and 1440 minutes.');
        }
        if($signalLimit<1||$signalLimit>500){
            throw new InvalidArgumentException('Growth polling status signal limit must be between 1 and 500.');
        }
    }

    /** @return array<string,mixed> */
    public function status(string $organizationId):array
    {
        $organizationId=trim($organizationId);
        if($organizationId===''||mb_strlen($organizationId)>64){
            throw new InvalidArgumentException('Growth polling status organization id is invalid.');
        }

        $target=$this->targets->targetForOrganization($organizationId);
        $collectors=array_values(array_filter(
            $target['collectors']??[],
            static fn(mixed $value):bool=>is_string($value)&&trim($value)!=='',
        ));
        sort($collectors,SORT_STRING);

        $sourceCounts=[];
        foreach(($target['source_counts']??[]) as $collector=>$count){
            if(!is_string($collector)||trim($collector)===''||!is_int($count)||$count<1)continue;
            $sourceCounts[trim($collector)]=$count;
        }
        ksort($sourceCounts,SORT_STRING);

        $moduleEnabled=$this->modules->isEnabled($organizationId,'growth');
        $actorConfigured=$this->actorId>0;
        $hasSources=$collectors!==[];
        $ready=$this->enabled&&$actorConfigured&&$moduleEnabled&&$hasSources;

        $reason=match(true){
            !$this->enabled=>'scheduler_disabled',
            !$actorConfigured=>'system_actor_missing',
            !$moduleEnabled=>'growth_module_disabled',
            !$hasSources=>'no_enabled_sources',
            default=>'ready',
        };

        return [
            'enabled'=>$this->enabled,
            'ready'=>$ready,
            'reason'=>$reason,
            'interval_minutes'=>$this->intervalMinutes,
            'actor_configured'=>$actorConfigured,
            'module_enabled'=>$moduleEnabled,
            'signal_limit'=>$this->signalLimit,
            'collectors'=>$collectors,
            'source_counts'=>$sourceCounts,
            'enabled_source_count'=>array_sum($sourceCounts),
        ];
    }
}
