<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Persistence\Workflow\DoctrineWorkflowRecoveryManager;
use App\Security\SessionCsrfValidator;
use DomainException;
use InvalidArgumentException;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use LogicException;
use Platform\Workflow\Model\WorkflowRecoveryPetition;
use Platform\Workflow\Model\WorkflowRecoveryResolution;
use Platform\Workflow\Model\WorkflowRunCommand;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/** Four safe operator APIs: tenant worklist, request, second-person approve, apply. */
final readonly class WorkflowRecoveryController
{
    public function __construct(
        private DoctrineWorkflowRecoveryManager $recovery,
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
    ) {}

    public function worklist(Request $request): JsonResponse
    {
        $actor=$this->manager();
        if($actor instanceof JsonResponse)return $actor;
        try {
            $limit=max(1,min(100,(int)$request->query->get('limit',50)));
            $command=$this->command($actor,'_recovery_worklist_',1,null);
            return new JsonResponse(['ok'=>true,'data'=>$this->recovery->expiredClaims($command,$limit)]);
        } catch(Throwable $e){return $this->failure($e);}
    }

    public function requests(Request $request,string $runId): JsonResponse
    {
        $actor=$this->manager();
        if($actor instanceof JsonResponse)return $actor;
        try {
            $limit=max(1,min(100,(int)$request->query->get('limit',50)));
            $command=$this->command($actor,$runId,1,null);
            return new JsonResponse(['ok'=>true,'data'=>$this->recovery->requestsForRun($command,$limit)]);
        } catch(Throwable $e){return $this->failure($e);}
    }

    public function inspect(Request $request,string $runId): JsonResponse
    {
        $actor=$this->manager();
        if($actor instanceof JsonResponse)return $actor;
        try {
            $rev=(int)$request->query->get('revision',0);
            $result=$this->recovery->inspect($this->command($actor,$runId,$rev,null));
            return new JsonResponse(['ok'=>true,'data'=>[
                'run_id'=>$runId,'disposition'=>$result->disposition->value,
                'reason'=>$result->reason,'external_outcome_unknown'=>$result->externalOutcomeUnknown,
            ]]);
        } catch(Throwable $e){return $this->failure($e);}
    }

    public function request(Request $request,string $runId): JsonResponse
    {
        $actor=$this->manager($request);
        if($actor instanceof JsonResponse)return $actor;
        try {
            $data=$this->input($request);
            $command=$this->command($actor,$runId,(int)($data['run_revision']??0),null);
            $petition=new WorkflowRecoveryPetition(
                $command,
                isset($data['request_id'])?(string)$data['request_id']:bin2hex(random_bytes(16)),
                (string)($data['step_id']??''),(int)($data['step_revision']??0),
                WorkflowRecoveryResolution::from((string)($data['resolution']??'')),
                (string)($data['reason']??''),
                isset($data['receipt_reference'])?(string)$data['receipt_reference']:null,
            );
            return new JsonResponse(['ok'=>true,'data'=>[
                'request_id'=>$this->recovery->request($petition),
                'status'=>'REQUESTED','requires_different_manager'=>true,
            ]],201);
        } catch(Throwable $e){return $this->failure($e);}
    }

    public function approve(Request $request,string $runId): JsonResponse
    {
        $actor=$this->manager($request);
        if($actor instanceof JsonResponse)return $actor;
        try {
            $data=$this->input($request);
            $id=(string)($data['request_id']??'');
            $this->recovery->approve(
                $this->command($actor,$runId,(int)($data['run_revision']??0),null),$id,
            );
            return new JsonResponse(['ok'=>true,'data'=>[
                'request_id'=>$id,'status'=>'APPROVED','external_effect_replayed'=>false,
            ]]);
        } catch(Throwable $e){return $this->failure($e);}
    }

    public function apply(Request $request,string $runId): JsonResponse
    {
        $actor=$this->manager($request);
        if($actor instanceof JsonResponse)return $actor;
        try {
            $data=$this->input($request);
            $command=$this->command($actor,$runId,
                (int)($data['run_revision']??0),(string)($data['request_id']??''));
            $result=$this->recovery->reconcile($command);
            return new JsonResponse(['ok'=>true,'data'=>[
                'run_id'=>$runId,'disposition'=>$result->disposition->value,
                'reason'=>$result->reason,
                'external_outcome_unknown'=>$result->externalOutcomeUnknown,
                'external_effect_replayed'=>false,
            ]]);
        } catch(Throwable $e){return $this->failure($e);}
    }

    private function manager(?Request $mutation=null): TenantContext|JsonResponse
    {
        $actor=$this->tenants->current();
        if($actor===null || !$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)) {
            return new JsonResponse(['ok'=>false,'error'=>'manager_required'],403);
        }
        if($mutation!==null && !$this->csrf->isValid($mutation)) {
            return new JsonResponse(['ok'=>false,'error'=>'invalid_csrf_token'],403);
        }
        return $actor;
    }

    private function command(
        TenantContext $actor,string $runId,int $revision,?string $reference,
    ): WorkflowRunCommand {
        return new WorkflowRunCommand(
            new WorkflowRunIdentity($actor->organizationId()->value(),$runId),
            $actor,$revision,'workflow-recovery:'.$runId,$reference,
        );
    }

    /** @return array<string,mixed> */
    private function input(Request $request):array
    {
        $data=json_decode((string)$request->getContent(),true);
        return is_array($data) && !array_is_list($data)?$data:$request->request->all();
    }

    private function failure(Throwable $error):JsonResponse
    {
        $code=$error instanceof LogicException?409:
            ($error instanceof DomainException||$error instanceof InvalidArgumentException
                ||$error instanceof \ValueError?422:500);
        // Unexpected server internals must not be returned in API bodies.
        return new JsonResponse(['ok'=>false,
            'error'=>$code===500?'recovery_internal_error':'recovery_validation_failed',
            'message'=>$code===500?'Workflow recovery failed.':$error->getMessage(),
        ],$code);
    }
}
