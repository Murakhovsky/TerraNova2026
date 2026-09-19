<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use InvalidArgumentException;
use Kernel\Module\EffectiveModuleContext;
use Kernel\Module\ModuleControlService;
use Kernel\Module\ModuleReadinessDiagnostic;
use Kernel\Observability\CorrelationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use RuntimeException;
use App\Security\LegacySessionCsrfValidator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

final readonly class PlatformModuleController
{
    public function __construct(
        private EffectiveModuleContext $context,
        private ModuleReadinessDiagnostic $readinessDiagnostic,
        private ModuleControlService $control,
        private TenantContextProviderInterface $tenants,
        private LegacySessionCsrfValidator $csrf,
    ) {}

    public function index(): JsonResponse
    {
        $tenant=$this->context(false);
        if($tenant instanceof JsonResponse) return $tenant;
        return $this->ok($this->context->describe($tenant->organizationId()->value()));
    }

    public function readiness(): JsonResponse
    {
        $tenant=$this->context(true);
        if($tenant instanceof JsonResponse) return $tenant;
        return $this->ok($this->readinessDiagnostic->diagnose($tenant->organizationId()->value()));
    }

    public function install(Request $request,string $id):JsonResponse{return $this->mutate($request,$id,'install');}
    public function upgrade(Request $request,string $id):JsonResponse{return $this->mutate($request,$id,'upgrade');}
    public function enable(Request $request,string $id):JsonResponse{return $this->mutate($request,$id,'enable');}
    public function disable(Request $request,string $id):JsonResponse{return $this->mutate($request,$id,'disable');}
    public function uninstall(Request $request,string $id):JsonResponse{return $this->mutate($request,$id,'uninstall');}

    private function mutate(Request $request,string $id,string $operation):JsonResponse
    {
        $tenant=$this->context(true);
        if($tenant instanceof JsonResponse) return $tenant;
        if(!$this->csrf->isValid($request)) return $this->error(400,'invalid_csrf_token','Invalid CSRF token.');
        if(!preg_match('/^[a-z][a-z0-9_]*$/',$id)) return $this->error(400,'invalid_module_id','Invalid module id.');

        $actor=$tenant->userId()->value();
        if(!ctype_digit($actor)||(int)$actor<=0) return $this->error(403,'invalid_actor','Authenticated actor is invalid.');
        $input=$this->input($request);
        $reason=($v=trim((string)($input['reason']??'')))!==''?$v:null;
        $correlation=$request->attributes->get('_cos_correlation_id');
        $correlationId=$correlation instanceof CorrelationId?$correlation->value():CorrelationId::generate()->value();

        try{
            $arguments=[$tenant->organizationId()->value(),$id,$actor,$correlationId,$reason];
            $module=match($operation){
                'install'=>$this->control->install(...$arguments),
                'upgrade'=>$this->control->upgrade(...$arguments),
                'enable'=>$this->control->enable(...$arguments),
                'disable'=>$this->control->disable(...$arguments),
                'uninstall'=>$this->control->uninstall(...$arguments),
                default=>throw new InvalidArgumentException('Unsupported module operation.'),
            };
            return $this->ok(['operation'=>$operation,'correlation_id'=>$correlationId,'module'=>$module]);
        }catch(RuntimeException|InvalidArgumentException $error){
            return $this->error(409,'module_operation_rejected',$error->getMessage());
        }catch(Throwable){
            return $this->error(500,'module_operation_failed','Platform module operation failed.');
        }
    }

    private function context(bool $admin):TenantContext|JsonResponse
    {
        $tenant=$this->tenants->current();
        if($tenant===null) return $this->error(403,'tenant_context_required','Tenant context required.');
        $permission=$admin?TenantPermissions::ADMIN:TenantPermissions::ACCESS;
        if(!$tenant->allows($permission)) return $this->error(403,'permission_denied','Permission denied.');
        return $tenant;
    }

    private function input(Request $request):array
    {
        $decoded=json_decode((string)$request->getContent(),true);
        return is_array($decoded)&&!array_is_list($decoded)?$decoded:$request->request->all();
    }

    private function ok(mixed $data):JsonResponse{return new JsonResponse(['ok'=>true,'data'=>$data]);}
    private function error(int $status,string $code,string $message):JsonResponse{return new JsonResponse(['ok'=>false,'error'=>$code,'message'=>$message],$status);}
}
