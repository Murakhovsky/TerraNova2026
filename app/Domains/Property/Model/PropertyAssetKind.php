<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class PropertyAssetKind
{
    public const DEVELOPMENT = 'development';
    public const LAND_PLOT = 'land_plot';
    public const BUILDING = 'building';
    public const SECTION = 'section';
    public const ENTRANCE = 'entrance';
    public const FLOOR = 'floor';
    public const UNIT = 'unit';
    public const HOUSE = 'house';

    private const VALUES = [
        self::DEVELOPMENT,
        self::LAND_PLOT,
        self::BUILDING,
        self::SECTION,
        self::ENTRANCE,
        self::FLOOR,
        self::UNIT,
        self::HOUSE,
    ];

    public function __construct(public string $value)
    {
        if (!in_array($this->value, self::VALUES, true)) {
            throw new InvalidArgumentException('Unsupported PropertyAsset kind: ' . $this->value);
        }
    }

    public static function from(string $value): self
    {
        return new self(strtolower(trim($value)));
    }

    public static function infer(PropertyType $type): self
    {
        return new self(match ($type->code) {
            'development', 'residential_complex', 'complex' => self::DEVELOPMENT,
            'land', 'land_plot', 'plot' => self::LAND_PLOT,
            'building' => self::BUILDING,
            'section' => self::SECTION,
            'entrance' => self::ENTRANCE,
            'floor' => self::FLOOR,
            'house', 'cottage', 'townhouse', 'villa' => self::HOUSE,
            default => self::UNIT,
        });
    }

    /** @return list<string> */
    public static function values(): array
    {
        return self::VALUES;
    }

    public function isStructuralNode(): bool
    {
        return in_array($this->value, [self::SECTION, self::ENTRANCE, self::FLOOR], true);
    }
}
