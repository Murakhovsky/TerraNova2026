<?php

declare(strict_types=1);

namespace App\Application\Administration\Command;

use Kernel\Application\Command\CommandInterface;

final readonly class UpdateAdministrationUserCommand implements CommandInterface
{
    /**
     * @param array<string,mixed> $input
     * @param array{id:int,role:string} $actor
     */
    public function __construct(
        public int $userId,
        public array $input,
        public array $actor,
    ) {
    }
}
