<?php

declare(strict_types=1);

namespace App\Application\Property\Command;

use Domains\Property\Application\Contract\PropertyCatalogInterface;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class RecordPublicPropertyViewCommandHandler implements CommandHandlerInterface
{
    public function __construct(private PropertyCatalogInterface $catalog)
    {
    }

    public function __invoke(RecordPublicPropertyViewCommand $command): null
    {
        $this->catalog->recordPropertyView($command->propertyId, $command->context);

        return null;
    }
}
