<?php
declare(strict_types=1);

namespace App\Application\Documents\Command;

use Kernel\Application\Command\CommandHandlerInterface;
use Platform\Documents\Service\DocumentsRuntimeService;

final readonly class SignDocumentCommandHandler implements CommandHandlerInterface
{
    public function __construct(private DocumentsRuntimeService $documents) {}
    public function __invoke(SignDocumentCommand $command):array
    {
        return $this->documents->sign(
            $command->organizationId->value(),$command->actorId,$command->correlationId,
            $command->signatureId,$command->signedBy,$command->signatureReference,$command->idempotencyKey,
        );
    }
}
