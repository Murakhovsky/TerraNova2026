<?php
declare(strict_types=1);

namespace Kernel\Action;

final readonly class ExecutionResult
{
    private function __construct(public bool $successful, public array $data, public ?string $error)
    {
    }

    public static function success(array $data = []): self
    {
        return new self(true, $data, null);
    }

    public static function failure(string $error, array $data = []): self
    {
        return new self(false, $data, $error);
    }
}
