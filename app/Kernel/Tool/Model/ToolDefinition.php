<?php
declare(strict_types=1);

namespace Kernel\Tool\Model;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class ToolDefinition extends ValueObject
{
    private string $name;

    /**
     * @param array<string, mixed> $inputSchema
     * @param array<string, mixed> $outputSchema
     */
    public function __construct(
        string $name,
        private string $description,
        private array $inputSchema = [],
        private array $outputSchema = [],
        private ToolEffect $effect = ToolEffect::READ,
    ) {
        $name = strtolower(trim($name));
        if ($name === '' || strlen($name) > 190 || preg_match('/^[a-z0-9._:-]+$/', $name) !== 1) {
            throw new InvalidArgumentException('Tool name must be a valid non-empty capability identifier.');
        }

        $this->name = $name;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    /** @return array<string, mixed> */
    public function inputSchema(): array
    {
        return $this->inputSchema;
    }

    /** @return array<string, mixed> */
    public function outputSchema(): array
    {
        return $this->outputSchema;
    }

    public function effect(): ToolEffect
    {
        return $this->effect;
    }
}
