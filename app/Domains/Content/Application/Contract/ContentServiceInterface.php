<?php
declare(strict_types=1);

namespace Domains\Content\Application\Contract;

interface ContentServiceInterface
{
    public function publicPosts(int $page = 1, int $perPage = 12): array;
    public function publicPost(string $slug): ?array;
    public function publicLanding(string $slug): ?array;
    public function relatedPosts(int $excludeId, int $limit = 3): array;
    public function sitemapItems(): array;
    public function adminItems(array $query = []): array;
    public function stats(): array;
    public function integrationStats(): array;
    public function recentWebhookDeliveries(int $limit = 12): array;
    public function item(int $id): ?array;
    public function revisions(int $id, int $limit = 12): array;
    public function save(array $input, ?array $user = null, string $source = 'manual', bool $emitEvent = true): array;
    public function upsertFromWebhook(array $data): array;
    public function publishScheduled(): int;
}
