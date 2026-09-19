<?php
declare(strict_types=1);

namespace App\Application\Documents\Command;

use Kernel\Application\Command\CommandHandlerInterface;
use Platform\Documents\Service\DocumentsRuntimeService;

final readonly class UploadDocumentCommandHandler implements CommandHandlerInterface
{
    public function __construct(private DocumentsRuntimeService $documents) {}
    public function __invoke(UploadDocumentCommand $command):array
    {
        return $this->documents->upload(
            $command->organizationId->value(),$command->actorId,$command->correlationId,
            $command->idempotencyKey,$command->input,
        );
    }
}
