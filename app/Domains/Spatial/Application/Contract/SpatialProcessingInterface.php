<?php
declare(strict_types=1);

namespace Domains\Spatial\Application\Contract;

interface SpatialProcessingInterface
{
    public function process(int $limit = 10): array;
    public function job(string $publicId): ?array;
}
