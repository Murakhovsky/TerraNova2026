<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PropertySubmissionRepositoryInterface
{
    public function create(array $submission): int;

    public function findForOwner(int $id, string $email): ?array;

    public function update(int $id, array $submission): void;

}
