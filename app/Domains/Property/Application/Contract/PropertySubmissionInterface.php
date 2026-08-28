<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PropertySubmissionInterface
{
    public function submit(array $input, string $sourcePage, array $files = []): array;
    public function submissionForUser(int $id, array $user): ?array;
    public function updateForUser(int $id, array $user, array $input, array $files = []): array;
}
