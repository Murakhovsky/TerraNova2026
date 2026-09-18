<?php
declare(strict_types=1);

namespace App\Application\Diagnostic\Command;

use App\Application\Diagnostic\DiagnosticMutationAudit;
use Domains\Diagnostic\Application\Contract\DiagnosticRuntimeRepositoryInterface;
use Domains\Diagnostic\Application\Service\DiagnosticRuntimeService;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class StartDiagnosticCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private DiagnosticRuntimeService $runtime,
        private DiagnosticRuntimeRepositoryInterface $repository,
        private DiagnosticMutationAudit $audit,

        private TransactionManagerInterface $transactions,
    ) {}

    public function __invoke(StartDiagnosticCommand $command): array
    {
        return $this->transactions->transactional(function () use ($command): array {
            $org=$command->organizationId->value();
            $session=substr(hash('sha256','diagnostic:start:'.$org.':'.$command->idempotencyKey),0,32);
            $requestHash=self::fingerprint($command->input);
            $existing=$this->repository->get($org,$session);
            if($existing!==null){
                $stored=(string)($existing['state']['idempotency_request_hash']??'');
                if($stored!=='' && !hash_equals($stored,$requestHash)){
                    throw new \DomainException('Diagnostic idempotency key was reused with a different creation payload.');
                }
                return $this->runtime->resume($org,$session)+['replayed'=>true];
            }
            $input=$command->input;
            $input['session_id']=$session;
            $input['idempotency_request_hash']=$requestHash;
            $result=$this->runtime->start($org,$input,(string)$command->actorId);
            $this->audit->record($org,$command->actorId,$command->correlationId,'diagnostic.created',$session,['pack_id'=>$input['pack_id']??null,'target_domain'=>$input['domain']??null]);
            return $result+['replayed'=>false];
        });
    }

    /** @param array<string,mixed> $value */
    private static function fingerprint(array $value): string
    {
        return hash('sha256',json_encode(self::normalize($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    private static function normalize(mixed $value): mixed
    {
        if(!is_array($value))return $value;
        if(array_is_list($value))return array_map(self::normalize(...),$value);
        ksort($value);
        foreach($value as $key=>$item)$value[$key]=self::normalize($item);
        return $value;
    }
}
