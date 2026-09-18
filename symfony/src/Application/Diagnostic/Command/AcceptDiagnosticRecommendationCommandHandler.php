<?php
declare(strict_types=1);

namespace App\Application\Diagnostic\Command;

use App\Application\Diagnostic\DiagnosticMutationAudit;
use Domains\Diagnostic\Application\Service\DiagnosticRuntimeService;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class AcceptDiagnosticRecommendationCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private DiagnosticRuntimeService $runtime,
        private DiagnosticMutationAudit $audit,

        private TransactionManagerInterface $transactions,
    ) {}

    public function __invoke(AcceptDiagnosticRecommendationCommand $command): array
    {
        return $this->transactions->transactional(function () use ($command): array {
            $org=$command->organizationId->value();
            $result=$this->runtime->accept($org,$command->sessionId,$command->recommendationId);
            if(($result['replayed']??false)!==true){
                $this->audit->record($org,$command->actorId,$command->correlationId,'diagnostic.recommendation_to_action',$command->sessionId,['recommendation_id'=>$command->recommendationId,'action_id'=>$result['action_id']??null]);
            }
            return $result;
        });
    }
}
