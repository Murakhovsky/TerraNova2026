<?php
declare(strict_types=1);

namespace App\Application\Documents\Command;

use Kernel\Application\Command\CommandHandlerInterface;
use Platform\Documents\Service\DocumentsRuntimeService;

final readonly class GenerateDocumentFromTemplateCommandHandler implements CommandHandlerInterface
{
    public function __construct(private DocumentsRuntimeService $documents) {}
    public function __invoke(GenerateDocumentFromTemplateCommand $command):array
    {
        return $this->documents->generateFromTemplate(
            $command->organizationId->value(),$command->actorId,$command->correlationId,
            $command->templateId,$command->idempotencyKey,$command->input,
        );
    }
}
