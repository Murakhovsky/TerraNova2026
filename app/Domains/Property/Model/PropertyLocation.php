<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class PropertyLocation
{
    public function __construct(
        public string $countryCode,
        public string $region,
        public string $city,
        public ?string $district = null,
        public ?string $address = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?int $referenceId = null,
    ) {
        if (!preg_match('/^[A-Z]{2}$/', $this->countryCode)) {
            throw new InvalidArgumentException('PropertyLocation country code must be ISO alpha-2 uppercase.');
        }
        if (trim($this->city) === '') {
            throw new InvalidArgumentException('PropertyLocation city is required.');
        }
        if ($this->referenceId !== null && $this->referenceId <= 0) {
            throw new InvalidArgumentException('PropertyLocation reference id must be positive when present.');
        }

        $hasLatitude = $this->latitude !== null;
        $hasLongitude = $this->longitude !== null;
        if ($hasLatitude !== $hasLongitude) {
            throw new InvalidArgumentException('PropertyLocation coordinates must be supplied as a pair.');
        }
        if ($this->latitude !== null && ($this->latitude < -90 || $this->latitude > 90)) {
            throw new InvalidArgumentException('PropertyLocation latitude is out of range.');
        }
        if ($this->longitude !== null && ($this->longitude < -180 || $this->longitude > 180)) {
            throw new InvalidArgumentException('PropertyLocation longitude is out of range.');
        }
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** @return array{country_code:string,region:string,city:string,district:?string,address:?string,latitude:?float,longitude:?float,reference_id:?int} */
    public function toArray(): array
    {
        return [
            'country_code' => $this->countryCode,
            'region' => $this->region,
            'city' => $this->city,
            'district' => $this->district,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'reference_id' => $this->referenceId,
        ];
    }
}
