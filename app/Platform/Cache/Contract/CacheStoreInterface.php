<?php
declare(strict_types=1);

namespace Platform\Cache\Contract;

interface CacheStoreInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function put(string $key, mixed $value, ?int $ttlSeconds = null): void;

    public function delete(string $key): void;
}
