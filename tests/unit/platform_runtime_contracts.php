<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Infrastructure\Storage\LocalFileStorage;
use Kernel\Shared\Domain\OrganizationId;
use Platform\Cache\Contract\CacheStoreInterface;
use Platform\Search\Contract\SearchEngineInterface;
use Platform\Search\Model\SearchHit;
use Platform\Search\Model\SearchQuery;
use Platform\Search\Model\SearchResult;
use Platform\Storage\Contract\FileStorageInterface;

function expectPlatformRuntime(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

expectPlatformRuntime(interface_exists(CacheStoreInterface::class), 'Platform cache contract must autoload.');
expectPlatformRuntime(interface_exists(SearchEngineInterface::class), 'Platform search contract must autoload.');
expectPlatformRuntime(interface_exists(FileStorageInterface::class), 'Platform storage contract must autoload.');

$query = new SearchQuery(
    OrganizationId::fromString('org-search'),
    'forest apartment',
    ['real_estate'],
    ['status' => 'active'],
    25,
);
$result = new SearchResult([
    new SearchHit('property-1', 'property', 0.95, 'Forest apartment', ['status' => 'active']),
], 1);
expectPlatformRuntime($query->organizationId->value() === 'org-search', 'Search must be tenant-explicit.');
expectPlatformRuntime($result->hits[0]->type === 'property', 'Search result must preserve typed hits.');

$root = sys_get_temp_dir() . '/cos-storage-' . bin2hex(random_bytes(6));
$storage = new LocalFileStorage($root);
$stored = $storage->put('documents/org-search/test.txt', 'hello COS', 'text/plain', ['owner' => 'org-search']);
expectPlatformRuntime($storage->exists($stored->key), 'Local storage must persist a file.');
expectPlatformRuntime($storage->read($stored->key) === 'hello COS', 'Local storage must read exact contents.');
expectPlatformRuntime($stored->sha256 === hash('sha256', 'hello COS'), 'Stored file checksum must be deterministic.');
$storage->delete($stored->key);
expectPlatformRuntime(!$storage->exists($stored->key), 'Local storage delete must be idempotent.');

try {
    $storage->put('../escape.txt', 'nope');
    throw new RuntimeException('Traversal storage key must be rejected.');
} catch (RuntimeException $error) {
    expectPlatformRuntime(str_contains($error->getMessage(), 'traversal'), 'Traversal must fail for the expected reason.');
}

echo "Platform cache/search/storage contracts passed.\n";
