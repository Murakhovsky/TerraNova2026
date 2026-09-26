<?php

declare(strict_types=1);

namespace App\Application\Property\Command;

use Domains\Property\Application\Contract\PropertySubmissionInterface;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class SubmitPublicPropertyCommandHandler implements CommandHandlerInterface
{
    public function __construct(private PropertySubmissionInterface $submissions)
    {
    }

    /** @return array{ok:bool,message:string} */
    public function __invoke(SubmitPublicPropertyCommand $command): array
    {
        $result = $this->submissions->submit(
            $command->input,
            $command->sourcePage,
            $command->files,
        );

        return [
            'ok' => (bool) ($result['ok'] ?? false),
            'message' => (string) ($result['message'] ?? 'Обʼєкт не вдалося зберегти.'),
        ];
    }
}
