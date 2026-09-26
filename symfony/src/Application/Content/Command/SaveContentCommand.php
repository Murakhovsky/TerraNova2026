<?php

declare(strict_types=1);

namespace App\Application\Content\Command;

use Kernel\Application\Command\CommandInterface;

final readonly class SaveContentCommand implements CommandInterface
{
    /**
     * @param array<string,mixed> $input
     * @param array{id:int,role:string,organization_id:string} $actor
     */
    public function __construct(
        public array $input,
        public array $actor,
    ) {
    }
}
