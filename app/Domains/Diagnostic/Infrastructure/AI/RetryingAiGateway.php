<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Infrastructure\AI;
use Domains\Diagnostic\AI\{AiGatewayInterface,AiOperationDefinition,AiRequest,AiResponse};
final readonly class RetryingAiGateway implements AiGatewayInterface
{
    public function __construct(private AiGatewayInterface $inner){}
    public function execute(AiOperationDefinition $operation,AiRequest $request):AiResponse{$last=null;for($attempt=0;$attempt<=$operation->maxRetries;$attempt++){try{return $this->inner->execute($operation,$request);}catch(\Throwable $error){$last=$error;}}throw $last;}
}
