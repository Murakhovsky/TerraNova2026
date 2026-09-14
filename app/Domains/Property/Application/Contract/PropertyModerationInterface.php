<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PropertyModerationInterface
{
    public function submissions(string $status = ''): array;
    public function submission(int $id): ?array;
    public function counts(): array;
    public function submissionMedia(int $id): array;
    public function moderate(int $id, string $action, string $note = ''): array;
}
