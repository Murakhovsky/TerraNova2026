<?php
declare(strict_types=1);

namespace App\Application\Diagnostic\Command;

use App\Application\Diagnostic\DiagnosticMutationAudit;
use Domains\Diagnostic\Application\Service\DiagnosticRuntimeService;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class CompleteDiagnosticCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private DiagnosticRuntimeService $runtime,
        private DiagnosticMutationAudit $audit,

        private TransactionManagerInterface $transactions,
    ) {}

    public function __invoke(CompleteDiagnosticCommand $command): array
    {
        return $this->transactions->transactional(function () use ($command): array {
            $org=$command->organizationId->value();
            $result=$this->runtime->complete($org,$command->sessionId,(string)$command->actorId);
            if(($result['replayed']??false)!==true){
                $this->audit->record($org,$command->actorId,$command->correlationId,'diagnostic.assessed',$command->sessionId,['report_version'=>$result['report_version']??null]);
            }
            return $result;
        });
    }
}
