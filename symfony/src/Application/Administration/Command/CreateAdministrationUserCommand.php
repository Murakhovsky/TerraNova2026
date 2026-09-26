<?php

declare(strict_types=1);

namespace App\Application\Administration\Command;

use Kernel\Application\Command\CommandInterface;

final readonly class CreateAdministrationUserCommand implements CommandInterface
{
    /** @param array<string,mixed> $input */
    public function __construct(public array $input)
    {
    }
}
