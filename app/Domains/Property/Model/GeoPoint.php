<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class GeoPoint
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {
        if ($this->latitude < -90.0 || $this->latitude > 90.0) {
            throw new InvalidArgumentException('GeoPoint latitude is outside the valid range.');
        }
        if ($this->longitude < -180.0 || $this->longitude > 180.0) {
            throw new InvalidArgumentException('GeoPoint longitude is outside the valid range.');
        }
    }

    /** @return array{latitude: float, longitude: float} */
    public function toArray(): array
    {
        return ['latitude' => $this->latitude, 'longitude' => $this->longitude];
    }
}
