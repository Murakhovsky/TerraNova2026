<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use App\Application\Growth\Command\RunGrowthSignalPollingCommand;
use App\Application\Growth\Command\RunGrowthSignalPollingCommandHandler;
use Domains\Growth\Application\Contract\GrowthSignalCollectorBoundary;
use Domains\Growth\Application\Contract\GrowthSignalPollingTargetRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthSignalPollingHealthRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthSignalPollingIncidentBoundary;
use Domains\Growth\Domain\SignalPollingBackoffPolicy;
use DateTimeImmutable;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleManifest;

function expectGrowthV0300(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$targets=new class implements GrowthSignalPollingTargetRepositoryInterface {
    public function targets(int $organizationLimit=500):array
    {
        return [
            ['organization_id'=>'org-a','collectors'=>['credentialed_json','rss_atom']],
            ['organization_id'=>'org-b','collectors'=>['rss_atom']],
            ['organization_id'=>'org-c','collectors'=>['rss_atom']],
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
        $this->calls[]=[
            'organization_id'=>$organizationId,'actor_id'=>$actorId,'correlation_id'=>$correlationId,
            'collector'=>$collectorName,'idempotency_key'=>$idempotencyKey,'cursor'=>$cursor,'limit'=>$limit,
        ];
        if($organizationId==='org-a'&&$collectorName==='credentialed_json'){
            throw new RuntimeException('fixture provider failure');
        }
        return [
            'run_id'=>'RUN-'.$organizationId.'-'.$collectorName,
            'status'=>'completed',
        ];
    }

    public function collectors():array{return ['credentialed_json','rss_atom'];}
};

$states=new class implements ModuleStateRepositoryInterface {
    /** @var array<string,bool> */
    private array $values=[];
    public function enabledOverride(string $organizationId,string $moduleId):?bool
    {
        return $this->values[$organizationId.':'.$moduleId]??null;
    }
    public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void
    {
        $this->values[$organizationId.':'.$moduleId]=$enabled;
    }
};
$states->setEnabled('org-b','growth',false);
$modules=new ActiveModuleResolver(new ModuleCatalog([
    new ModuleManifest('growth','Growth','0.30.0',enabledByDefault:true,schemaVersion:'0.28.0'),
]),$states);

$health=new class implements GrowthSignalPollingHealthRepositoryInterface {
    public function state(string $organizationId,string $collectorName):?array{return null;}
    public function statesForOrganization(string $organizationId):array{return [];}
    public function markHealthy(string $organizationId,string $collectorName,DateTimeImmutable $at):void{}
    public function markDegraded(string $organizationId,string $collectorName,DateTimeImmutable $at,?string $errorSummary):void{}
    public function markFailed(string $organizationId,string $collectorName,DateTimeImmutable $at,DateTimeImmutable $nextRetryAt,string $errorSummary):void{}
};

$incidents=new class implements GrowthSignalPollingIncidentBoundary {
    public function recordFailure(string $organizationId,int $actorId,string $correlationId,string $collectorName,int $consecutiveFailures,string $errorSummary,DateTimeImmutable $failedAt,DateTimeImmutable $nextRetryAt):?array{return null;}
    public function recordRecovery(string $organizationId,int $actorId,string $correlationId,string $collectorName,DateTimeImmutable $recoveredAt):?array{return null;}
    public function activeIncidents(string $organizationId):array{return [];}
};

$handler=new RunGrowthSignalPollingCommandHandler(
    $targets,$collectors,$health,$incidents,new SignalPollingBackoffPolicy(15,360),$modules,42,15,500,75,
);
$at=1760000000;
$result=$handler(new RunGrowthSignalPollingCommand('unit',$at));

expectGrowthV0300($result['target_organizations']===3,'Growth polling target count is wrong.');
expectGrowthV0300($result['completed_runs']===2,'Growth polling must complete remaining collectors after one failure.');
expectGrowthV0300($result['failed_runs']===1,'Growth polling failed run count is wrong.');
expectGrowthV0300($result['skipped_disabled_organizations']===1,'Growth polling must skip disabled organization.');
expectGrowthV0300(count($collectors->calls)===3,'Growth polling must not call collectors for disabled organization.');
expectGrowthV0300($collectors->calls[0]['actor_id']===42,'Growth polling lost system actor.');
expectGrowthV0300($collectors->calls[0]['limit']===75,'Growth polling lost configured signal limit.');
expectGrowthV0300($collectors->calls[0]['cursor']===null,'Scheduled Growth polling must start collector without cursor.');

$bucketStart=intdiv($at,15*60)*(15*60);
$bucket=gmdate('YmdHi',$bucketStart);
foreach($collectors->calls as $call){
    expectGrowthV0300(
        str_starts_with((string)$call['idempotency_key'],'scheduled-poll:'.$bucket.':'),
        'Growth polling idempotency key must be stable inside the cadence bucket.'
    );
    expectGrowthV0300(
        is_string($call['correlation_id'])&&$call['correlation_id']!=='',
        'Growth polling must create correlation id for each collector run.'
    );
}

$rssKeys=array_values(array_map(
    static fn(array $call):string=>(string)$call['idempotency_key'],
    array_filter($collectors->calls,static fn(array $call):bool=>$call['collector']==='rss_atom')
));
expectGrowthV0300(count(array_unique($rssKeys))===1,'Same collector cadence bucket should reuse its idempotency key across tenants.');

echo "Growth V0.30 Scheduled Signal Polling contracts passed.\n";
