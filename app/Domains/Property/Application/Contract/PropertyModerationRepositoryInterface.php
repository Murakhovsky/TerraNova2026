<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PropertyModerationRepositoryInterface
{
    public function submissions(string $status = ''): array;

    public function submission(int $id): ?array;

    public function counts(): array;

    public function submissionMedia(int $id): array;

    public function setStatus(int $id, string $status, string $note): array;

    public function publish(int $id, string $note): array;
}
