<?php
declare(strict_types=1);

namespace Domains\Content\Application\Service;

use Domains\Content\Application\Contract\ContentRepositoryInterface;
use Domains\Content\Application\Contract\ContentServiceInterface;

final readonly class ContentService implements ContentServiceInterface
{
    public function __construct(private ContentRepositoryInterface $content)
    {
    }

    public function publicPosts(int $page = 1, int $perPage = 12): array { return $this->content->publicPosts($page, $perPage); }
    public function publicPost(string $slug): ?array { return $this->content->publicPost($slug); }
    public function publicLanding(string $slug): ?array { return $this->content->publicLanding($slug); }
    public function relatedPosts(int $excludeId, int $limit = 3): array { return $this->content->relatedPosts($excludeId, $limit); }
    public function sitemapItems(): array { return $this->content->sitemapItems(); }
    public function adminItems(array $query = []): array { return $this->content->adminItems($query); }
    public function stats(): array { return $this->content->stats(); }
    public function integrationStats(): array { return $this->content->integrationStats(); }
    public function recentWebhookDeliveries(int $limit = 12): array { return $this->content->recentWebhookDeliveries($limit); }
    public function item(int $id): ?array { return $this->content->item($id); }
    public function revisions(int $id, int $limit = 12): array { return $this->content->revisions($id, $limit); }
    public function save(array $input, ?array $user = null, string $source = 'manual', bool $emitEvent = true): array
    {
        return $this->content->save($input, $user, $source, $emitEvent);
    }
    public function upsertFromWebhook(array $data): array { return $this->content->upsertFromWebhook($data); }
    public function publishScheduled(): int { return $this->content->publishScheduled(); }
}
