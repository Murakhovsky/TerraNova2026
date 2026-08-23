<?php
declare(strict_types=1);

namespace Kernel\Action;

final readonly class ExecutionResult
{
    private function __construct(
        public bool $successful,
        public array $data,
        public ?string $error,
        public array $metrics = [],
    )
    {
    }

    public static function success(array $data = [], array $metrics = []): self
    {
        return new self(true, $data, null, $metrics);
    }

    public static function failure(string $error, array $data = [], array $metrics = []): self
    {
        return new self(false, $data, $error, $metrics);
    }

    public function status(): string { return $this->successful ? 'SUCCESS' : 'FAILED'; }
}
