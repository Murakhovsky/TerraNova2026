<?php
declare(strict_types=1);

namespace Domains\Sales\Application\DTO;

final readonly class ClientCaseCommandResult
{
    private function __construct(public bool $ok, public string $code, public array $data = [])
    {
    }

    public static function success(string $code, array $data = []): self
    {
        return new self(true, $code, $data);
    }

    public static function failure(string $code, array $data = []): self
    {
        return new self(false, $code, $data);
    }
}
