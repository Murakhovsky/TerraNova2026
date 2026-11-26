<?php
declare(strict_types=1);

use App\Application\Capability\CanonicalCapabilityGateway;
use App\Infrastructure\Capability\DiagnosticMethodologyPublishAdapter;
use App\Persistence\Federation\FederationCapabilityBindingResolver;
use Domains\Diagnostic\Application\Service\MethodologyStudioService;
use Domains\Diagnostic\Application\Service\DiagnosticMethodologyAccess;
use Tests\Support\InMemoryMethodologyStudioRepository;
use Domains\Diagnostic\Application\Contract\DiagnosticPackRepositoryInterface;
use Domains\Diagnostic\Methodology\Serialization\MethodologyPackSerializer;
use Domains\Diagnostic\Model\DiagnosticPack;
use Domains\Diagnostic\Model\DiagnosticPackStatus;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDefinition;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
spl_autoload_register(static function (string $name) use ($root): void {
    if (!str_starts_with($name, 'App\\')) return;
    $file = $root . '/symfony/src/' . str_replace('\\', '/', substr($name, 4)) . '.php';
    if (is_file($file)) require $file;
});
$module = require $root . '/app/Domains/Diagnostic/module.php';
$moduleCatalog = new ModuleCatalog([ModuleDefinition::fromArray($module)]);
$catalog = new CanonicalCapabilityCatalog($moduleCatalog);
$state = new class implements ModuleStateRepositoryInterface {
    public bool $enabled = true;
    public function enabledOverride(string $org, string $module): ?bool { return $this->enabled; }
    public function setEnabled(string $org, string $module, bool $enabled): void { $this->enabled = $enabled; }
};
$active = new ActiveModuleResolver($moduleCatalog, $state);
$resolver = new FederationCapabilityBindingResolver($catalog, new DomainModuleRegistry([]), $active);
require_once $root.'/tests/Support/InMemoryMethodologyStudioRepository.php';
$repo=new InMemoryMethodologyStudioRepository();
$studio=new MethodologyStudioService($repo);
$org='tenant-a';$pack='sales-studio';$version='0.2.0';
$studio->create($org,['slug'=>$pack,'name'=>'Sales Diagnostic','domain'=>'Sales','methodology_version'=>$version],'31');
$source=json_decode(file_get_contents($root.'/resources/diagnostic/sales/0.1.0/sales-diagnostic-pack.json'),true,512,JSON_THROW_ON_ERROR);
$map=['sections'=>'AREA','criteria'=>'CRITERION','facts'=>'FACT','metrics'=>'METRIC','questions'=>'QUESTION','evidence_requirements'=>'EVIDENCE_REQUIREMENT','rules'=>'RULE','dependencies'=>'DEPENDENCY','recommendations'=>'RECOMMENDATION','benchmarks'=>'BENCHMARK','scoring'=>'SCORING'];
foreach($map as $key=>$type)foreach($source[$key]??[] as $i=>$entity)$studio->saveEntity($org,$pack,$version,$type,$entity+['order'=>$i],'31');
$repo->versionsData[$org][$pack][$version]['organization_id']=$org;
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE diagnostic_user_permissions (organization_id TEXT,user_id INTEGER,permission TEXT,allowed INTEGER)');
$db->exec('CREATE TABLE cos_organization_memberships (organization_id TEXT,user_id INTEGER,role TEXT,status TEXT)');
$db->exec('CREATE TABLE diagnostic_role_permissions (role TEXT,permission TEXT)');
$db->exec("INSERT INTO diagnostic_user_permissions VALUES ('tenant-a',31,'diagnostic.methodology.publish',1),('tenant-a',32,'diagnostic.methodology.publish',0),('tenant-b',31,'diagnostic.methodology.publish',1)");
$native=new class($repo) implements DiagnosticPackRepositoryInterface {
    public bool $broken=false;
    public function __construct(private InMemoryMethodologyStudioRepository $repo){}
    public function save(string $organizationId,DiagnosticPack $pack,?int $expectedLockVersion=null):void{throw new LogicException('Adapter cannot write native packs directly.');}
    public function get(string $organizationId,string $packId,int $version):?DiagnosticPack{
        if($this->broken)return null;
        $row=$this->repo->pack($organizationId,$packId);
        $compiled=$row['compiled'][$version]??null;
        if($compiled===null)return null;
        return DiagnosticPack::rehydrate($row['domain'],(new MethodologyPackSerializer())->decode($compiled['json']),DiagnosticPackStatus::Published,new DateTimeImmutable(),0,$compiled['hash']);
    }
};
$gateway=new CanonicalCapabilityGateway($catalog,$active,$resolver,[new DiagnosticMethodologyPublishAdapter($studio,$repo,$native,new DiagnosticMethodologyAccess($db))]);
$actor=static fn(string $org,string $role='manager',string $id='31')=>new TenantContext(UserId::fromString($id),OrganizationId::fromString($org),OrganizationRole::fromString($role),[Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)]);
$manager=$actor($org);
$reject=static function(callable $call):void{try{$call();}catch(DomainException){return;}throw new RuntimeException('Unsafe publication accepted.');};
$check=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$hash=(new MethodologyPackSerializer())->hash($studio->compile($org,$pack,$version)->pack);
$input=['pack_id'=>$pack,'methodology_version'=>$version,'expected_content_hash'=>$hash];
$cap='diagnostic.methodology.publish';
$call=static fn(array $input,string $origin='human_api',string $key='key')=>$gateway->invoke($manager,$cap,$input,$origin,$key,'trace');
$snapshot=static fn()=>serialize([$repo->versionsData,$repo->packsData,$repo->entityData,$repo->scenarioData,$repo->audits,$repo->results]);
$before=$snapshot();
$check($gateway->describe($manager,$cap)['status']==='direct_human','Publication must be human-only.');
$reject(fn()=> $gateway->invoke($actor($org,'manager','32'),$cap,$input,'human_api','key','trace'));
$reject(fn()=> $gateway->invoke($actor('tenant-b'),$cap,$input,'human_api','key','trace'));
$reject(fn()=> $gateway->invoke($actor($org,'viewer'),$cap,$input,'human_api','key','trace'));
$reject(fn()=> $call($input,'ai'));
$reject(fn()=> $call($input,'cron'));
$reject(fn()=> $call($input,'human_api',''));
$reject(fn()=> $call([...$input,'organization_id'=>'tenant-b']));
$reject(fn()=> $call([...$input,'expected_content_hash'=>str_repeat('f',64)]));
$reject(fn()=> $call($input)); // No passing regression scenario yet.
$check($snapshot()===$before,'Rejected publication changed state.');
$metrics=[];foreach($source['metrics'] as $m)$metrics[$m['id']]=$m['direction']==='lower_is_better'?10:90;
$facts=array_fill_keys(array_column($source['facts'],'id'),true);
$scenario=['id'=>'healthy','name'=>'Healthy','input'=>['facts'=>$facts,'metrics'=>$metrics],'expected'=>['findings'=>[]]];
$studio->saveScenario($org,$pack,$version,$scenario,'31');
$before=$snapshot();
$native->broken=true;
$reject(fn()=> $call($input));
$check($snapshot()===$before,'Native readback failure did not roll back publication.');
$native->broken=false;
$expected=['pack_id'=>$pack,'methodology_version'=>$version,'runtime_version'=>2000,'status'=>'PUBLISHED','content_hash'=>$hash,'active'=>true,'replayed'=>false];
$check($call($input)===$expected,'Native publication receipt mismatch.');
$published=$snapshot();
$check($call($input,'human_ui','other-key')===[...$expected,'replayed'=>true] && $snapshot()===$published,'Replay mutated publication or audit.');
$reject(fn()=> $call([...$input,'expected_content_hash'=>str_repeat('0',64)]));
$studio->clone($org,$pack,$version,'0.3.0','31');
$repo->versionsData[$org][$pack]['0.3.0']['organization_id']=$org;
$studio->saveScenario($org,$pack,'0.3.0',$scenario,'31');
$hash2=(new MethodologyPackSerializer())->hash($studio->compile($org,$pack,'0.3.0')->pack);
$check($call([...$input,'methodology_version'=>'0.3.0','expected_content_hash'=>$hash2])['active'],'New version did not activate.');
$published2=$snapshot();
$check($call($input)===[...$expected,'active'=>false,'replayed'=>true] && $snapshot()===$published2,'Replay reactivated old version.');
$state->enabled=false;
$reject(fn()=> $call($input));
echo "Diagnostic methodology publish authorization, fingerprint, rollback, immutable readback and no-reactivation replay PASS.\n";
