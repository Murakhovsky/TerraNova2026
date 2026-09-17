<?php
declare(strict_types=1);

namespace Kernel\Identity\Model;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;
use Stringable;

final readonly class OrganizationRole extends ValueObject implements Stringable
{
    private string $value;

    private function __construct(string $value)
    {
        $value = strtolower(trim($value));
        if ($value === '' || strlen($value) > 64 || preg_match('/^[a-z0-9._:-]+$/', $value) !== 1) {
            throw new InvalidArgumentException('Organization role must be a valid non-empty role identifier.');
        }

        $this->value = $value;
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isManager(): bool
    {
        return in_array($this->value, ['manager', 'admin'], true);
    }

    public function isAdmin(): bool
    {
        return $this->value === 'admin';
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
