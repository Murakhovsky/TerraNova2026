<?php
declare(strict_types=1);

namespace App\Application\Diagnostic\Command;

use App\Application\Diagnostic\DiagnosticMutationAudit;
use Domains\Diagnostic\Application\Service\DiagnosticRuntimeService;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class AnswerDiagnosticInterviewCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private DiagnosticRuntimeService $runtime,
        private DiagnosticMutationAudit $audit,

        private TransactionManagerInterface $transactions,
    ) {}

    public function __invoke(AnswerDiagnosticInterviewCommand $command): array
    {
        return $this->transactions->transactional(function () use ($command): array {
            $org=$command->organizationId->value();
            $result=$this->runtime->answer($org,$command->sessionId,$command->answer,(string)$command->actorId,$command->idempotencyKey);
            if(($result['replayed']??false)!==true){
                $this->audit->record($org,$command->actorId,$command->correlationId,'diagnostic.interview_answered',$command->sessionId);
            }
            return $result;
        });
    }
}
