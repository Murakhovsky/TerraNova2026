<?php
declare(strict_types=1);

namespace App\Infrastructure\Cache;

use Platform\Cache\Contract\CacheStoreInterface;
use Psr\Cache\CacheItemPoolInterface;

final readonly class SymfonyCacheStore implements CacheStoreInterface
{
    public function __construct(private CacheItemPoolInterface $pool)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $item = $this->pool->getItem($key);

        return $item->isHit() ? $item->get() : $default;
    }

    public function put(string $key, mixed $value, ?int $ttlSeconds = null): void
    {
        $item = $this->pool->getItem($key);
        $item->set($value);
        if ($ttlSeconds !== null) {
            $item->expiresAfter(max(1, $ttlSeconds));
        }

        $this->pool->save($item);
    }

    public function delete(string $key): void
    {
        $this->pool->deleteItem($key);
    }
}
