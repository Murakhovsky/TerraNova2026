<?php
declare(strict_types=1);

namespace App\Application\Documents\Command;

use Kernel\Application\Command\CommandHandlerInterface;
use Platform\Documents\Contract\DocumentAttachmentPort;

final readonly class AttachDocumentCommandHandler implements CommandHandlerInterface
{
    public function __construct(private DocumentAttachmentPort $documents) {}
    public function __invoke(AttachDocumentCommand $command):array
    {
        return $this->documents->attachExistingDocument(
            $command->organizationId->value(),$command->actorId,$command->correlationId,
            $command->documentId,$command->relatedType,$command->relatedId,$command->idempotencyKey,
        );
    }
}
