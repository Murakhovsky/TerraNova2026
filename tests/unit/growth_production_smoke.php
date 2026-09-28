<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

spl_autoload_register(static function(string $class):void{
    if(!str_starts_with($class,'App\\'))return;
    $path=dirname(__DIR__,2).'/symfony/src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path))require $path;
});

use App\Application\Growth\Operations\GrowthProductionSmokeService;
use Domains\Growth\Application\Contract\GrowthWorkspaceReadModelInterface;
use Kernel\Database\MigrationRunnerInterface;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleContributions;
use Kernel\Module\ModuleDefinition;
use Kernel\Module\ModuleManifest;
use Kernel\Module\ModuleReadinessDiagnostic;

$states=new class implements ModuleStateRepositoryInterface{
    public bool $enabled=true;
    public function enabledOverride(string $organizationId,string $moduleId):?bool{return $this->enabled;}
    public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void{$this->enabled=$enabled;}
};
$catalog=new ModuleCatalog([
    new ModuleDefinition(
        new ModuleManifest('growth','Growth','0.50.0',enabledByDefault:false,schemaVersion:'0.50.0'),
        new ModuleContributions(),
    ),
]);
$resolver=new ActiveModuleResolver($catalog,$states);
$migrations=new class implements MigrationRunnerInterface{
    public function migrate():array{return ['applied'=>[],'skipped'=>[]];}
    public function status():array{return [];}
};
$readiness=new ModuleReadinessDiagnostic($catalog,$resolver,$migrations);

$workspace=new class implements GrowthWorkspaceReadModelInterface{
    public array $evidence=[
        'candidate_id'=>'GCND-LIVE-1','exists'=>true,'status'=>'handed_off','subject_type'=>'account','subject_id'=>'GACC-1',
        'target_domain'=>'sales','opportunity_type'=>'new_business','growth_mode'=>'outbound','account_exists'=>true,
        'signal_count'=>2,'market_membership_count'=>1,'committee_assessment_count'=>1,'recommendation_count'=>1,
        'accepted_recommendation_count'=>1,'execution_count'=>1,'response_count'=>1,'route_count'=>1,
        'sales_route_count'=>1,'accepted_sales_handoff_count'=>0,'outcome_count'=>3,'reply_outcome_count'=>1,
        'sales_outcome_count'=>1,'terminal_outcome_count'=>1,
    ];
    public function overview(string $organizationId):array{return ['candidate_total'=>1];}
    public function candidates(string $organizationId,array $filters=[],int $limit=100):array{return [];}
    public function accounts(string $organizationId,array $filters=[],int $limit=100):array{return [];}
    public function signals(string $organizationId,array $filters=[],int $limit=100):array{return [];}
    public function collectorRuns(string $organizationId,array $filters=[],int $limit=100):array{return [];}
    public function learningOverview(string $organizationId):array{return [];}
    public function outcomes(string $organizationId,array $filters=[],int $limit=100):array{return [];}
    public function productionEvidence(string $organizationId,string $candidateId):array{return $this->evidence;}
};

$smoke=new GrowthProductionSmokeService($readiness,$workspace);
$basic=$smoke->run('org-1');
if(!$basic['ok'])throw new RuntimeException('Basic Growth production smoke should pass for READY active module.');

$live=$smoke->run('org-1','GCND-LIVE-1',true);
if(!$live['ok'])throw new RuntimeException('Live Growth production smoke should pass for complete evidence.');

$workspace->evidence['sales_outcome_count']=0;
$failed=$smoke->run('org-1','GCND-LIVE-1',true);
if($failed['ok'])throw new RuntimeException('Live Growth production smoke must fail without Sales outcome feedback.');

$states->enabled=false;
$disabled=$smoke->run('org-1');
if($disabled['ok'])throw new RuntimeException('Basic Growth production smoke must fail while module is disabled.');

echo "Growth production smoke invariants passed.\n";
