<?php
declare(strict_types=1);

namespace App\Security;

use DomainException;
use Domains\Sales\Application\Contract\SalesAccessControlInterface;
use Domains\Sales\Application\Service\SalesEntityScopeService;
use Domains\Sales\Model\SalesCapability;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/** Fail-closed HTTP boundary for existing Sales controllers. Public signed CRM webhook is separate. */
final readonly class SalesWorkspaceAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(private TenantContextProviderInterface $tenants,private ActiveModuleResolver $modules,
        private SalesEntityScopeService $scope,private SalesAccessControlInterface $access) {}

    public static function getSubscribedEvents():array {return [KernelEvents::CONTROLLER=>'authorize'];}

    public function authorize(ControllerEvent $event):void
    {
        $controller=$event->getController();
        if (is_array($controller)&&is_object($controller[0])) {
            $class=$controller[0]::class;$method=(string)$controller[1];
        } elseif (is_object($controller)) {$class=$controller::class;$method='__invoke';}
        else return;
        $web=str_starts_with($class,'App\\Web\\Sales\\');
        $api=str_starts_with($class,'App\\Http\\Api\\V1\\Controller\\Sales');
        if (!$web&&!$api) return;
        $name=substr($class,strrpos($class,'\\')+1);
        if ($name==='SalesIntegrationController'&&$method==='webhook') return;

        $tenant=$this->tenants->current();
        if ($tenant===null&&$web) {
            $event->setController(static fn()=>new RedirectResponse('/auth/login'));return;
        }
        if ($tenant===null||!$tenant->isManager()||!$tenant->allows(TenantPermissions::ACCESS)) {
            throw new AccessDeniedHttpException('Sales access denied.');
        }
        $org=$tenant->organizationId()->value();$raw=$tenant->userId()->value();
        if (!ctype_digit($raw)||(int)$raw<1||(string)(int)$raw!==$raw||!$this->modules->isEnabled($org,'sales')) {
            throw new AccessDeniedHttpException('Sales access denied.');
        }
        $actor=(int)$raw;$request=$event->getRequest();
        $mutation=!in_array($request->getMethod(),['GET','HEAD','OPTIONS'],true);
        $require=fn(SalesCapability $permission):bool=>$this->access->hasCapability($org,$actor,$permission->value);
        try {
            $this->scope->resolve($org,$actor);
            // Existing admin API enforces operation-specific capabilities. Do not replace them with DirectorView.
            if ($name==='SalesAdminFrontendController') return;
            if (in_array($name,['SalesAdminDashboardController','SalesAdminControlController'],true)) {
                if (!$require(SalesCapability::AdminView)) throw new DomainException('Denied.');
                // Legacy admin page projections may contain all-tenant data; restrict to live administrators.
                if (!$tenant->isAdmin()||!$require(SalesCapability::DirectorView)) throw new DomainException('Denied.');
                return;
            }
            if ($name==='SalesIntegrationController') {
                if (!$require(SalesCapability::AdminIntegrationsManage)) throw new DomainException('Denied.');
                return;
            }
            if ($mutation&&!$require(SalesCapability::WorkspaceUse)) throw new DomainException('Denied.');
            if (($name==='SalesReadController'&&in_array($method,['leads','opportunities'],true))
                ||in_array($name,['SalesLeadsController','SalesDealsController','SalesPipelineController'],true)) {
                $filters=$request->query->all();$nested=is_array($filters['filter']??null)?$filters['filter']:[];
                $owner=$name==='SalesDealsController'?($nested['owner_id']??$filters['owner_id']??null):($filters['owner_id']??null);
                if ($owner==='') $owner=null;
                $mode=$filters['scope']??($owner===null?'self':'owner');
                if (!is_string($mode)||($owner!==null&&(!is_string($owner)||!ctype_digit($owner)||(int)$owner<1||(string)(int)$owner!==$owner))) throw new DomainException('Denied.');
                $resolved=$this->scope->resolve($org,$actor,$mode,$owner===null?null:(int)$owner);
                unset($filters['scope'],$filters['owner_id']);
                if ($resolved!==null) $filters['owner_id']=(string)$resolved;
                if ($name==='SalesDealsController') {
                    unset($nested['owner_id']);
                    if ($resolved!==null) $nested['owner_id']=(string)$resolved;
                    $filters['filter']=$nested;
                }
                $request->query->replace($filters);return;
            }
            if (($name==='SalesReadController'&&in_array($method,['lead','opportunity'],true))
                ||$name==='SalesDealController'||$name==='SalesWorkspaceController') {
                $this->entity($org,$actor,$method==='lead'?'lead':'deal',$request->attributes->get('id'));return;
            }
            if (($name==='SalesReadController'&&in_array($method,['dashboard','pipelines'],true))||$name==='SalesDashboardController') return;
            if (in_array($name,['ClientCaseInboxController','ClientCaseCollectionController','SalesTodayController','SalesClientCaseStatsController'],true)&&!$mutation) return;
            if ($name==='ClientCaseWorkspaceController'&&!$mutation) {$this->entity($org,$actor,'deal',$request->attributes->get('id'));return;}
            if ($name==='SalesWriteController'&&$method==='createLead') {
                $input=str_contains(strtolower((string)$request->headers->get('Content-Type','')),'application/json')
                    ?json_decode((string)$request->getContent(),true):$request->request->all();
                if (!is_array($input)||array_is_list($input)) $input=[];
                foreach (['owner_id','assigned_user_id'] as $field) {
                    if (!array_key_exists($field,$input)) continue;
                    $target=$input[$field];
                    if ((!is_int($target)&&!is_string($target))||!ctype_digit((string)$target)||(int)$target<1) throw new DomainException('Denied.');
                    $this->scope->resolve($org,$actor,'owner',(int)$target);
                    if ((int)$target!==$actor&&!$require(SalesCapability::DealAssign)) throw new DomainException('Denied.');
                }
                return;
            }
            if ($name==='SalesFrontendController'&&in_array($method,['approve','reject'],true)) return;
            if ($name==='SalesFrontendController'&&in_array($method,['execute','dismiss'],true)) {
                // Approval scope and governed execution must be proven by S6, not by a generic manager role.
                throw new DomainException('Legacy action execution requires governed workflow.');
            }
            // These commands now enter the native transactional write facade.
            if ($mutation&&($name==='SalesWriteController'
                ||$name==='ClientCaseMutationController'
                ||$name==='SalesCommunicationController'
                ||($name==='SalesFrontendController'&&in_array($method,['quickUpdate','owner','meeting','completeActivity','rescheduleActivity','leadFollowup'],true)))) return;
            if ($mutation) {
                // Existing legacy write handlers do not uniformly lock/recheck owner authorization.
                // Until migrated, only an explicitly authorized live administrator may use these routes.
                if (!$tenant->isAdmin()||!$require(SalesCapability::DealAssign)||!$require(SalesCapability::DirectorView)) throw new DomainException('Denied.');
                return;
            }
            if (!$mutation&&$name==='SalesFrontendController'&&in_array($method,['search','intelligence'],true)) return;
            // Complex legacy projections include tenant-level owner lists, approvals, or linked cases.
            // Fail closed instead of filtering after an unsafe query.
            $this->scope->resolve($org,$actor,'tenant');
        } catch (NotFoundHttpException $e) {throw $e;}
        catch (DomainException) {throw new AccessDeniedHttpException('Sales access denied.');}
    }

    private function entity(string $org,int $actor,string $kind,mixed $id):void
    {
        if (!is_string($id)||!ctype_digit($id)||(int)$id<1||(string)(int)$id!==$id) throw new NotFoundHttpException('Sales entity not found.');
        try {$this->scope->requireEntity($org,$actor,$kind,(int)$id);}
        catch (DomainException) {throw new NotFoundHttpException('Sales entity not found.');}
    }
}
