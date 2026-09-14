<?php
declare(strict_types=1);

namespace Domains\Spatial\Application\Contract;

interface SpatialAccessInterface
{
    public function token(array $credentials): array;
    public function actor(string $authorization = ''): ?array;
    public function editor(string $authorization = ''): ?array;
    public function canEdit(?array $user): bool;
}
