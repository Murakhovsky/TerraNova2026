<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Security\SessionCsrfValidator;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Application\Audit\CapitalMarketsAuditTrail;
use Domains\CapitalMarkets\Application\Audit\CapitalMarketsAuditAction;
use Domains\CapitalMarkets\Application\Audit\CapitalMarketsAuditResourceType;
use Domains\CapitalMarkets\Application\Service\ResearchLabService;
use Domains\CapitalMarkets\Application\Service\ResearchBacktestService;
use Domains\CapitalMarkets\Application\Service\CapitalMarketsResearchAgentService;
use Domains\CapitalMarkets\Application\Service\ResearchPaperRunService;
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use InvalidArgumentException;
use JsonException;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use PDOException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

final readonly class CapitalMarketsResearchLabController
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private ActiveModuleResolver $modules,
        private CapitalMarketsAccessControlInterface $access,
        private CapitalMarketsAuditTrail $audit,
        private ResearchLabService $lab,
        private ResearchBacktestService $backtests,
        private CapitalMarketsResearchAgentService $researchAgent,
        private ResearchPaperRunService $paperRuns,
        private SessionCsrfValidator $csrf,
    ){}

    public function runAgent(Request $request):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::ResearchAgentUse,
            function(TenantContext $tenant,array $payload):array{
                $subjectType=trim((string)($payload['subject_type']??'research'));
                $subjectId=trim((string)($payload['subject_id']??'capital-markets'));
                $question=$this->required($payload,'question');
                $correlation=trim((string)($payload['correlation_id']??''));
                if($correlation==='')$correlation='CM-RESEARCH-'.strtoupper(bin2hex(random_bytes(6)));
                return $this->researchAgent->run(
                    $tenant->organizationId()->value(),
                    $subjectType===''?'research':$subjectType,
                    $subjectId===''?'capital-markets':$subjectId,
                    $question,
                    $correlation,
                );
            },200);
    }

    public function workspace():JsonResponse
    {
        $context=$this->context(CapitalMarketsCapability::ResearchView);
        if($context instanceof JsonResponse)return $context;
        [$tenant]=$context;
        return $this->respond(fn():array=>$this->lab->workspace($tenant->organizationId()->value()));
    }

    public function createHypothesis(Request $request):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::ResearchManage,
            function(TenantContext $tenant,array $payload) use($request):array{
                $result=$this->lab->createHypothesis($tenant->organizationId()->value(),$payload);
                $this->auditResult($request,$tenant,CapitalMarketsAuditAction::ResearchHypothesisCreated,CapitalMarketsAuditResourceType::ResearchHypothesis,(string)$result['hypothesis_id'],$result);
                return $result;
            },201);
    }

    public function reviseHypothesis(Request $request,string $id):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::ResearchManage,
            function(TenantContext $tenant,array $payload) use($request,$id):array{
                $result=$this->lab->reviseHypothesis($tenant->organizationId()->value(),$id,$payload);
                $this->auditResult($request,$tenant,CapitalMarketsAuditAction::ResearchHypothesisRevised,CapitalMarketsAuditResourceType::ResearchHypothesis,$id,$result);
                return $result;
            },201);
    }

    public function freezeDataset(Request $request):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::ResearchManage,
            function(TenantContext $tenant,array $payload) use($request):array{
                $result=$this->lab->freezeDataset($tenant->organizationId()->value(),$payload);
                $this->auditResult($request,$tenant,CapitalMarketsAuditAction::ResearchDatasetFrozen,CapitalMarketsAuditResourceType::ResearchDataset,(string)$result['dataset_id'],$result);
                return $result;
            },201);
    }

    public function createExperiment(Request $request):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::ResearchManage,
            function(TenantContext $tenant,array $payload) use($request):array{
                $result=$this->lab->createExperiment($tenant->organizationId()->value(),$payload);
                $this->auditResult($request,$tenant,CapitalMarketsAuditAction::ResearchExperimentCreated,CapitalMarketsAuditResourceType::ResearchExperiment,(string)$result['experiment_id'],$result);
                return $result;
            },201);
    }

    public function transitionExperiment(Request $request,string $id):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::ResearchExperimentRun,
            fn(TenantContext $tenant,array $payload):array=>$this->lab->transitionExperiment(
                $tenant->organizationId()->value(),$id,$this->required($payload,'status')
            ),200);
    }

    public function createStrategyVersion(Request $request):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::StrategyVersionManage,
            function(TenantContext $tenant,array $payload) use($request):array{
                $result=$this->lab->createStrategyVersion($tenant->organizationId()->value(),$payload);
                $this->auditResult($request,$tenant,CapitalMarketsAuditAction::ResearchStrategyVersionCreated,CapitalMarketsAuditResourceType::ResearchStrategyVersion,(string)$result['strategy_version_id'],$result);
                return $result;
            },201);
    }

    public function recordResult(Request $request):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::ResearchExperimentRun,
            fn(TenantContext $tenant,array $payload):array=>$this->lab->recordResult($tenant->organizationId()->value(),$payload),201);
    }

    public function queueBacktest(Request $request):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::ResearchExperimentRun,
            fn(TenantContext $tenant,array $payload):array=>$this->backtests->queue($tenant->organizationId()->value(),$payload),202);
    }

    public function runBacktest(Request $request):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::ResearchExperimentRun,
            fn(TenantContext $tenant,array $payload):array=>$this->backtests->run($tenant->organizationId()->value(),$payload),201);
    }

    public function cancelBacktest(Request $request,string $id):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::ResearchExperimentRun,
            fn(TenantContext $tenant,array $payload):array=>$this->backtests->cancel(
                $tenant->organizationId()->value(),$id,trim((string)($payload['reason']??'USER_CANCELLED'))
            ),200);
    }

    public function walkForward(Request $request):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::ResearchExperimentRun,
            fn(TenantContext $tenant,array $payload):array=>$this->backtests->walkForward($tenant->organizationId()->value(),$payload),200);
    }

    public function startPaperRun(Request $request):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::ResearchExperimentRun,
            fn(TenantContext $tenant,array $payload):array=>$this->paperRuns->start($tenant->organizationId()->value(),$payload),201);
    }

    public function completePaperRun(Request $request,string $id):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::ResearchExperimentRun,
            fn(TenantContext $tenant,array $payload):array=>$this->paperRuns->complete(
                $tenant->organizationId()->value(),$id,
                $this->object($payload,'performance_snapshot'),
                trim((string)($payload['result_id']??'')),
                trim((string)($payload['decision_id']??''))
            ),200);
    }

    public function cancelPaperRun(Request $request,string $id):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::ResearchExperimentRun,
            fn(TenantContext $tenant,array $payload):array=>$this->paperRuns->cancel(
                $tenant->organizationId()->value(),$id,trim((string)($payload['reason']??'USER_CANCELLED'))
            ),200);
    }

    public function scorecard(Request $request,string $id):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::ResearchManage,
            fn(TenantContext $tenant,array $payload):array=>$this->lab->createScorecard(
                $tenant->organizationId()->value(),
                $id,
                $this->object($payload,'dimensions'),
                $this->object($payload,'weights'),
                trim((string)($payload['weight_version']??'v1')),
            ),201);
    }

    public function promotion(Request $request,string $id):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::StrategyPromote,
            fn(TenantContext $tenant,array $payload):array=>$this->lab->evaluatePromotion(
                $tenant->organizationId()->value(),
                $id,
                $this->required($payload,'from'),
                $this->required($payload,'to'),
                $this->object($payload,'actual'),
                $this->object($payload,'policy'),
                $tenant->userId()->value(),
            ),201);
    }

    public function reject(Request $request):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::StrategyReject,
            fn(TenantContext $tenant,array $payload):array=>$this->lab->rejectHypothesis($tenant->organizationId()->value(),$payload),201);
    }

    public function knowledge(Request $request):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::ResearchManage,
            fn(TenantContext $tenant,array $payload):array=>$this->lab->recordKnowledge($tenant->organizationId()->value(),$payload),201);
    }

    private function mutation(Request $request,CapitalMarketsCapability $capability,callable $operation,int $success=200):JsonResponse
    {
        $context=$this->context($capability);
        if($context instanceof JsonResponse)return $context;
        [$tenant]=$context;
        if(!$this->csrf->isValid($request))return $this->error('Invalid CSRF token.',403);
        try{return $this->ok($operation($tenant,$this->payload($request)),$success);}
        catch(PDOException $error){return $this->error('Capital Markets research persistence conflict.',409);}
        catch(InvalidArgumentException $error){return $this->error($error->getMessage(),422);}
        catch(Throwable $error){
            error_log('capital_markets.research_lab.api.failure '.$error->getMessage());
            return $this->error('Research Lab operation failed.',500);
        }
    }

    private function context(CapitalMarketsCapability $capability):array|JsonResponse
    {
        $tenant=$this->tenants->current();
        if($tenant===null)return $this->error('Tenant context required.',403);
        $organizationId=$tenant->organizationId()->value();
        if(!$this->modules->isEnabled($organizationId,'capital_markets'))return $this->error('Capital Markets module is disabled.',403);
        $actor=$tenant->userId()->value();
        if(!ctype_digit($actor))return $this->error('Canonical numeric actor required.',403);
        $actorId=(int)$actor;
        if(!$this->allowed($organizationId,$actorId,$capability))return $this->error('Capital Markets research capability required.',403);
        return [$tenant,$actorId];
    }

    private function allowed(string $organizationId,int $actorId,CapitalMarketsCapability $capability):bool
    {
        if($this->access->hasCapability($organizationId,$actorId,$capability->value))return true;
        $broad=in_array($capability,[CapitalMarketsCapability::ResearchView],true)
            ? CapitalMarketsCapability::View
            : CapitalMarketsCapability::Manage;
        return $this->access->hasCapability($organizationId,$actorId,$broad->value);
    }

    private function auditResult(
        Request $request,
        TenantContext $tenant,
        CapitalMarketsAuditAction $action,
        CapitalMarketsAuditResourceType $resourceType,
        string $resourceId,
        array $next,
        array $previous=[],
    ):void{
        $actor=$tenant->userId()->value();
        if(!ctype_digit($actor))return;
        $this->audit->record(
            $tenant->organizationId()->value(),
            (int)$actor,
            $action,
            $resourceType,
            $resourceId,
            $previous,
            $next,
            $this->correlation($request),
        );
    }

    private function correlation(Request $request):string
    {
        $value=trim((string)$request->headers->get('X-Correlation-Id',''));
        return $value!==''?substr($value,0,190):'CM-RESEARCH-API-'.strtoupper(bin2hex(random_bytes(6)));
    }

    private function payload(Request $request):array
    {
        if(str_contains(strtolower((string)$request->headers->get('Content-Type','')),'application/json')){
            try{$data=json_decode((string)$request->getContent(),true,flags:JSON_THROW_ON_ERROR);}
            catch(JsonException){throw new InvalidArgumentException('JSON body is malformed.');}
            if(!is_array($data)||array_is_list($data))throw new InvalidArgumentException('JSON body must be an object.');
            unset($data['csrf_token']);
            return $data;
        }
        $data=$request->request->all();
        unset($data['csrf_token']);
        return $data;
    }

    private function object(array $payload,string $key):array
    {
        $value=$payload[$key]??null;
        if(!is_array($value)||array_is_list($value))throw new InvalidArgumentException($key.' must be an object.');
        return $value;
    }

    private function required(array $payload,string $key):string
    {
        $value=trim((string)($payload[$key]??''));
        if($value==='')throw new InvalidArgumentException($key.' is required.');
        return $value;
    }

    private function respond(callable $reader):JsonResponse
    {
        try{return $this->ok($reader());}
        catch(InvalidArgumentException $error){return $this->error($error->getMessage(),422);}
        catch(Throwable $error){
            error_log('capital_markets.research_lab.api.read_failure '.$error->getMessage());
            return $this->error('Research Lab read failed.',500);
        }
    }

    private function ok(mixed $data,int $status=200):JsonResponse{return new JsonResponse(['ok'=>true,'data'=>$data],$status);}
    private function error(string $message,int $status):JsonResponse{return new JsonResponse(['ok'=>false,'error'=>$message],$status);}
}
