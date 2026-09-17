<?php
declare(strict_types=1);

namespace Kernel\Shared\Application;

use LogicException;

/** @template T */
final readonly class Result
{
    private function __construct(
        private bool $successful,
        private mixed $value,
        private ?string $error,
    ) {
    }

    /** @template TValue @param TValue $value @return self<TValue> */
    public static function success(mixed $value = null): self
    {
        return new self(true, $value, null);
    }

    /** @return self<never> */
    public static function failure(string $error): self
    {
        $error = trim($error);
        if ($error === '') {
            throw new LogicException('Failure result requires an error message.');
        }

        return new self(false, null, $error);
    }

    public function isSuccess(): bool
    {
        return $this->successful;
    }

    public function isFailure(): bool
    {
        return !$this->successful;
    }

    /** @return T */
    public function value(): mixed
    {
        if (!$this->successful) {
            throw new LogicException('Cannot read value from a failed result.');
        }

        return $this->value;
    }

    public function error(): ?string
    {
        return $this->error;
    }
}
