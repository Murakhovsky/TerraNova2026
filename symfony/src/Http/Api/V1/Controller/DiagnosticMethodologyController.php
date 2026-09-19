<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Security\LegacySessionCsrfValidator;
use Domains\Diagnostic\Application\Service\DiagnosticMethodologyAccess;
use Domains\Diagnostic\Application\Service\MethodologyStudioService;
use Domains\Diagnostic\Application\Service\MethodologyWorkbenchService;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

final readonly class DiagnosticMethodologyController
{
    public function __construct(
        private MethodologyStudioService $studio,
        private MethodologyWorkbenchService $workbench,
        private DiagnosticMethodologyAccess $access,
        private TenantContextProviderInterface $tenants,
        private LegacySessionCsrfValidator $csrf,
        private ActiveModuleResolver $modules,
    ) {}

    public function packs(Request $request):JsonResponse
    {
        if($request->isMethod('POST')){
            return $this->call($request,DiagnosticMethodologyAccess::EDIT,fn(string $org,int $actor)=>[
                'created'=>$this->studio->create($org,$this->input($request),(string)$actor),
            ],true);
        }
        return $this->call($request,DiagnosticMethodologyAccess::VIEW,fn(string $org)=>['packs'=>$this->studio->packs($org)]);
    }

    public function pack(Request $request,string $id):JsonResponse{return $this->call($request,DiagnosticMethodologyAccess::VIEW,fn(string $org)=>['pack'=>$this->studio->pack($org,$id)]);}
    public function versions(Request $request,string $id):JsonResponse{return $this->call($request,DiagnosticMethodologyAccess::VIEW,fn(string $org)=>['versions'=>$this->studio->versions($org,$id)]);}
    public function entities(Request $request,string $id,string $version):JsonResponse{return $this->call($request,DiagnosticMethodologyAccess::VIEW,fn(string $org)=>['entities'=>$this->studio->entities($org,$id,$version)]);}

    public function entity(Request $request,string $id,string $version,string $type):JsonResponse
    {
        return $this->call($request,DiagnosticMethodologyAccess::EDIT,function(string $org,int $actor)use($request,$id,$version,$type){
            $this->studio->saveEntity($org,$id,$version,$type,$this->input($request),(string)$actor);return ['saved'=>true];
        },true);
    }

    public function deleteEntity(Request $request,string $id,string $version,string $type,string $entity):JsonResponse
    {
        return $this->call($request,DiagnosticMethodologyAccess::EDIT,function(string $org,int $actor)use($id,$version,$type,$entity){
            $this->studio->deleteEntity($org,$id,$version,$type,$entity,(string)$actor);return ['deleted'=>true];
        },true);
    }

    public function scenarios(Request $request,string $id,string $version):JsonResponse
    {
        if($request->isMethod('POST')){
            return $this->call($request,DiagnosticMethodologyAccess::EDIT,function(string $org,int $actor)use($request,$id,$version){
                $this->studio->saveScenario($org,$id,$version,$this->input($request),(string)$actor);return ['saved'=>true];
            },true);
        }
        return $this->call($request,DiagnosticMethodologyAccess::VIEW,fn(string $org)=>['scenarios'=>$this->studio->scenarios($org,$id,$version)]);
    }

    public function validate(Request $r,string $id,string $version):JsonResponse{return $this->call($r,DiagnosticMethodologyAccess::EDIT,fn(string $o)=>$this->studio->validate($o,$id,$version),true);}
    public function simulate(Request $r,string $id,string $version):JsonResponse{return $this->call($r,DiagnosticMethodologyAccess::EDIT,fn(string $o)=>$this->studio->simulate($o,$id,$version,$this->input($r)),true);}
    public function regression(Request $r,string $id,string $version):JsonResponse{return $this->call($r,DiagnosticMethodologyAccess::EDIT,fn(string $o)=>$this->studio->runRegression($o,$id,$version),true);}

    public function clone(Request $r,string $id,string $version):JsonResponse
    {
        return $this->call($r,DiagnosticMethodologyAccess::EDIT,function(string $o,int $actor)use($r,$id,$version){
            $this->studio->clone($o,$id,$version,(string)($this->input($r)['version']??''),(string)$actor);return ['cloned'=>true];
        },true);
    }

    public function publish(Request $r,string $id,string $version):JsonResponse{return $this->lifecycle($r,$id,$version,'publish');}
    public function archive(Request $r,string $id,string $version):JsonResponse{return $this->lifecycle($r,$id,$version,'archive');}
    public function activate(Request $r,string $id,string $version):JsonResponse{return $this->lifecycle($r,$id,$version,'activate');}

    public function history(Request $request):JsonResponse
    {
        return $this->call($request,DiagnosticMethodologyAccess::VIEW,fn(string $org)=>['history'=>$this->studio->history($org,(string)$request->query->get('pack',''),(string)$request->query->get('version',''))]);
    }
    public function runs(Request $request):JsonResponse{return $this->call($request,DiagnosticMethodologyAccess::VIEW,fn(string $org)=>['runs'=>$this->studio->runs($org)]);}
    public function permissions(Request $request):JsonResponse
    {
        return $this->call($request,DiagnosticMethodologyAccess::VIEW,function(string $org,int $actor){
            return ['view'=>true,'edit'=>$this->access->allows($org,$actor,DiagnosticMethodologyAccess::EDIT),'publish'=>$this->access->allows($org,$actor,DiagnosticMethodologyAccess::PUBLISH)];
        });
    }

    public function workbenchScenarios(Request $request,string $id,string $version):JsonResponse{return $this->call($request,DiagnosticMethodologyAccess::VIEW,fn(string $o)=>['scenarios'=>$this->workbench->scenarios($o,$id,$version)]);}
    public function deleteScenario(Request $r,string $id,string $version,string $scenario):JsonResponse
    {
        return $this->call($r,DiagnosticMethodologyAccess::EDIT,function(string $o,int $actor)use($id,$version,$scenario){$this->workbench->deleteScenario($o,$id,$version,$scenario,(string)$actor);return ['deleted'=>true];},true);
    }
    public function cloneScenario(Request $r,string $id,string $version,string $scenario):JsonResponse
    {
        return $this->call($r,DiagnosticMethodologyAccess::EDIT,function(string $o,int $actor)use($r,$id,$version,$scenario){$i=$this->input($r);return ['scenario'=>$this->workbench->cloneScenario($o,$id,$version,$scenario,(string)($i['id']??''),(string)($i['name']??''),(string)$actor)];},true);
    }
    public function runScenario(Request $r,string $id,string $version,string $scenario):JsonResponse{return $this->call($r,DiagnosticMethodologyAccess::EDIT,fn(string $o)=>['result'=>$this->workbench->runScenario($o,$id,$version,$scenario)],true);}
    public function run(Request $r,string $session):JsonResponse{return $this->call($r,DiagnosticMethodologyAccess::EDIT,fn(string $o)=>['run'=>$this->workbench->diagnosticRun($o,$session)]);}
    public function permissionMatrix(Request $r):JsonResponse{return $this->call($r,DiagnosticMethodologyAccess::PUBLISH,fn(string $o)=>['matrix'=>$this->workbench->permissionMatrix($o)]);}
    public function permissionOverride(Request $r):JsonResponse
    {
        return $this->call($r,DiagnosticMethodologyAccess::PUBLISH,fn(string $o,int $actor)=>['matrix'=>$this->workbench->setPermission($o,$actor,$this->input($r))],true);
    }

    private function lifecycle(Request $request,string $id,string $version,string $operation):JsonResponse
    {
        return $this->call($request,DiagnosticMethodologyAccess::PUBLISH,function(string $org,int $actor)use($id,$version,$operation){
            match($operation){'publish'=>$this->studio->publish($org,$id,$version,(string)$actor),'archive'=>$this->studio->archive($org,$id,$version,(string)$actor),'activate'=>$this->studio->activate($org,$id,$version,(string)$actor)};
            return [$operation.'ed'=>true];
        },true);
    }

    private function call(Request $request,string $permission,callable $callback,bool $mutation=false):JsonResponse
    {
        $tenant=$this->tenant();
        if($tenant instanceof JsonResponse) return $tenant;
        $actor=$tenant->userId()->value();
        if(!ctype_digit($actor)||(int)$actor<=0) return $this->error(403,'invalid_actor','Authenticated actor is invalid.');
        $org=$tenant->organizationId()->value();
        if(!$this->access->allows($org,(int)$actor,$permission)) return $this->error(403,'permission_denied','Missing permission: '.$permission);
        if($mutation&&!$this->csrf->isValid($request)) return $this->error(400,'invalid_csrf_token','Invalid CSRF token.');
        try{return new JsonResponse(['ok'=>true,'data'=>$callback($org,(int)$actor)]);}
        catch(Throwable $e){return $this->error(422,'diagnostic_methodology_failed',$e->getMessage());}
    }

    private function tenant():TenantContext|JsonResponse
    {
        $tenant=$this->tenants->current();
        if($tenant===null) return $this->error(403,'tenant_context_required','Tenant context required.');
        if(!$this->modules->isEnabled($tenant->organizationId()->value(),'diagnostic')) return $this->error(403,'diagnostic_module_disabled','Diagnostic module is disabled.');
        return $tenant;
    }

    private function input(Request $request):array
    {
        $decoded=json_decode((string)$request->getContent(),true);
        return is_array($decoded)&&!array_is_list($decoded)?$decoded:$request->request->all();
    }

    private function error(int $status,string $code,string $message):JsonResponse{return new JsonResponse(['ok'=>false,'error'=>$code,'message'=>$message],$status);}
}
