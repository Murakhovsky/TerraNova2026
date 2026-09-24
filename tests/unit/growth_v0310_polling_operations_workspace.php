<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use App\Application\Growth\ReadModel\GrowthSignalPollingStatusProvider;
use Domains\Growth\Application\Contract\GrowthSignalPollingTargetRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthSignalPollingHealthRepositoryInterface;
use DateTimeImmutable;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleManifest;

function expectGrowthV0310(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$targets=new class implements GrowthSignalPollingTargetRepositoryInterface {
    public array $requestedOrganizations=[];

    public function targets(int $organizationLimit=500):array
    {
        throw new RuntimeException('Workspace status must not use cross-tenant target enumeration.');
    }

    public function targetForOrganization(string $organizationId):array
    {
        $this->requestedOrganizations[]=$organizationId;
        return [
            'organization_id'=>$organizationId,
            'collectors'=>['credentialed_json','rss_atom'],
            'source_counts'=>['credentialed_json'=>2,'rss_atom'=>3],
        ];
    }
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
$modules=new ActiveModuleResolver(new ModuleCatalog([
    new ModuleManifest('growth','Growth','0.31.0',enabledByDefault:true,schemaVersion:'0.28.0'),
]),$states);

$health=new class implements GrowthSignalPollingHealthRepositoryInterface {
    public function state(string $organizationId,string $collectorName):?array{return null;}
    public function statesForOrganization(string $organizationId):array{return [];}
    public function markHealthy(string $organizationId,string $collectorName,DateTimeImmutable $at):void{}
    public function markDegraded(string $organizationId,string $collectorName,DateTimeImmutable $at,?string $errorSummary):void{}
    public function markFailed(string $organizationId,string $collectorName,DateTimeImmutable $at,DateTimeImmutable $nextRetryAt,string $errorSummary):void{}
};

$provider=new GrowthSignalPollingStatusProvider($targets,$health,$modules,true,15,42,100);
$status=$provider->status('org-1');

expectGrowthV0310($targets->requestedOrganizations===['org-1'],'Polling status must query only the current organization.');
expectGrowthV0310($status['ready']===true&&$status['reason']==='ready','Ready polling status is wrong.');
expectGrowthV0310($status['enabled_source_count']===5,'Polling status source count is wrong.');
expectGrowthV0310($status['collectors']===['credentialed_json','rss_atom'],'Polling status collectors are wrong.');
expectGrowthV0310(($status['source_counts']['credentialed_json']??null)===2,'Polling status JSON source count is wrong.');
expectGrowthV0310(($status['source_counts']['rss_atom']??null)===3,'Polling status RSS source count is wrong.');
expectGrowthV0310(!array_key_exists('actor_id',$status),'Polling status must not expose raw actor id.');
expectGrowthV0310(!array_key_exists('organization_id',$status),'Polling status must not echo organization identity into the workspace projection.');
expectGrowthV0310(!array_key_exists('organization_limit',$status),'Polling status must not expose cross-tenant scheduler limits.');

$disabledProvider=new GrowthSignalPollingStatusProvider($targets,$health,$modules,false,15,42,100);
$disabled=$disabledProvider->status('org-1');
expectGrowthV0310($disabled['ready']===false&&$disabled['reason']==='scheduler_disabled','Disabled scheduler status is wrong.');

$missingActor=new GrowthSignalPollingStatusProvider($targets,$health,$modules,true,15,0,100);
$actorStatus=$missingActor->status('org-1');
expectGrowthV0310($actorStatus['ready']===false&&$actorStatus['reason']==='system_actor_missing','Missing actor status is wrong.');

$states->setEnabled('org-1','growth',false);
$moduleDisabled=$provider->status('org-1');
expectGrowthV0310($moduleDisabled['ready']===false&&$moduleDisabled['reason']==='growth_module_disabled','Disabled Growth module status is wrong.');

echo "Growth V0.31 Polling Operations Workspace contracts passed.\n";
