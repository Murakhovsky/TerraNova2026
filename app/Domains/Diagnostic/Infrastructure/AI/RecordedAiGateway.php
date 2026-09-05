<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Infrastructure\AI;
use Domains\Diagnostic\AI\{AiGatewayInterface,AiOperationDefinition,AiRequest,AiResponse};

final class RecordedAiGateway implements AiGatewayInterface
{
    public array $calls=[];
    public function __construct(private AiGatewayInterface $inner){}
    public function execute(AiOperationDefinition $operation,AiRequest $request):AiResponse { $started=microtime(true); try{$response=$this->inner->execute($operation,$request);$this->calls[]=$this->audit($operation,$request,$response,'SUCCESS',(int)((microtime(true)-$started)*1000));return $response;}catch(\Throwable $e){$this->calls[]=$this->audit($operation,$request,null,'FAILED',(int)((microtime(true)-$started)*1000));throw $e;} }
    private function audit(AiOperationDefinition $op,AiRequest $request,?AiResponse $response,string $status,int $duration):array{return ['organization_id'=>$request->organizationId,'diagnostic_id'=>$request->diagnosticId,'operation'=>$op->operation->value,'model'=>$response?->model??$op->model,'prompt_version'=>$op->promptVersion,'schema_version'=>$op->schemaVersion,'input_hash'=>hash('sha256',json_encode($request->context,JSON_THROW_ON_ERROR)),'output_hash'=>$response?hash('sha256',json_encode($response->output,JSON_THROW_ON_ERROR)):null,'tokens_input'=>$response?->tokensInput??0,'tokens_output'=>$response?->tokensOutput??0,'estimated_cost'=>$response?->estimatedCost??0,'duration_ms'=>$duration,'status'=>$status,'timestamp'=>(new \DateTimeImmutable())->format(DATE_ATOM)];}
}
