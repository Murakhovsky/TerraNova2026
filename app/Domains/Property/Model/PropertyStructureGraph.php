<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;
use RuntimeException;

final class PropertyStructureGraph
{
    /** @var array<string,PropertyAsset> */
    private array $assets = [];

    /** @var list<PropertyAssetRelation> */
    private array $relations = [];

    /**
     * @param list<PropertyAsset> $assets
     * @param list<PropertyAssetRelation> $relations
     */
    public function __construct(
        private readonly string $organizationId,
        array $assets,
        array $relations,
    ) {
        if (trim($this->organizationId) === '') {
            throw new InvalidArgumentException('PropertyStructureGraph organization id is required.');
        }

        foreach ($assets as $asset) {
            if (!$asset instanceof PropertyAsset || $asset->organizationId !== $this->organizationId) {
                throw new InvalidArgumentException('PropertyStructureGraph assets must belong to one organization.');
            }
            $this->assets[$asset->assetId] = $asset;
        }

        foreach ($relations as $relation) {
            if (!$relation instanceof PropertyAssetRelation || $relation->organizationId !== $this->organizationId) {
                throw new InvalidArgumentException('PropertyStructureGraph relations must belong to one organization.');
            }
            if (!isset($this->assets[$relation->sourceAssetId], $this->assets[$relation->targetAssetId])) {
                throw new InvalidArgumentException('PropertyStructureGraph relation endpoints must exist in the graph.');
            }
            $this->relations[] = $relation;
        }
    }

    /** @return list<PropertyAsset> */
    public function childrenOf(string $assetId): array
    {
        $children = [];
        foreach ($this->relations as $relation) {
            if ($relation->type->value === PropertyAssetRelationType::CONTAINS && $relation->sourceAssetId === $assetId) {
                $children[] = ['order' => $relation->sortOrder, 'asset' => $this->assets[$relation->targetAssetId]];
            }
        }
        usort($children, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_values(array_map(static fn (array $row): PropertyAsset => $row['asset'], $children));
    }

    /** @return list<PropertyAsset> */
    public function path(string $ancestorAssetId, string $descendantAssetId): array
    {
        if (!isset($this->assets[$ancestorAssetId], $this->assets[$descendantAssetId])) {
            throw new InvalidArgumentException('PropertyStructureGraph path endpoints must exist.');
        }

        $queue = [[$ancestorAssetId]];
        $visited = [];

        while ($queue !== []) {
            $path = array_shift($queue);
            $current = $path[array_key_last($path)];
            if ($current === $descendantAssetId) {
                return array_map(fn (string $id): PropertyAsset => $this->assets[$id], $path);
            }
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;

            foreach ($this->childrenOf($current) as $child) {
                if (!isset($visited[$child->assetId])) {
                    $queue[] = [...$path, $child->assetId];
                }
            }
        }

        throw new RuntimeException(sprintf('No CONTAINS path from %s to %s.', $ancestorAssetId, $descendantAssetId));
    }
}
