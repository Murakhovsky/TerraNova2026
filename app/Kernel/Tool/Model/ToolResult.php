<?php
declare(strict_types=1);

namespace Kernel\Tool\Model;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class ToolResult extends ValueObject
{
    /**
     * @param array<string, mixed> $output
     * @param array<string, mixed> $metadata
     */
    private function __construct(
        private bool $success,
        private array $output,
        private ?string $error,
        private array $metadata,
    ) {
        if ($success && $error !== null) {
            throw new InvalidArgumentException('Successful tool result cannot contain an error.');
        }

        if (!$success && ($error === null || trim($error) === '')) {
            throw new InvalidArgumentException('Failed tool result requires an error.');
        }
    }

    /**
     * @param array<string, mixed> $output
     * @param array<string, mixed> $metadata
     */
    public static function success(array $output = [], array $metadata = []): self
    {
        return new self(true, $output, null, $metadata);
    }

    /** @param array<string, mixed> $metadata */
    public static function failure(string $error, array $metadata = []): self
    {
        return new self(false, [], trim($error), $metadata);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isFailure(): bool
    {
        return !$this->success;
    }

    /** @return array<string, mixed> */
    public function output(): array
    {
        return $this->output;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return $this->metadata;
    }
}
