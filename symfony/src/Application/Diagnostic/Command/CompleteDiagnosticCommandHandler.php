<?php
declare(strict_types=1);
namespace App\Application\Diagnostic\Command;
use App\Application\Diagnostic\DiagnosticMutationAudit;
use Domains\Diagnostic\Application\Service\DiagnosticRuntimeService;
use Kernel\Application\Command\CommandHandlerInterface;
final readonly class CompleteDiagnosticCommandHandler implements CommandHandlerInterface
{
    public function __construct(private DiagnosticRuntimeService $runtime,private DiagnosticMutationAudit $audit){}
    public function __invoke(CompleteDiagnosticCommand $command):array
    {
        $org=$command->organizationId->value(); $result=$this->runtime->complete($org,$command->sessionId,(string)$command->actorId);
        $this->audit->record($org,$command->actorId,$command->correlationId,'diagnostic.assessed',$command->sessionId,['report_version'=>$result['report_version']??null]); return $result;
    }
}
