<?php
declare(strict_types=1);

namespace App\Application\Documents\Command;

use Kernel\Application\Command\CommandHandlerInterface;
use Platform\Documents\Service\DocumentsRuntimeService;

final readonly class CreateDocumentVersionCommandHandler implements CommandHandlerInterface
{
    public function __construct(private DocumentsRuntimeService $documents) {}
    public function __invoke(CreateDocumentVersionCommand $command):array
    {
        return $this->documents->createVersion(
            $command->organizationId->value(),$command->actorId,$command->correlationId,
            $command->documentId,$command->idempotencyKey,$command->input,
        );
    }
}
