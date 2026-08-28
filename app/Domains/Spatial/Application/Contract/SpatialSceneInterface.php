<?php
declare(strict_types=1);

namespace Domains\Spatial\Application\Contract;

interface SpatialSceneInterface
{
    public function managerScenes(array $filters = []): array;
    public function stats(): array;
    public function propertyOptions(): array;
    public function save(array $input, array $user): array;
    public function scene(int $id): ?array;
    public function sceneReference(int $id): ?array;
    public function publicScene(string $reference): ?array;
    public function sceneForProperty(int $propertyId, bool $publicOnly = true): ?array;
    public function upload(int $sceneId, array $file, array $input, array $user): array;
    public function externalAsset(int $sceneId, array $input): array;
    public function capture(int $sceneId, array $input, array $user): array;
    public function saveHotspot(int $sceneId, array $input): array;
    public function publish(int $sceneId): array;
    public function recordEvent(string $reference, array $input, ?array $user = null): bool;
}
