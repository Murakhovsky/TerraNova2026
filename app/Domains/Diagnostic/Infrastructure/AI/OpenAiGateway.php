<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Infrastructure\AI;
use Domains\Diagnostic\AI\{AiGatewayInterface,AiOperationDefinition,AiRequest,AiResponse,PromptRegistry};
use Kernel\Agent\AgentDefinition;
use Kernel\Agent\Contract\LlmClientInterface;
final readonly class OpenAiGateway implements AiGatewayInterface
{
    public function __construct(private LlmClientInterface $client,private PromptRegistry $prompts=new PromptRegistry()){}
    public function execute(AiOperationDefinition $operation,AiRequest $request):AiResponse{$prompt=$this->prompts->get($operation->promptVersion);$agent=new AgentDefinition('diagnostic-'.$operation->operation->value,'1.0',(string)$prompt['system'],$operation->promptVersion,$operation->schemaVersion,[], 'APPROVAL_REQUIRED','MEDIUM',[$operation->schemaVersion=>$request->outputSchema]);$started=hrtime(true);$response=$this->client->structured($agent,(string)$prompt['task'],$request->context+['output_schema'=>$request->outputSchema]);return new AiResponse($response->output,$response->inputTokens??0,$response->outputTokens??0,$response->costAmount??0,(int)round((hrtime(true)-$started)/1_000_000),$response->model);}
}
