<?php
declare(strict_types=1);

use App\Application\Capability\CanonicalCapabilityGateway;
use App\Application\Sales\Command\ConvertSalesLeadToOpportunityCommand;
use App\Application\Sales\Command\ConvertSalesLeadToOpportunityCommandHandler;
use App\Infrastructure\Capability\SalesClientCaseCreateAdapter;
use App\Infrastructure\Messenger\SymfonyCommandBus;
use App\Persistence\Federation\FederationCapabilityBindingResolver;
use Domains\Property\Infrastructure\ReadModel\MySql\MysqlPropertyReferencePort;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlInboundLeadRepository;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesAssignmentAuthority;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesWriteServiceFactory;
use Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesWorkspaceReadModel;
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
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

$root = dirname(__DIR__, 2);
require getenv('COS_TEST_AUTOLOAD') ?: $root . '/symfony/vendor/autoload.php';
$check = static function(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); };
$reject = static function(callable $fn): void { try { $fn(); } catch (DomainException) { return; } throw new RuntimeException('Unsafe conversion accepted.'); };
$db = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', getenv('DB_HOST') ?: '127.0.0.1', (int)(getenv('DB_PORT') ?: 3306), getenv('DB_DATABASE') ?: 'cos'),
    getenv('DB_USERNAME') ?: 'cos', getenv('DB_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC],
);
$org = (string)$db->query('SELECT p.organization_id FROM sales_pipelines p INNER JOIN sales_pipeline_stages s ON s.id=p.initial_stage_id AND s.organization_id=p.organization_id AND s.pipeline_id=p.id INNER JOIN cos_organizations o ON o.id=p.organization_id WHERE p.status="ACTIVE" AND s.status="ACTIVE" AND o.status="ACTIVE" ORDER BY p.organization_id LIMIT 1')->fetchColumn();
$check($org !== '', 'Migrated MySQL must contain an active organization and initial Sales pipeline.');
$token = bin2hex(random_bytes(8));
$db->beginTransaction();
try {
    $db->prepare('INSERT INTO tn_users (organization_id,email,password_hash,full_name,role,status) VALUES (?,?,?,?,?,?)')
        ->execute([$org,'sales-conversion-'.$token.'@example.test',password_hash($token,PASSWORD_DEFAULT),'Sales CI manager','manager','active']);
    $userId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO cos_organization_memberships (organization_id,user_id,role,status) VALUES (?,?,?,?)')
        ->execute([$org,$userId,'manager','ACTIVE']);
    $leadId = (new MysqlInboundLeadRepository($db))->create($org,[
        'person_id'=>null,'client_case_id'=>null,'property_id'=>null,'full_name'=>'Sales CI lead',
        'phone'=>null,'email'=>'sales-lead-'.$token.'@example.test','role'=>'buyer','deal_type'=>'consultation',
        'message'=>'private fixture','source_page'=>'/ci',
    ]);

    $factory = new MysqlSalesWriteServiceFactory($db,new MysqlPropertyReferencePort($db),new MysqlSalesAssignmentAuthority($db));
    $handler = new ConvertSalesLeadToOpportunityCommandHandler($factory);
    $bus = new SymfonyCommandBus(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
        ConvertSalesLeadToOpportunityCommand::class => [$handler],
    ]))]));
    $module = new ModuleCatalog([ModuleDefinition::fromArray(require $root.'/app/Domains/Sales/module.php')]);
    $catalog = new CanonicalCapabilityCatalog($module);
    $state = new class implements ModuleStateRepositoryInterface {
        public function enabledOverride(string $organizationId,string $moduleId): ?bool { return true; }
        public function setEnabled(string $organizationId,string $moduleId,bool $enabled): void {}
    };
    $active = new ActiveModuleResolver($module,$state);
    $resolver = new FederationCapabilityBindingResolver($catalog,new DomainModuleRegistry([]),$active);
    $read = new MysqlSalesWorkspaceReadModel($db);
    $gateway = new CanonicalCapabilityGateway($catalog,$active,$resolver,[new SalesClientCaseCreateAdapter($bus,$read)]);
    $actor = static fn(string $tenant,string $role='manager') => new TenantContext(
        UserId::fromString((string)$userId),OrganizationId::fromString($tenant),OrganizationRole::fromString($role),
        [Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)],
    );
    $cap = 'sales.client_case.create';
    $input = ['lead_id'=>$leadId];
    $manager = $actor($org);
    $reject(fn()=>$gateway->invoke($actor('foreign-'.$token),$cap,$input,'human_api','key','trace'));
    $reject(fn()=>$gateway->invoke($actor($org,'viewer'),$cap,$input,'human_api','key','trace'));
    $reject(fn()=>$gateway->invoke($manager,$cap,$input,'ai','key','trace'));
    $check(empty($read->lead($org,$leadId)['client_case_id']),'Denied conversion mutated native lead.');
    $receipt = $gateway->invoke($manager,$cap,$input,'human_api','first-'.$token,'trace-'.$token);
    $caseId = $receipt['case_id'];
    $check($receipt===['lead_id'=>$leadId,'case_id'=>$caseId,'replayed'=>false] && $caseId>0,'Invalid conversion receipt.');
    $repeat = $gateway->invoke($manager,$cap,$input,'human_ui','second-'.$token,'trace-replay-'.$token);
    $check($repeat===[...$receipt,'replayed'=>true],'Replay did not return original case.');
    $lead = $read->lead($org,$leadId);
    $case = $read->deal($org,$caseId);
    $check((int)$lead['client_case_id']===$caseId && $lead['status']==='qualified' && (int)$case['assigned_user_id']===$userId,'Native lead/case state mismatch.');
    $q = $db->prepare('SELECT COUNT(*) FROM tn_client_cases WHERE organization_id=? AND inbound_request_id=?');
    $q->execute([$org,$leadId]);
    $check((int)$q->fetchColumn()===1,'Duplicate native case.');
    $q = $db->prepare('SELECT COUNT(*) FROM cos_events e INNER JOIN cos_event_outbox o ON o.event_id=e.id AND o.organization_id=e.organization_id WHERE e.organization_id=? AND e.aggregate_id=? AND e.type=?');
    $q->execute([$org,(string)$caseId,'sales.client_case.created']);
    $check((int)$q->fetchColumn()===1,'Expected exactly one case-created Event/Outbox.');
    echo "Sales canonical conversion Gateway, Messenger, native state and outbox PASS.\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
}
