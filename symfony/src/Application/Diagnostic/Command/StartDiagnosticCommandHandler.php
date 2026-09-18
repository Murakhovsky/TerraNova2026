<?php
declare(strict_types=1);
namespace App\Application\Diagnostic\Command;
use App\Application\Diagnostic\DiagnosticMutationAudit;
use Domains\Diagnostic\Application\Contract\DiagnosticRuntimeRepositoryInterface;
use Domains\Diagnostic\Application\Service\DiagnosticRuntimeService;
use Kernel\Application\Command\CommandHandlerInterface;
final readonly class StartDiagnosticCommandHandler implements CommandHandlerInterface
{
    public function __construct(private DiagnosticRuntimeService $runtime,private DiagnosticRuntimeRepositoryInterface $repository,private DiagnosticMutationAudit $audit){}
    public function __invoke(StartDiagnosticCommand $command):array
    {
        $org=$command->organizationId->value(); $session=substr(hash('sha256','diagnostic:start:'.$org.':'.$command->idempotencyKey),0,32);
        if($this->repository->get($org,$session)!==null) return $this->runtime->resume($org,$session)+['replayed'=>true];
        $input=$command->input; $input['session_id']=$session; $result=$this->runtime->start($org,$input,(string)$command->actorId);
        $this->audit->record($org,$command->actorId,$command->correlationId,'diagnostic.created',$session,['pack_id'=>$input['pack_id']??null,'target_domain'=>$input['domain']??null]);
        return $result+['replayed'=>false];
    }
}
