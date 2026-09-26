<?php

declare(strict_types=1);

namespace App\Application\Administration\Command;

use Domains\Identity\Application\Contract\AdministrationServiceInterface;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class CreateAdministrationUserCommandHandler implements CommandHandlerInterface
{
    public function __construct(private AdministrationServiceInterface $administration)
    {
    }

    /** @return array<string,mixed> */
    public function __invoke(CreateAdministrationUserCommand $command): array
    {
        return $this->administration->createUser($command->input);
    }
}
