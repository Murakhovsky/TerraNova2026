<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Application\Diagnostic\Methodology\DiagnosticMethodologyApplicationService;
use App\Application\Diagnostic\Methodology\DiagnosticMethodologyPermissionDenied;
use App\Security\SessionCsrfValidator;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

final readonly class DiagnosticMethodologyController
{
    public function __construct(
        private DiagnosticMethodologyApplicationService $methodology,
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
        private ActiveModuleResolver $modules,
    ) {}

    public function packs(Request $request): JsonResponse
    {
        return $request->isMethod('POST')
            ? $this->call($request, fn(string $org,int $actor) => $this->methodology->createPack($org,$actor,$this->input($request)), true)
            : $this->call($request, fn(string $org,int $actor) => $this->methodology->packs($org,$actor));
    }

    public function pack(Request $request,string $id): JsonResponse
    {
        return $this->call($request,fn(string $org,int $actor)=>$this->methodology->pack($org,$actor,$id));
    }

    public function versions(Request $request,string $id): JsonResponse
    {
        return $this->call($request,fn(string $org,int $actor)=>$this->methodology->versions($org,$actor,$id));
    }

    public function entities(Request $request,string $id,string $version): JsonResponse
    {
        return $this->call($request,fn(string $org,int $actor)=>$this->methodology->entities($org,$actor,$id,$version));
    }

    public function entity(Request $request,string $id,string $version,string $type): JsonResponse
    {
        return $this->call($request,fn(string $org,int $actor)=>$this->methodology->saveEntity($org,$actor,$id,$version,$type,$this->input($request)),true);
    }

    public function deleteEntity(Request $request,string $id,string $version,string $type,string $entity): JsonResponse
    {
        return $this->call($request,fn(string $org,int $actor)=>$this->methodology->deleteEntity($org,$actor,$id,$version,$type,$entity),true);
    }

    public function scenarios(Request $request,string $id,string $version): JsonResponse
    {
        return $request->isMethod('POST')
            ? $this->call($request,fn(string $org,int $actor)=>$this->methodology->saveScenario($org,$actor,$id,$version,$this->input($request)),true)
            : $this->call($request,fn(string $org,int $actor)=>$this->methodology->scenarios($org,$actor,$id,$version));
    }

    public function validate(Request $request,string $id,string $version): JsonResponse
    {
        return $this->call($request,fn(string $org,int $actor)=>$this->methodology->validate($org,$actor,$id,$version),true);
    }

    public function simulate(Request $request,string $id,string $version): JsonResponse
    {
        return $this->call($request,fn(string $org,int $actor)=>$this->methodology->simulate($org,$actor,$id,$version,$this->input($request)),true);
    }

    public function regression(Request $request,string $id,string $version): JsonResponse
    {
        return $this->call($request,fn(string $org,int $actor)=>$this->methodology->regression($org,$actor,$id,$version),true);
    }

    public function clone(Request $request,string $id,string $version): JsonResponse
    {
        return $this->call($request,function(string $org,int $actor)use($request,$id,$version){
            $input=$this->input($request);
            return $this->methodology->clonePack($org,$actor,$id,$version,(string)($input['version']??''));
        },true);
    }

    public function publish(Request $request,string $id,string $version): JsonResponse
    {
        return $this->call($request,fn(string $org,int $actor)=>$this->methodology->publish($org,$actor,$id,$version),true);
    }

    public function archive(Request $request,string $id,string $version): JsonResponse
    {
        return $this->call($request,fn(string $org,int $actor)=>$this->methodology->archive($org,$actor,$id,$version),true);
    }

    public function activate(Request $request,string $id,string $version): JsonResponse
    {
        return $this->call($request,fn(string $org,int $actor)=>$this->methodology->activate($org,$actor,$id,$version),true);
    }

    public function history(Request $request): JsonResponse
    {
        return $this->call($request,fn(string $org,int $actor)=>$this->methodology->history(
            $org,$actor,(string)$request->query->get('pack',''),(string)$request->query->get('version','')
        ));
    }

    public function runs(Request $request): JsonResponse
    {
        return $this->call($request,fn(string $org,int $actor)=>$this->methodology->runs($org,$actor));
    }

    public function permissions(Request $request): JsonResponse
    {
        return $this->call($request,fn(string $org,int $actor)=>$this->methodology->permissions($org,$actor));
    }

    public function workbenchScenarios(Request $request,string $id,string $version): JsonResponse
    {
        return $this->call($request,fn(string $org,int $actor)=>$this->methodology->workbenchScenarios($org,$actor,$id,$version));
    }

    public function deleteScenario(Request $request,string $id,string $version,string $scenario): JsonResponse
    {
        return $this->call($request,fn(string $org,int $actor)=>$this->methodology->deleteScenario($org,$actor,$id,$version,$scenario),true);
    }

    public function cloneScenario(Request $request,string $id,string $version,string $scenario): JsonResponse
    {
        return $this->call($request,fn(string $org,int $actor)=>$this->methodology->cloneScenario(
            $org,$actor,$id,$version,$scenario,$this->input($request)
        ),true);
    }

    public function runScenario(Request $request,string $id,string $version,string $scenario): JsonResponse
    {
        return $this->call($request,fn(string $org,int $actor)=>$this->methodology->runScenario($org,$actor,$id,$version,$scenario),true);
    }

    public function run(Request $request,string $session): JsonResponse
    {
        return $this->call($request,fn(string $org,int $actor)=>$this->methodology->diagnosticRun($org,$actor,$session));
    }

    public function permissionMatrix(Request $request): JsonResponse
    {
        return $this->call($request,fn(string $org,int $actor)=>$this->methodology->permissionMatrix($org,$actor));
    }

    public function permissionOverride(Request $request): JsonResponse
    {
        return $this->call($request,fn(string $org,int $actor)=>$this->methodology->permissionOverride($org,$actor,$this->input($request)),true);
    }

    private function call(Request $request,callable $callback,bool $mutation=false): JsonResponse
    {
        $tenant=$this->tenant();
        if($tenant instanceof JsonResponse) return $tenant;

        $actor=$tenant->userId()->value();
        if(!ctype_digit($actor)||(int)$actor<=0) return $this->error(403,'invalid_actor','Authenticated actor is invalid.');
        if($mutation&&!$this->csrf->isValid($request)) return $this->error(400,'invalid_csrf_token','Invalid CSRF token.');

        try {
            return new JsonResponse(['ok'=>true,'data'=>$callback($tenant->organizationId()->value(),(int)$actor)]);
        } catch (DiagnosticMethodologyPermissionDenied $error) {
            return $this->error(403,'permission_denied',$error->getMessage());
        } catch (Throwable $error) {
            return $this->error(422,'diagnostic_methodology_failed',$error->getMessage());
        }
    }

    private function tenant(): TenantContext|JsonResponse
    {
        $tenant=$this->tenants->current();
        if($tenant===null) return $this->error(403,'tenant_context_required','Tenant context required.');
        if(!$this->modules->isEnabled($tenant->organizationId()->value(),'diagnostic')) {
            return $this->error(403,'diagnostic_module_disabled','Diagnostic module is disabled.');
        }
        return $tenant;
    }

    private function input(Request $request): array
    {
        $decoded=json_decode((string)$request->getContent(),true);
        return is_array($decoded)&&!array_is_list($decoded)?$decoded:$request->request->all();
    }

    private function error(int $status,string $code,string $message): JsonResponse
    {
        return new JsonResponse(['ok'=>false,'error'=>$code,'message'=>$message],$status);
    }
}
