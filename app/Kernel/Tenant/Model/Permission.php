<?php
declare(strict_types=1);

namespace Kernel\Tenant\Model;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;
use Stringable;

final readonly class Permission extends ValueObject implements Stringable
{
    private string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));
        if ($value === '' || strlen($value) > 190 || !preg_match('/^[a-z0-9]+(?:[._:-][a-z0-9]+)*$/', $value)) {
            throw new InvalidArgumentException('Permission must be a normalized capability name up to 190 characters.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
