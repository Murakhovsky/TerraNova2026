<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Infrastructure\AI;
use Domains\Diagnostic\AI\{AiGatewayInterface,AiOperationDefinition,AiRequest,AiResponse};
use RuntimeException;

final class FakeAiGateway implements AiGatewayInterface
{
    public function __construct(private array $responses=[]){ }
    public function execute(AiOperationDefinition $operation,AiRequest $request):AiResponse
    {
        $response=array_shift($this->responses); if($response instanceof \Throwable) throw $response;
        if(!is_array($response)) throw new RuntimeException('No recorded AI response for '.$operation->operationId);
        return new AiResponse($response,100,50,0.001,5,'fake');
    }
}
