<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class GeoBoundary
{
    /** @param list<GeoPoint> $points */
    public function __construct(public array $points)
    {
        if (count($this->points) < 3) {
            throw new InvalidArgumentException('GeoBoundary requires at least three points.');
        }
        foreach ($this->points as $point) {
            if (!$point instanceof GeoPoint) {
                throw new InvalidArgumentException('GeoBoundary points must be GeoPoint values.');
            }
        }
    }

    /** @return list<array{latitude: float, longitude: float}> */
    public function toArray(): array
    {
        return array_map(static fn (GeoPoint $point): array => $point->toArray(), $this->points);
    }
}
