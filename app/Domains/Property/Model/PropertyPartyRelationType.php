<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class PropertyPartyRelationType
{
    public const OWNER = 'owner';
    public const CO_OWNER = 'co_owner';
    public const DEVELOPER = 'developer';
    public const MANAGER = 'manager';
    public const REPRESENTATIVE = 'representative';
    public const TENANT = 'tenant';
    public const OPERATOR = 'operator';
    public const CONTRACTOR = 'contractor';

    private const VALUES = [
        self::OWNER,
        self::CO_OWNER,
        self::DEVELOPER,
        self::MANAGER,
        self::REPRESENTATIVE,
        self::TENANT,
        self::OPERATOR,
        self::CONTRACTOR,
    ];

    public function __construct(public string $value)
    {
        if (!in_array($this->value, self::VALUES, true)) {
            throw new InvalidArgumentException('Unsupported Property party relation type: ' . $this->value);
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
