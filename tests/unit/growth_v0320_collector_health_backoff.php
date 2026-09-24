<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use App\Application\Growth\Command\RunGrowthSignalPollingCommand;
use App\Application\Growth\Command\RunGrowthSignalPollingCommandHandler;
use App\Application\Growth\ReadModel\GrowthSignalPollingStatusProvider;
use DateTimeImmutable;
use Domains\Growth\Application\Contract\GrowthSignalCollectorBoundary;
use Domains\Growth\Application\Contract\GrowthSignalPollingHealthRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthSignalPollingTargetRepositoryInterface;
use Domains\Growth\Domain\SignalPollingBackoffPolicy;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleManifest;

function expectGrowthV0320(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$policy=new SignalPollingBackoffPolicy(15,120);
expectGrowthV0320($policy->delayMinutes(1)===15,'First Growth polling failure must use base cadence.');
expectGrowthV0320($policy->delayMinutes(2)===30,'Second Growth polling failure must double backoff.');
expectGrowthV0320($policy->delayMinutes(3)===60,'Third Growth polling failure must double backoff again.');
expectGrowthV0320($policy->delayMinutes(5)===120,'Growth polling backoff must respect configured cap.');

$at=1760000000;
$now=(new DateTimeImmutable('@'.$at));
$targets=new class implements GrowthSignalPollingTargetRepositoryInterface {
    public function targets(int $organizationLimit=500):array
    {
        return [
            ['organization_id'=>'org-a','collectors'=>['credentialed_json','rss_atom']],
            ['organization_id'=>'org-b','collectors'=>['rss_atom']],
        ];
    }

    public function targetForOrganization(string $organizationId):array
    {
        return [
            'organization_id'=>$organizationId,
            'collectors'=>['credentialed_json','rss_atom'],
            'source_counts'=>['credentialed_json'=>1,'rss_atom'=>2],
        ];
    }
};

$health=new class($now) implements GrowthSignalPollingHealthRepositoryInterface {
    /** @var array<string,array<string,mixed>> */
    public array $states;
    /** @var list<array<string,mixed>> */
    public array $writes=[];

    public function __construct(DateTimeImmutable $now)
    {
        $this->states=[
            'org-a:credentialed_json'=>[
                'collector_name'=>'credentialed_json',
                'status'=>'cooling_down',
                'consecutive_failures'=>3,
                'last_run_status'=>'failed',
                'next_retry_at'=>$now->modify('+30 minutes')->format('Y-m-d H:i:s'),
            ],
            'org-b:rss_atom'=>[
                'collector_name'=>'rss_atom',
                'status'=>'cooling_down',
                'consecutive_failures'=>2,
                'last_run_status'=>'failed',
                'next_retry_at'=>null,
            ],
        ];
    }

    public function state(string $organizationId,string $collectorName):?array
    {
        return $this->states[$organizationId.':'.$collectorName]??null;
    }

    public function statesForOrganization(string $organizationId):array
    {
        return array_values(array_filter(
            $this->states,
            static fn(array $row,string $key):bool=>str_starts_with($key,$organizationId.':'),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    public function markHealthy(string $organizationId,string $collectorName,DateTimeImmutable $at):void
    {
        $this->writes[]=['kind'=>'healthy','organization_id'=>$organizationId,'collector'=>$collectorName,'at'=>$at];
    }

    public function markDegraded(string $organizationId,string $collectorName,DateTimeImmutable $at,?string $errorSummary):void
    {
        $this->writes[]=['kind'=>'degraded','organization_id'=>$organizationId,'collector'=>$collectorName,'at'=>$at,'error'=>$errorSummary];
    }

    public function markFailed(
        string $organizationId,string $collectorName,DateTimeImmutable $at,DateTimeImmutable $nextRetryAt,string $errorSummary
    ):void {
        $this->writes[]=[
            'kind'=>'failed','organization_id'=>$organizationId,'collector'=>$collectorName,
            'at'=>$at,'next_retry_at'=>$nextRetryAt,'error'=>$errorSummary,
        ];
    }
};

$collectors=new class implements GrowthSignalCollectorBoundary {
    /** @var list<array<string,mixed>> */
    public array $calls=[];

    public function runCollector(
        string $organizationId,int $actorId,string $correlationId,string $collectorName,string $idempotencyKey,
        ?string $cursor=null,int $limit=100,
    ):array {
        $this->calls[]=['organization_id'=>$organizationId,'collector'=>$collectorName,'key'=>$idempotencyKey];
        if($organizationId==='org-b'){
            return [
                'run_id'=>'RUN-B-RSS',
                'status'=>'failed',
                'error_summary'=>'provider unavailable',
            ];
        }
        return ['run_id'=>'RUN-A-RSS','status'=>'completed'];
    }

    public function collectors():array{return ['credentialed_json','rss_atom'];}
};

$states=new class implements ModuleStateRepositoryInterface {
    public function enabledOverride(string $organizationId,string $moduleId):?bool{return null;}
    public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void{}
};
$modules=new ActiveModuleResolver(new ModuleCatalog([
    new ModuleManifest('growth','Growth','0.32.0',enabledByDefault:true,schemaVersion:'0.29.0'),
]),$states);

$handler=new RunGrowthSignalPollingCommandHandler(
    $targets,$collectors,$health,$policy,$modules,42,15,500,75,
);
$result=$handler(new RunGrowthSignalPollingCommand('unit',$at));

expectGrowthV0320($result['completed_runs']===1,'Growth polling should complete healthy collector.');
expectGrowthV0320($result['failed_runs']===1,'Growth polling should count failed collector result.');
expectGrowthV0320($result['skipped_backoff_runs']===1,'Growth polling should skip collector inside cooldown.');
expectGrowthV0320(count($collectors->calls)===2,'Cooling-down collector must not call provider runtime.');

$healthy=array_values(array_filter($health->writes,static fn(array $row):bool=>$row['kind']==='healthy'));
$failed=array_values(array_filter($health->writes,static fn(array $row):bool=>$row['kind']==='failed'));
expectGrowthV0320(count($healthy)===1&&$healthy[0]['organization_id']==='org-a','Healthy polling result must reset collector health.');
expectGrowthV0320(count($failed)===1&&$failed[0]['organization_id']==='org-b','Failed polling result must update collector health.');
expectGrowthV0320(
    $failed[0]['next_retry_at']->getTimestamp()===$now->modify('+60 minutes')->getTimestamp(),
    'Third consecutive failure must schedule a 60 minute retry delay.'
);

$provider=new GrowthSignalPollingStatusProvider($targets,$health,$modules,true,15,42,100);
$status=$provider->status('org-a');
expectGrowthV0320($status['health_status']==='degraded','Cooling-down collector must degrade polling health.');
expectGrowthV0320(
    ($status['collector_health']['credentialed_json']['consecutive_failures']??null)===3,
    'Polling workspace must expose tenant collector failure count.'
);
expectGrowthV0320(!array_key_exists('actor_id',$status),'Polling health must not expose system actor identity.');

echo "Growth V0.32 Collector Health & Adaptive Backoff contracts passed.\n";
