<?php
declare(strict_types=1);

namespace Domains\Identity\Application\Contract;

/**
 * Identity-owned port for linking an application account to a Telegram identity.
 * Message delivery and notification scheduling remain Infrastructure concerns.
 */
interface TelegramAccountLinkInterface
{
    public function createUserLink(int $userId, int $ttlSeconds = 900): array;

    public function consumeLinkToken(string $token, array $telegram): array;

    public function bindingForUser(int $userId): ?array;

    public function bindingForTelegram(int $telegramUserId): ?array;

    public function disconnectUser(int $userId): bool;
}
