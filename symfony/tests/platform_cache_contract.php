<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Infrastructure\Cache\SymfonyCacheStore;
use Platform\Cache\Contract\CacheStoreInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

function expectCache(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$store = new SymfonyCacheStore(new ArrayAdapter());
expectCache($store instanceof CacheStoreInterface, 'Symfony cache adapter must implement Platform cache contract.');
expectCache($store->get('missing', 'fallback') === 'fallback', 'Cache default value must be returned for misses.');
$store->put('tenant.default.permissions', ['manage'], 60);
expectCache($store->get('tenant.default.permissions') === ['manage'], 'Cache must preserve structured values.');
$store->delete('tenant.default.permissions');
expectCache($store->get('tenant.default.permissions') === null, 'Cache delete must evict the item.');

echo "Symfony cache adapter contract passed.\n";
