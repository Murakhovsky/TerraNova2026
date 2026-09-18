<?php
declare(strict_types=1);

namespace App\Application\Diagnostic\Command;

use App\Application\Diagnostic\DiagnosticMutationAudit;
use Domains\Diagnostic\Application\Service\DiagnosticEvidencePipeline;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class CaptureDiagnosticEvidenceCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private DiagnosticEvidencePipeline $pipeline,
        private DiagnosticMutationAudit $audit,

        private TransactionManagerInterface $transactions,
    ) {}

    public function __invoke(CaptureDiagnosticEvidenceCommand $command): array
    {
        return $this->transactions->transactional(function () use ($command): array {
            $org=$command->organizationId->value();
            $result=$this->pipeline->ingest($org,$command->sessionId,$command->input,(string)$command->actorId,$command->idempotencyKey);
            if(($result['replayed']??false)!==true){
                $this->audit->record($org,$command->actorId,$command->correlationId,'diagnostic.evidence_captured',$command->sessionId,['evidence_id'=>$result['evidence_id']??null]);
            }
            return $result;
        });
    }
}
