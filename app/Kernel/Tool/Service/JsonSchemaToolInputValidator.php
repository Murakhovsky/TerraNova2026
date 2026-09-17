<?php
declare(strict_types=1);

namespace Kernel\Tool\Service;

use InvalidArgumentException;
use Kernel\Tool\Contract\ToolInputValidatorInterface;
use Kernel\Tool\Model\ToolDefinition;
use Kernel\Tool\Model\ToolInvocation;

final class JsonSchemaToolInputValidator implements ToolInputValidatorInterface
{
    public function validate(ToolDefinition $definition, ToolInvocation $invocation): void
    {
        $schema = $definition->inputSchema();
        if ($schema === []) return;

        $input = $invocation->input();
        if (($schema['type'] ?? 'object') !== 'object') {
            throw new InvalidArgumentException('Tool input schema root must be object.');
        }

        foreach (($schema['required'] ?? []) as $field) {
            if (!is_string($field) || !array_key_exists($field, $input)) {
                throw new InvalidArgumentException('Missing required tool input: ' . (string) $field);
            }
        }

        foreach (($schema['properties'] ?? []) as $field => $property) {
            if (!array_key_exists($field, $input) || !is_array($property) || !isset($property['type'])) continue;
            if (!$this->matches($input[$field], (string) $property['type'])) {
                throw new InvalidArgumentException(sprintf('Invalid type for tool input %s; expected %s.', $field, $property['type']));
            }
        }
    }

    private function matches(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value),
            'object' => is_array($value),
            'null' => $value === null,
            default => throw new InvalidArgumentException('Unsupported tool schema type: ' . $type),
        };
    }
}
