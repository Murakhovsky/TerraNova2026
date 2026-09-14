<?php
declare(strict_types=1);

namespace Domains\Spatial\Application\Service;

use Domains\Spatial\Application\Contract\SpatialSceneInterface;
use Domains\Spatial\Application\Contract\SpatialSceneRepositoryInterface;

final readonly class SpatialSceneService implements SpatialSceneInterface
{
    public function __construct(private SpatialSceneRepositoryInterface $scenes)
    {
    }

    public function managerScenes(array $filters = []): array { return $this->scenes->managerScenes($filters); }
    public function stats(): array { return $this->scenes->stats(); }
    public function propertyOptions(): array { return $this->scenes->propertyOptions(); }
    public function save(array $input, array $user): array { return $this->scenes->save($input, $user); }
    public function scene(int $id): ?array { return $this->scenes->scene($id); }
    public function sceneReference(int $id): ?array { return $this->scenes->sceneReference($id); }
    public function publicScene(string $reference): ?array { return $this->scenes->publicScene($reference); }
    public function sceneForProperty(int $propertyId, bool $publicOnly = true): ?array { return $this->scenes->sceneForProperty($propertyId, $publicOnly); }
    public function upload(int $sceneId, array $file, array $input, array $user): array { return $this->scenes->upload($sceneId, $file, $input, $user); }
    public function externalAsset(int $sceneId, array $input): array { return $this->scenes->externalAsset($sceneId, $input); }
    public function capture(int $sceneId, array $input, array $user): array { return $this->scenes->capture($sceneId, $input, $user); }
    public function saveHotspot(int $sceneId, array $input): array { return $this->scenes->saveHotspot($sceneId, $input); }
    public function publish(int $sceneId): array { return $this->scenes->publish($sceneId); }
    public function recordEvent(string $reference, array $input, ?array $user = null): bool
    {
        return $this->scenes->recordEvent($reference, $input, $user);
    }
}
