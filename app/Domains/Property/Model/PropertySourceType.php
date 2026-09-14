<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class PropertySourceType
{
    public const DEVELOPER = 'developer';
    public const MANUAL = 'manual';
    public const OWNER = 'owner';
    public const PARTNER = 'partner';
    public const CRM_IMPORT = 'crm_import';
    public const MLS = 'mls';
    public const PARSER = 'parser';
    public const EXTERNAL_API = 'external_api';

    private const VALUES = [
        self::DEVELOPER,
        self::MANUAL,
        self::OWNER,
        self::PARTNER,
        self::CRM_IMPORT,
        self::MLS,
        self::PARSER,
        self::EXTERNAL_API,
    ];

    public function __construct(public string $value)
    {
        if (!in_array($this->value, self::VALUES, true)) {
            throw new InvalidArgumentException('Unsupported Property source type: ' . $this->value);
        }
    }

    public static function from(string $value): self
    {
        return new self(strtolower(trim($value)));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return self::VALUES;
    }
}
