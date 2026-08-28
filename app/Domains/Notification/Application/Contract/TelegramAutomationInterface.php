<?php
declare(strict_types=1);

namespace Domains\Notification\Application\Contract;

interface TelegramAutomationInterface
{
    public function createUserLink(int $userId, int $ttlSeconds = 900): array;
    public function consumeLinkToken(string $token, array $telegram): array;
    public function bindingForUser(int $userId): ?array;
    public function bindingForTelegram(int $telegramUserId): ?array;
    public function disconnectUser(int $userId): bool;
    public function queue(
        string $eventType,
        string $audience,
        array $payload,
        ?int $userId = null,
        ?int $personId = null,
        ?int $chatId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $dedupeKey = null,
        ?string $availableAt = null
    ): bool;
    public function notifyNewInboundRequest(int $requestId): void;
    public function notifyPropertySubmission(int $submissionId): void;
    public function notifySubmissionStatus(int $submissionId, string $status, string $note = '', ?int $propertyId = null): void;
    public function notifyPropertyStatus(int $propertyId, string $status, string $note = ''): void;
    public function notifyPresentationShared(int $propertyId, ?int $userId, string $channel, string $variant): void;
    public function scheduleDueReminders(): int;
    public function queueDailyDigest(): int;
    public function managerSnapshot(int $telegramUserId): array;
    public function outboxStats(): array;
}
