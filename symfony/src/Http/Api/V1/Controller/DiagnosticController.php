<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Application\Diagnostic\Command\AcceptDiagnosticRecommendationCommand;
use App\Application\Diagnostic\Command\AnswerDiagnosticInterviewCommand;
use App\Application\Diagnostic\Command\CaptureDiagnosticEvidenceCommand;
use App\Application\Diagnostic\Command\CompleteDiagnosticCommand;
use App\Application\Diagnostic\Command\StartDiagnosticCommand;
use App\Application\Diagnostic\Query\GetDiagnosticAssessmentQuery;
use App\Application\Diagnostic\Query\GetDiagnosticFindingsQuery;
use App\Application\Diagnostic\Query\GetDiagnosticNextQuestionQuery;
use App\Application\Diagnostic\Query\GetDiagnosticRecommendationsQuery;
use App\Application\Diagnostic\Query\GetDiagnosticReportQuery;
use App\Application\Diagnostic\Query\GetDiagnosticSessionQuery;
use App\Security\LegacySessionCsrfValidator;
use DomainException;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Observability\CorrelationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Throwable;

final readonly class DiagnosticController
{
    public function __construct(private CommandBusInterface $commands,private QueryBusInterface $queries,private TenantContextProviderInterface $tenants,private LegacySessionCsrfValidator $csrf,private ActiveModuleResolver $modules){}

    public function create(Request $request):JsonResponse
    {
        $c=$this->mutationContext($request,false); if($c instanceof JsonResponse)return $c; $key=$this->idempotencyKey($request); if($key instanceof JsonResponse)return $key;
        return $this->dispatch(fn()=>$this->commands->dispatch(new StartDiagnosticCommand($c->organizationId(),(int)$c->userId()->value(),$this->correlationId($request),$key,$this->input($request))),201);
    }
    public function view(string $id):JsonResponse{$c=$this->readContext(false);if($c instanceof JsonResponse)return $c;return $this->dispatch(fn()=>$this->queries->ask(new GetDiagnosticSessionQuery($c->organizationId(),$id)));}
    public function nextQuestion(string $id):JsonResponse{$c=$this->readContext(false);if($c instanceof JsonResponse)return $c;return $this->dispatch(fn()=>$this->queries->ask(new GetDiagnosticNextQuestionQuery($c->organizationId(),$id)));}
    public function answer(Request $request,string $id):JsonResponse
    {
        $c=$this->mutationContext($request,false);if($c instanceof JsonResponse)return $c;$key=$this->idempotencyKey($request);if($key instanceof JsonResponse)return $key;$input=$this->input($request);$answer=trim((string)($input['answer']??''));if($answer==='')return $this->error(422,'answer_required','answer is required.');
        return $this->dispatch(fn()=>$this->commands->dispatch(new AnswerDiagnosticInterviewCommand($c->organizationId(),(int)$c->userId()->value(),$id,$answer,$this->correlationId($request),$key)));
    }
    public function evidence(Request $request,string $id):JsonResponse
    {
        $c=$this->mutationContext($request,false);if($c instanceof JsonResponse)return $c;$key=$this->idempotencyKey($request);if($key instanceof JsonResponse)return $key;
        return $this->dispatch(fn()=>$this->commands->dispatch(new CaptureDiagnosticEvidenceCommand($c->organizationId(),(int)$c->userId()->value(),$id,$this->correlationId($request),$key,$this->input($request))),201);
    }
    public function complete(Request $request,string $id):JsonResponse
    {
        $c=$this->mutationContext($request,true);if($c instanceof JsonResponse)return $c;$key=$this->idempotencyKey($request);if($key instanceof JsonResponse)return $key;
        return $this->dispatch(fn()=>$this->commands->dispatch(new CompleteDiagnosticCommand($c->organizationId(),(int)$c->userId()->value(),$id,$this->correlationId($request))));
    }
    public function report(string $id):JsonResponse{$c=$this->readContext(false);if($c instanceof JsonResponse)return $c;return $this->dispatch(fn()=>$this->queries->ask(new GetDiagnosticReportQuery($c->organizationId(),$id)));}
    public function assessment(string $id):JsonResponse{$c=$this->readContext(false);if($c instanceof JsonResponse)return $c;return $this->dispatch(fn()=>$this->queries->ask(new GetDiagnosticAssessmentQuery($c->organizationId(),$id)));}
    public function findings(string $id):JsonResponse{$c=$this->readContext(false);if($c instanceof JsonResponse)return $c;return $this->dispatch(fn()=>$this->queries->ask(new GetDiagnosticFindingsQuery($c->organizationId(),$id)));}
    public function recommendations(string $id):JsonResponse{$c=$this->readContext(false);if($c instanceof JsonResponse)return $c;return $this->dispatch(fn()=>$this->queries->ask(new GetDiagnosticRecommendationsQuery($c->organizationId(),$id)));}
    public function recommendationAction(Request $request,string $id,string $recommendationId):JsonResponse
    {
        $c=$this->mutationContext($request,true);
        if($c instanceof JsonResponse)return $c;
        $key=$this->idempotencyKey($request);
        if($key instanceof JsonResponse)return $key;

        $input=$this->input($request);
        $ownerId=(int)($input['owner_id']??0);
        if($ownerId<=0)return $this->error(422,'owner_id_required','A positive owner_id is required.');

        $rawDueAt=trim((string)($input['due_at']??''));
        if($rawDueAt==='')return $this->error(422,'due_at_required','due_at is required.');
        try{$dueAt=new \DateTimeImmutable($rawDueAt);}catch(\Throwable){
            return $this->error(422,'invalid_due_at','due_at must be a valid date-time.');
        }
        if($dueAt<=new \DateTimeImmutable())return $this->error(422,'invalid_due_at','due_at must be in the future.');

        $workflowCode=trim((string)($input['workflow_code']??\Domains\Diagnostic\Application\UseCase\AcceptDiagnosticRecommendation::WORKFLOW));
        if($workflowCode===''||preg_match('/^[a-z][a-z0-9._:-]*$/',$workflowCode)!==1){
            return $this->error(422,'invalid_workflow_code','workflow_code is invalid.');
        }

        return $this->dispatch(fn()=>$this->commands->dispatch(new AcceptDiagnosticRecommendationCommand(
            $c->organizationId(),
            (int)$c->userId()->value(),
            $id,
            $recommendationId,
            $ownerId,
            $dueAt,
            $workflowCode,
            $this->correlationId($request),
        )),202);
    }

    private function readContext(bool $manage):TenantContext|JsonResponse
    {
        $tenant=$this->tenants->current();if($tenant===null)return $this->error(403,'tenant_context_required','Tenant context required.');
        if(!$tenant->allows($manage?TenantPermissions::MANAGE:TenantPermissions::ACCESS))return $this->error(403,'permission_denied','Permission denied.');
        if(!$this->modules->isEnabled($tenant->organizationId()->value(),'diagnostic'))return $this->error(403,'diagnostic_module_disabled','Diagnostic module is disabled for this organization.');
        return $tenant;
    }
    private function mutationContext(Request $request,bool $manage):TenantContext|JsonResponse
    {
        $tenant=$this->readContext($manage);if($tenant instanceof JsonResponse)return $tenant;if(!$this->csrf->isValid($request))return $this->error(400,'invalid_csrf_token','Invalid CSRF token.');
        $actor=$tenant->userId()->value();if(!ctype_digit($actor)||(int)$actor<=0)return $this->error(403,'invalid_actor','Authenticated actor is invalid.');return $tenant;
    }
    private function input(Request $request):array{$decoded=json_decode((string)$request->getContent(),true);return is_array($decoded)&&!array_is_list($decoded)?$decoded:$request->request->all();}
    private function idempotencyKey(Request $request):string|JsonResponse{$key=trim((string)$request->headers->get('X-Idempotency-Key',''));return $key===''||strlen($key)>191?$this->error(422,'idempotency_key_required','A valid X-Idempotency-Key is required.'):$key;}
    private function correlationId(Request $request):string{$value=$request->attributes->get('_cos_correlation_id');return $value instanceof CorrelationId?$value->value():CorrelationId::generate()->value();}
    private function dispatch(callable $operation,int $successStatus=200):JsonResponse
    {
        try{return new JsonResponse(['ok'=>true,'data'=>$operation()],$successStatus);}catch(Throwable $error){$root=$error;if($error instanceof HandlerFailedException&&$error->getPrevious() instanceof Throwable)$root=$error->getPrevious();$message=$root->getMessage();$normalized=strtolower($message);
            $status=str_contains($normalized,'not found')?404:(($root instanceof DomainException&&(str_contains($normalized,'transition')||str_contains($normalized,'already has an action')))?409:422);return $this->error($status,'diagnostic_operation_failed',$message);}
    }
    private function error(int $status,string $code,string $message):JsonResponse{return new JsonResponse(['ok'=>false,'error'=>$code,'message'=>$message],$status);}
}
