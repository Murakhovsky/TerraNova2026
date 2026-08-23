<?php
declare(strict_types=1);

namespace Modules\Frontend\Services;

use Common\Services\DatabaseService;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use PDO;
use RuntimeException;
use Throwable;

class ContentService
{
    private const TYPES = ['blog_post', 'seo_landing'];
    private const STATUSES = ['draft', 'review', 'published', 'archived'];
    private const ROBOTS = ['index,follow', 'noindex,follow', 'noindex,nofollow'];

    public function __construct(private DatabaseService $database)
    {
    }

    public function publicPosts(int $page = 1, int $perPage = 12): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(30, $perPage));
        $total = (int) ($this->database->fetchOne('
            SELECT COUNT(*) AS total FROM tn_content_items
            WHERE content_type = "blog_post" AND status = "published"
              AND published_at IS NOT NULL AND published_at <= NOW()
        ')['total'] ?? 0);
        $offset = ($page - 1) * $perPage;
        $items = $this->database->fetchAll('
            SELECT id, title, slug, category, excerpt, featured_image_url, featured_image_alt,
                   meta_title, meta_description, published_at, updated_at
            FROM tn_content_items
            WHERE content_type = "blog_post" AND status = "published"
              AND published_at IS NOT NULL AND published_at <= NOW()
            ORDER BY published_at DESC, id DESC
            LIMIT ' . $perPage . ' OFFSET ' . $offset . '
        ');

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public function publicPost(string $slug): ?array
    {
        return $this->publicItem('blog_post', $slug);
    }

    public function publicLanding(string $slug): ?array
    {
        return $this->publicItem('seo_landing', $slug);
    }

    public function relatedPosts(int $excludeId, int $limit = 3): array
    {
        $limit = max(1, min(6, $limit));
        return $this->database->fetchAll('
            SELECT id, title, slug, category, excerpt, featured_image_url, featured_image_alt, published_at
            FROM tn_content_items
            WHERE content_type = "blog_post" AND status = "published" AND id <> :id
              AND published_at IS NOT NULL AND published_at <= NOW()
            ORDER BY published_at DESC, id DESC
            LIMIT ' . $limit . '
        ', ['id' => $excludeId]);
    }

    public function sitemapItems(): array
    {
        return $this->database->fetchAll('
            SELECT id, content_type, slug, updated_at
            FROM tn_content_items
            WHERE status = "published" AND published_at IS NOT NULL AND published_at <= NOW()
              AND robots = "index,follow"
            ORDER BY updated_at DESC
        ');
    }

    public function adminItems(array $query = []): array
    {
        $type = in_array((string) ($query['type'] ?? ''), self::TYPES, true) ? (string) $query['type'] : '';
        $status = in_array((string) ($query['status'] ?? ''), self::STATUSES, true) ? (string) $query['status'] : '';
        $search = trim((string) ($query['q'] ?? ''));
        $where = [];
        $params = [];
        if ($type !== '') {
            $where[] = 'c.content_type = :type';
            $params['type'] = $type;
        }
        if ($status !== '') {
            $where[] = 'c.status = :status';
            $params['status'] = $status;
        }
        if ($search !== '') {
            $where[] = '(c.title LIKE :search OR c.slug LIKE :search OR c.focus_keyword LIKE :search)';
            $params['search'] = '%' . mb_substr($search, 0, 120) . '%';
        }

        $items = $this->database->fetchAll('
            SELECT c.*, u.full_name AS author_name
            FROM tn_content_items c
            LEFT JOIN tn_users u ON u.id = c.author_user_id
            ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . '
            ORDER BY FIELD(c.status, "review", "draft", "published", "archived"), c.updated_at DESC
            LIMIT 200
        ', $params);
        foreach ($items as &$item) {
            $item['seo_score'] = $this->seoScore($item);
        }
        unset($item);
        return $items;
    }

    public function stats(): array
    {
        $rows = $this->database->fetchAll('
            SELECT content_type, status, COUNT(*) AS total
            FROM tn_content_items GROUP BY content_type, status
        ');
        $stats = [];
        foreach (self::TYPES as $type) {
            $stats[$type] = array_fill_keys(self::STATUSES, 0);
        }
        foreach ($rows as $row) {
            $stats[(string) $row['content_type']][(string) $row['status']] = (int) $row['total'];
        }
        return $stats;
    }

    public function integrationStats(): array
    {
        $rows = $this->database->fetchAll('
            SELECT status, COUNT(*) AS total
            FROM tn_integration_outbox WHERE integration = "n8n" GROUP BY status
        ');
        $stats = ['pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($rows as $row) {
            $stats[(string) $row['status']] = (int) $row['total'];
        }
        return $stats;
    }

    public function recentWebhookDeliveries(int $limit = 12): array
    {
        $limit = max(1, min(50, $limit));
        return $this->database->fetchAll('
            SELECT id, direction, event_type, idempotency_key, status, error_message, processed_at, created_at
            FROM tn_webhook_deliveries WHERE integration = "n8n"
            ORDER BY id DESC LIMIT ' . $limit . '
        ');
    }

    public function item(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $item = $this->database->fetchOne('SELECT * FROM tn_content_items WHERE id = :id LIMIT 1', ['id' => $id]);
        if ($item) {
            $item['tags'] = implode(', ', (array) json_decode((string) ($item['tags_json'] ?? '[]'), true));
            $item['seo_score'] = $this->seoScore($item);
        }
        return $item;
    }

    public function revisions(int $id, int $limit = 12): array
    {
        $limit = max(1, min(50, $limit));
        return $this->database->fetchAll('
            SELECT r.id, r.source, r.created_at, u.full_name AS user_name
            FROM tn_content_revisions r
            LEFT JOIN tn_users u ON u.id = r.user_id
            WHERE r.content_item_id = :id
            ORDER BY r.id DESC LIMIT ' . $limit . '
        ', ['id' => $id]);
    }

    public function save(array $input, ?array $user = null, string $source = 'manual', bool $emitEvent = true): array
    {
        $id = (int) ($input['id'] ?? 0);
        $type = in_array((string) ($input['content_type'] ?? ''), self::TYPES, true)
            ? (string) $input['content_type']
            : 'blog_post';
        $status = in_array((string) ($input['status'] ?? ''), self::STATUSES, true)
            ? (string) $input['status']
            : 'draft';
        $title = $this->limit((string) ($input['title'] ?? ''), 240);
        $body = $this->sanitizeHtml((string) ($input['body_html'] ?? ''));
        if ($title === '' || trim(strip_tags($body)) === '') {
            return ['ok' => false, 'message' => 'Вкажіть назву та зміст матеріалу.', 'id' => $id];
        }

        $pdo = $this->database->connection();
        try {
            $pdo->beginTransaction();
            $current = $id > 0 ? $this->itemForUpdate($pdo, $id) : null;
            if ($id > 0 && !$current) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'Матеріал не знайдено.', 'id' => $id];
            }
            if ($current) {
                $this->insertRevision($pdo, $current, !empty($user['id']) ? (int) $user['id'] : null, $source);
            }

            $databaseNow = (string) $pdo->query('SELECT NOW()')->fetchColumn();
            $slug = $this->uniqueSlug($pdo, $type, (string) ($input['slug'] ?? $title), $id);
            $publishedAt = $this->dateTimeOrNull($input['published_at'] ?? null);
            if ($status === 'published' && !$publishedAt) {
                $publishedAt = $current['published_at'] ?? $databaseNow;
            }
            $schemaJson = $this->jsonObjectOrNull($input['schema_json'] ?? null);
            $tagsJson = json_encode($this->tags($input['tags'] ?? $input['tags_json'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $values = [
                'external_id' => $this->nullable((string) ($input['external_id'] ?? ($current['external_id'] ?? '')), 120),
                'content_type' => $type,
                'source' => in_array($source, ['manual', 'n8n', 'aida'], true) ? $source : 'manual',
                'status' => $status,
                'title' => $title,
                'slug' => $slug,
                'category' => $this->nullable((string) ($input['category'] ?? ''), 120),
                'excerpt' => $this->nullable((string) ($input['excerpt'] ?? ''), 2000),
                'body_html' => $body,
                'featured_image_url' => $this->safeUrlOrNull((string) ($input['featured_image_url'] ?? ''), 700),
                'featured_image_alt' => $this->nullable((string) ($input['featured_image_alt'] ?? ''), 240),
                'meta_title' => $this->nullable((string) ($input['meta_title'] ?? ''), 240),
                'meta_description' => $this->nullable((string) ($input['meta_description'] ?? ''), 500),
                'focus_keyword' => $this->nullable((string) ($input['focus_keyword'] ?? ''), 190),
                'canonical_url' => $this->safeUrlOrNull((string) ($input['canonical_url'] ?? ''), 700),
                'og_image_url' => $this->safeUrlOrNull((string) ($input['og_image_url'] ?? ''), 700),
                'robots' => in_array((string) ($input['robots'] ?? ''), self::ROBOTS, true) ? (string) $input['robots'] : 'index,follow',
                'schema_json' => $schemaJson,
                'tags_json' => $tagsJson,
                'author_user_id' => !empty($user['id']) ? (int) $user['id'] : ($current['author_user_id'] ?? null),
                'published_at' => $publishedAt,
                'scheduled_at' => $this->dateTimeOrNull($input['scheduled_at'] ?? null),
                'synced_at' => $source === 'n8n' ? $databaseNow : ($current['synced_at'] ?? null),
            ];

            if ($current) {
                $assignments = [];
                foreach (array_keys($values) as $field) {
                    $assignments[] = $field . ' = :' . $field;
                }
                $values['id'] = $id;
                $pdo->prepare('UPDATE tn_content_items SET ' . implode(', ', $assignments) . ' WHERE id = :id LIMIT 1')->execute($values);
            } else {
                $fields = array_keys($values);
                $pdo->prepare('INSERT INTO tn_content_items (' . implode(', ', $fields) . ') VALUES (:' . implode(', :', $fields) . ')')->execute($values);
                $id = (int) $pdo->lastInsertId();
            }

            if ($emitEvent) {
                $this->queueOutbound($pdo, 'content.changed', $id, [
                    'id' => $id,
                    'external_id' => $values['external_id'],
                    'content_type' => $type,
                    'status' => $status,
                    'title' => $title,
                    'slug' => $slug,
                    'updated_at' => date(DATE_ATOM),
                ]);
            }
            $pdo->commit();

            return ['ok' => true, 'message' => 'Матеріал збережено.', 'id' => $id, 'slug' => $slug];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->logError('content-save', $e);
            return ['ok' => false, 'message' => 'Не вдалося зберегти матеріал. Деталі записано в лог.', 'id' => $id];
        }
    }

    public function upsertFromWebhook(array $data): array
    {
        $externalId = $this->limit((string) ($data['external_id'] ?? ''), 120);
        if ($externalId === '') {
            return ['ok' => false, 'message' => 'data.external_id is required.', 'id' => 0];
        }
        $existing = $this->database->fetchOne('SELECT * FROM tn_content_items WHERE external_id = :external_id LIMIT 1', ['external_id' => $externalId]);
        if ($existing) {
            $existing['tags'] = $existing['tags_json'] ?? '[]';
            $data = array_merge($existing, $data);
        }
        $data['id'] = (int) ($existing['id'] ?? 0);
        $data['external_id'] = $externalId;
        return $this->save($data, null, 'n8n', false);
    }

    public function publishScheduled(): int
    {
        $items = $this->database->fetchAll('
            SELECT * FROM tn_content_items
            WHERE status IN ("draft", "review") AND scheduled_at IS NOT NULL AND scheduled_at <= NOW()
            ORDER BY scheduled_at LIMIT 100
        ');
        $published = 0;
        foreach ($items as $item) {
            $item['status'] = 'published';
            $item['published_at'] = $item['scheduled_at'];
            $item['tags'] = $item['tags_json'] ?? '[]';
            $result = $this->save($item, null, 'manual', true);
            $published += (int) !empty($result['ok']);
        }
        return $published;
    }

    private function publicItem(string $type, string $slug): ?array
    {
        $item = $this->database->fetchOne('
            SELECT c.*, u.full_name AS author_name
            FROM tn_content_items c
            LEFT JOIN tn_users u ON u.id = c.author_user_id
            WHERE c.content_type = :type AND c.slug = :slug AND c.status = "published"
              AND c.published_at IS NOT NULL AND c.published_at <= NOW()
            LIMIT 1
        ', ['type' => $type, 'slug' => $slug]);
        if ($item) {
            $item['tags'] = (array) json_decode((string) ($item['tags_json'] ?? '[]'), true);
        }
        return $item;
    }

    private function itemForUpdate(PDO $pdo, int $id): ?array
    {
        $statement = $pdo->prepare('SELECT * FROM tn_content_items WHERE id = :id LIMIT 1 FOR UPDATE');
        $statement->execute(['id' => $id]);
        $item = $statement->fetch(PDO::FETCH_ASSOC);
        return $item ?: null;
    }

    private function insertRevision(PDO $pdo, array $item, ?int $userId, string $source): void
    {
        $pdo->prepare('
            INSERT INTO tn_content_revisions (content_item_id, user_id, source, snapshot_json)
            VALUES (:content_item_id, :user_id, :source, :snapshot)
        ')->execute([
            'content_item_id' => $item['id'],
            'user_id' => $userId,
            'source' => in_array($source, ['manual', 'n8n', 'aida'], true) ? $source : 'system',
            'snapshot' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }

    private function queueOutbound(PDO $pdo, string $eventType, int $id, array $payload): void
    {
        $pdo->prepare('
            INSERT IGNORE INTO tn_integration_outbox (
                integration, event_type, entity_type, entity_id, payload, dedupe_key
            ) VALUES ("n8n", :event_type, "content", :entity_id, :payload, :dedupe_key)
        ')->execute([
            'event_type' => $eventType,
            'entity_id' => $id,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'dedupe_key' => 'n8n:content:' . $id . ':' . hash('sha256', json_encode($payload)),
        ]);
    }

    private function uniqueSlug(PDO $pdo, string $type, string $value, int $ignoreId): string
    {
        $base = $this->slug($value) ?: 'material';
        $slug = $base;
        $suffix = 2;
        $statement = $pdo->prepare('
            SELECT id FROM tn_content_items
            WHERE content_type = :type AND slug = :slug AND id <> :ignore_id LIMIT 1
        ');
        while (true) {
            $statement->execute(['type' => $type, 'slug' => $slug, 'ignore_id' => $ignoreId]);
            if (!$statement->fetchColumn()) {
                return $slug;
            }
            $slug = $base . '-' . $suffix++;
        }
    }

    private function sanitizeHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="tn-content-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($doc);
        foreach (['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select'] as $tag) {
            foreach (iterator_to_array($xpath->query('//' . $tag) ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        $allowed = ['div', 'p', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'strong', 'em', 'a', 'blockquote', 'figure', 'img', 'figcaption', 'br', 'hr', 'span'];
        foreach (iterator_to_array($xpath->query('//*') ?: []) as $node) {
            if (!$node instanceof DOMElement || $node->getAttribute('id') === 'tn-content-root') {
                continue;
            }
            if (!in_array(strtolower($node->tagName), $allowed, true)) {
                $this->unwrapNode($node);
                continue;
            }
            foreach (iterator_to_array($node->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                $keep = ($node->tagName === 'a' && in_array($name, ['href', 'target', 'rel'], true))
                    || ($node->tagName === 'img' && in_array($name, ['src', 'alt', 'width', 'height', 'loading'], true));
                if (!$keep) {
                    $node->removeAttribute($attribute->name);
                }
            }
            if ($node->tagName === 'a') {
                $href = $node->getAttribute('href');
                if (!$this->safeLink($href)) {
                    $node->removeAttribute('href');
                }
                if ($node->getAttribute('target') === '_blank') {
                    $node->setAttribute('rel', 'noopener noreferrer');
                } else {
                    $node->removeAttribute('target');
                    $node->removeAttribute('rel');
                }
            }
            if ($node->tagName === 'img') {
                if (!$this->safeLink($node->getAttribute('src'))) {
                    $node->parentNode?->removeChild($node);
                } else {
                    $node->setAttribute('loading', 'lazy');
                }
            }
        }
        $root = $doc->getElementById('tn-content-root');
        if (!$root) {
            return '';
        }
        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $doc->saveHTML($child);
        }
        return trim($result);
    }

    private function unwrapNode(DOMNode $node): void
    {
        $parent = $node->parentNode;
        if (!$parent) {
            return;
        }
        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }
        $parent->removeChild($node);
    }

    private function safeLink(string $url): bool
    {
        $url = trim($url);
        return $url !== '' && (str_starts_with($url, '/') || preg_match('~^https?://~i', $url) === 1);
    }

    private function safeUrlOrNull(string $value, int $limit): ?string
    {
        $value = trim($value);
        return $value !== '' && $this->safeLink($value) ? mb_substr($value, 0, $limit) : null;
    }

    private function seoScore(array $item): int
    {
        $checks = [
            trim((string) ($item['meta_title'] ?? '')) !== '',
            mb_strlen((string) ($item['meta_description'] ?? '')) >= 80,
            trim((string) ($item['focus_keyword'] ?? '')) !== '',
            trim((string) ($item['featured_image_url'] ?? '')) !== '',
            trim((string) ($item['featured_image_alt'] ?? '')) !== '',
            mb_strlen(strip_tags((string) ($item['body_html'] ?? ''))) >= 500,
        ];
        return (int) round((count(array_filter($checks)) / count($checks)) * 100);
    }

    private function jsonObjectOrNull(mixed $value): ?string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                throw new RuntimeException('Schema JSON must be an object.');
            }
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('Schema JSON must be an object.');
        }
        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function tags(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = str_starts_with(trim($value), '[') ? json_decode($value, true) : null;
            $value = is_array($decoded) ? $decoded : preg_split('/[,;]+/', $value);
        }
        $tags = [];
        foreach ((array) $value as $tag) {
            $tag = $this->limit((string) $tag, 60);
            if ($tag !== '') {
                $tags[mb_strtolower($tag)] = $tag;
            }
        }
        return array_values(array_slice($tags, 0, 20));
    }

    private function dateTimeOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    private function slug(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'h','ґ'=>'g','д'=>'d','е'=>'e','є'=>'ye','ж'=>'zh','з'=>'z','и'=>'y','і'=>'i','ї'=>'yi','й'=>'y',
            'к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch',
            'ш'=>'sh','щ'=>'shch','ь'=>'','ю'=>'yu','я'=>'ya',
        ]);
        $value = preg_replace('~[^a-z0-9]+~', '-', $value) ?: '';
        return trim($value, '-');
    }

    private function limit(string $value, int $limit): string
    {
        return mb_substr(trim($value), 0, $limit);
    }

    private function nullable(string $value, int $limit): ?string
    {
        $value = $this->limit($value, $limit);
        return $value === '' ? null : $value;
    }

    private function logError(string $label, Throwable $error): void
    {
        $directory = BASE_PATH . '/tmp/logs';
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        @file_put_contents($directory . '/content.log', sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), $label, $error->getMessage()), FILE_APPEND);
    }
}
