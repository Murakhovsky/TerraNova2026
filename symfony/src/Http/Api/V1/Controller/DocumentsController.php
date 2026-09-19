<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Application\Documents\Command\ArchiveDocumentCommand;
use App\Application\Documents\Command\AttachDocumentCommand;
use App\Application\Documents\Command\CreateDocumentVersionCommand;
use App\Application\Documents\Command\GenerateDocumentFromTemplateCommand;
use App\Application\Documents\Command\RequestDocumentSignatureCommand;
use App\Application\Documents\Command\SignDocumentCommand;
use App\Application\Documents\Command\UploadDocumentCommand;
use App\Application\Documents\Query\GetDocumentQuery;
use App\Security\LegacySessionCsrfValidator;
use DomainException;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Observability\CorrelationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Throwable;

final readonly class DocumentsController
{
    public function __construct(
        private QueryBusInterface $queries,
        private CommandBusInterface $commands,
        private TenantContextProviderInterface $tenants,
        private LegacySessionCsrfValidator $csrf,
    ) {}

    public function view(string $id):JsonResponse
    {
        $tenant=$this->context(null,false);
        if($tenant instanceof JsonResponse)return $tenant;
        try{
            $data=$this->queries->ask(new GetDocumentQuery($tenant->organizationId(),$id));
            return $data===null
                ?$this->error(404,'document_not_found','Document was not found.')
                :$this->ok($data);
        }catch(Throwable $error){
            return $this->failure($error);
        }
    }

    public function upload(Request $request):JsonResponse
    {
        $tenant=$this->context($request,true);
        if($tenant instanceof JsonResponse)return $tenant;
        $key=$this->idempotencyKey($request);
        if($key instanceof JsonResponse)return $key;
        try{
            return $this->ok($this->commands->dispatch(new UploadDocumentCommand(
                $tenant->organizationId(),(int)$tenant->userId()->value(),$this->correlationId($request),$key,$this->input($request),
            )),201);
        }catch(Throwable $error){
            return $this->failure($error);
        }
    }

    public function attach(Request $request,string $id):JsonResponse
    {
        $tenant=$this->context($request,true);
        if($tenant instanceof JsonResponse)return $tenant;
        $key=$this->idempotencyKey($request);
        if($key instanceof JsonResponse)return $key;
        $input=$this->input($request);
        try{
            return $this->ok($this->commands->dispatch(new AttachDocumentCommand(
                $tenant->organizationId(),(int)$tenant->userId()->value(),$this->correlationId($request),$id,
                trim((string)($input['related_type']??'')),trim((string)($input['related_id']??'')),$key,
            )),201);
        }catch(Throwable $error){
            return $this->failure($error);
        }
    }

    public function createVersion(Request $request,string $id):JsonResponse
    {
        $tenant=$this->context($request,true);
        if($tenant instanceof JsonResponse)return $tenant;
        $key=$this->idempotencyKey($request);
        if($key instanceof JsonResponse)return $key;
        try{
            return $this->ok($this->commands->dispatch(new CreateDocumentVersionCommand(
                $tenant->organizationId(),(int)$tenant->userId()->value(),$this->correlationId($request),$id,$key,$this->input($request),
            )),201);
        }catch(Throwable $error){
            return $this->failure($error);
        }
    }

    public function generate(Request $request,string $id):JsonResponse
    {
        $tenant=$this->context($request,true);
        if($tenant instanceof JsonResponse)return $tenant;
        $key=$this->idempotencyKey($request);
        if($key instanceof JsonResponse)return $key;
        try{
            return $this->ok($this->commands->dispatch(new GenerateDocumentFromTemplateCommand(
                $tenant->organizationId(),(int)$tenant->userId()->value(),$this->correlationId($request),$id,$key,$this->input($request),
            )),201);
        }catch(Throwable $error){
            return $this->failure($error);
        }
    }

    public function requestSignature(Request $request,string $id):JsonResponse
    {
        $tenant=$this->context($request,true);
        if($tenant instanceof JsonResponse)return $tenant;
        $key=$this->idempotencyKey($request);
        if($key instanceof JsonResponse)return $key;
        $input=$this->input($request);
        try{
            return $this->ok($this->commands->dispatch(new RequestDocumentSignatureCommand(
                $tenant->organizationId(),(int)$tenant->userId()->value(),$this->correlationId($request),$id,
                trim((string)($input['signer_id']??'')),$key,
            )),201);
        }catch(Throwable $error){
            return $this->failure($error);
        }
    }

    public function sign(Request $request,string $id):JsonResponse
    {
        $tenant=$this->context($request,true);
        if($tenant instanceof JsonResponse)return $tenant;
        $key=$this->idempotencyKey($request);
        if($key instanceof JsonResponse)return $key;
        $input=$this->input($request);
        try{
            return $this->ok($this->commands->dispatch(new SignDocumentCommand(
                $tenant->organizationId(),(int)$tenant->userId()->value(),$this->correlationId($request),$id,
                trim((string)($input['signed_by']??'')),trim((string)($input['signature_reference']??'')),$key,
            )));
        }catch(Throwable $error){
            return $this->failure($error);
        }
    }

    public function archive(Request $request,string $id):JsonResponse
    {
        $tenant=$this->context($request,true);
        if($tenant instanceof JsonResponse)return $tenant;
        $key=$this->idempotencyKey($request);
        if($key instanceof JsonResponse)return $key;
        try{
            return $this->ok($this->commands->dispatch(new ArchiveDocumentCommand(
                $tenant->organizationId(),(int)$tenant->userId()->value(),$this->correlationId($request),$id,$key,
            )));
        }catch(Throwable $error){
            return $this->failure($error);
        }
    }

    private function context(?Request $request,bool $mutation):TenantContext|JsonResponse
    {
        $tenant=$this->tenants->current();
        if($tenant===null)return $this->error(403,'tenant_context_required','Tenant context required.');
        if(!$tenant->allows(TenantPermissions::ACCESS)){
            return $this->error(403,'documents_access_denied','Documents access denied.');
        }
        if($mutation&&(!$tenant->isManager()||!$tenant->allows(TenantPermissions::MANAGE))){
            return $this->error(403,'documents_manage_required','Documents manager permission required.');
        }
        if($mutation&&($request===null||!$this->csrf->isValid($request))){
            return $this->error(400,'invalid_csrf_token','Invalid CSRF token.');
        }
        $actor=$tenant->userId()->value();
        if(!ctype_digit($actor)||(int)$actor<=0)return $this->error(403,'invalid_actor','Authenticated actor is invalid.');
        return $tenant;
    }

    private function idempotencyKey(Request $request):string|JsonResponse
    {
        $key=trim((string)$request->headers->get('X-Idempotency-Key',''));
        return $key===''||mb_strlen($key)>191
            ?$this->error(422,'idempotency_key_required','A valid X-Idempotency-Key is required.')
            :$key;
    }

    /** @return array<string,mixed> */
    private function input(Request $request):array
    {
        $decoded=json_decode((string)$request->getContent(),true);
        return is_array($decoded)&&!array_is_list($decoded)?$decoded:$request->request->all();
    }

    private function correlationId(Request $request):string
    {
        $value=$request->attributes->get('_cos_correlation_id');
        return $value instanceof CorrelationId?$value->value():CorrelationId::generate()->value();
    }

    private function failure(Throwable $error):JsonResponse
    {
        $root=$error instanceof HandlerFailedException&&$error->getPrevious() instanceof Throwable
            ?$error->getPrevious():$error;
        $message=$root->getMessage();
        $normalized=strtolower($message);
        $status=match(true){
            str_contains($normalized,'not found')=>404,
            str_contains($normalized,'idempotency'),
            str_contains($normalized,'already'),
            str_contains($normalized,'archived'),
            str_contains($normalized,'current status')=>409,
            $root instanceof DomainException,
            $root instanceof \InvalidArgumentException,
            $root instanceof \ValueError=>422,
            default=>500,
        };
        return $this->error($status,'documents_operation_failed',$message);
    }

    private function ok(mixed $data,int $status=200):JsonResponse{return new JsonResponse(['ok'=>true,'data'=>$data],$status);}
    private function error(int $status,string $code,string $message):JsonResponse{return new JsonResponse(['ok'=>false,'error'=>$code,'message'=>$message],$status);}
}
