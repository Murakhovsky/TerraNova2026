<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Domain;

use DomainException;

/** Pure, deterministic product invariants. */
final class ProductRules
{
    public static function sku(string $sku): string
    {
        $sku = strtoupper(trim($sku));
        if (!preg_match('/^[A-Z0-9][A-Z0-9._:\/-]{0,127}$/D', $sku)) {
            throw new DomainException('Invalid SKU. Use 1–128 ASCII alphanumeric or . _ : / - characters.');
        }
        return $sku;
    }

    public static function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw new DomainException('Product name must have 1–255 characters.');
        }
        return $name;
    }

    public static function code(string $code): string
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{0,63}$/D', $code)) {
            throw new DomainException('Invalid location code.');
        }
        return $code;
    }

    public static function quantity(string $value, bool $allowNegative = false): string
    {
        $pattern = $allowNegative
            ? '/^-?(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/D'
            : '/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/D';
        if (!preg_match($pattern, $value) || preg_match('/^-?0(?:\\.0+)?$/D', $value) === 1) {
            throw new DomainException('Quantity must be nonzero DECIMAL(20,6).');
        }
        return $value;
    }

    public static function money(string $amount): string
    {
        if (!preg_match('/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,4})?$/D', $amount)) {
            throw new DomainException('Invalid monetary amount.');
        }
        return $amount;
    }

    public static function productType(string $type): string
    {
        if (!in_array($type, ['stock', 'material', 'service', 'digital'], true)) {
            throw new DomainException('Invalid product type.');
        }
        return $type;
    }
}
