<?php

declare(strict_types=1);

namespace App\Application\Content\Command;

use Domains\Content\Application\Contract\ContentServiceInterface;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class SaveContentCommandHandler implements CommandHandlerInterface
{
    public function __construct(private ContentServiceInterface $content)
    {
    }

    /** @return array<string,mixed> */
    public function __invoke(SaveContentCommand $command): array
    {
        return $this->content->save($command->input, $command->actor);
    }
}
