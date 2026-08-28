<?php
declare(strict_types=1);

namespace Domains\Identity\Application\Contract;

interface TelegramAccessPolicyInterface
{
    /** @return array{is_allow: bool, message: string} */
    public function check(int $userId, string $capability): array;

    public function getStatus(int $userId): string;
}
