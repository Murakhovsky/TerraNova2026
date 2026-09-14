<?php
declare(strict_types=1);

namespace Domains\Spatial\Application\Contract;

interface SpatialAssetStorageInterface
{
    public function store(array $scene, array $file, array $input, array $user): array;
    public function external(array $scene, array $input): array;
    public function asset(int $id): ?array;
}
