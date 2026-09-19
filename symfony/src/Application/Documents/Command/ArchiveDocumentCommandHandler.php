<?php
declare(strict_types=1);

namespace App\Application\Documents\Command;

use Kernel\Application\Command\CommandHandlerInterface;
use Platform\Documents\Service\DocumentsRuntimeService;

final readonly class ArchiveDocumentCommandHandler implements CommandHandlerInterface
{
    public function __construct(private DocumentsRuntimeService $documents) {}
    public function __invoke(ArchiveDocumentCommand $command):array
    {
        return $this->documents->archive(
            $command->organizationId->value(),$command->actorId,$command->correlationId,
            $command->documentId,$command->idempotencyKey,
        );
    }
}
