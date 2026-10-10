<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use DomainException;
use Kernel\Module\CapabilityContract;

/**
 * Fail-closed, deliberately narrow JSON-Schema subset for approved Goal plan
 * action inputs. Unsupported validation keywords reject the schema, rather than
 * pretending an unknown rule was checked. No remote $ref or network I/O.
 */
final readonly class CapabilityJsonInputValidator
{
    public function validate(CapabilityContract $contract, mixed $input): void
    {
        $path = $contract->inputSchema;
        if (!preg_match('#^resources/contracts/[a-z0-9_/-]+\.schema\.json$#', $path)
            || str_contains($path, '..')) {
            throw new DomainException('Capability JSON schema path is not a trusted local contract.');
        }
        $root = dirname(__DIR__, 4);
        $absolute = realpath($root . '/' . $path);
        $prefix = realpath($root . '/resources/contracts');
        if ($absolute === false || $prefix === false
            || !str_starts_with($absolute, $prefix . DIRECTORY_SEPARATOR)) {
            throw new DomainException('Capability input contract is missing or outside trusted schemas.');
        }
        $schema = json_decode((string) file_get_contents($absolute), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($schema) || array_is_list($schema)) {
            throw new DomainException('Capability input schema must be a JSON object.');
        }
        $bytes = strlen(json_encode($input, JSON_THROW_ON_ERROR));
        if ($bytes > 16384) {
            throw new DomainException('Capability input exceeds maximum approved payload size.');
        }
        $this->check($schema, $input, '$', 0);
    }

    /** @param array<string,mixed> $schema */
    private function check(array $schema, mixed $value, string $path, int $depth): void
    {
        if ($depth > 10) {
            throw new DomainException('Capability schema exceeds safe maximum depth.');
        }
        $supported = ['$schema', 'title', 'description', 'type', 'properties', 'required',
            'additionalProperties', 'items', 'minLength', 'maxLength', 'minimum',
            'maximum', 'pattern', 'const', 'enum', 'format', 'minItems', 'maxItems'];
        foreach (array_keys($schema) as $keyword) {
            if (!in_array($keyword, $supported, true)) {
                throw new DomainException('Unsupported capability schema keyword: ' . $keyword);
            }
        }
        $types = $schema['type'] ?? null;
        if (!is_string($types) && !is_array($types)) {
            throw new DomainException('Capability input schema requires an explicit type.');
        }
        $types = is_array($types) ? $types : [$types];
        $matches = static fn (string $type): bool => match ($type) {
            'object' => is_array($value) && !array_is_list($value),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => false,
        };
        if ($types === [] || !array_reduce($types, static fn (bool $valid, string $type): bool => $valid || $matches($type), false)) {
            throw new DomainException('Wrong capability input type at ' . $path);
        }
        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            throw new DomainException('Capability constant mismatch at ' . $path);
        }
        if (array_key_exists('enum', $schema)
            && (!is_array($schema['enum']) || !in_array($value, $schema['enum'], true))) {
            throw new DomainException('Capability enum mismatch at ' . $path);
        }
        if (is_string($value)) {
            if (isset($schema['minLength']) && mb_strlen($value) < (int) $schema['minLength']) {
                throw new DomainException('Capability string too short at ' . $path);
            }
            if (isset($schema['maxLength']) && mb_strlen($value) > (int) $schema['maxLength']) {
                throw new DomainException('Capability string too long at ' . $path);
            }
            if (isset($schema['pattern']) && @preg_match('~' . str_replace('~', '\\~', (string) $schema['pattern']) . '~D', $value) !== 1) {
                throw new DomainException('Capability input fails pattern at ' . $path);
            }
            if (isset($schema['format']) && $schema['format'] === 'date-time'
                && !strtotime($value)) {
                throw new DomainException('Capability invalid date-time at ' . $path);
            }
            if (isset($schema['format']) && $schema['format'] !== 'date-time') {
                throw new DomainException('Unsupported capability string format.');
            }
        }
        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                throw new DomainException('Capability number below minimum at ' . $path);
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                throw new DomainException('Capability number above maximum at ' . $path);
            }
        }
        if (is_array($value) && !array_is_list($value)) {
            $properties = $schema['properties'] ?? [];
            if (!is_array($properties)) {
                throw new DomainException('Capability schema properties malformed.');
            }
            foreach (($schema['required'] ?? []) as $field) {
                if (!array_key_exists($field, $value)) {
                    throw new DomainException('Required capability field missing: ' . $path . '.' . $field);
                }
            }
            foreach ($value as $field => $child) {
                if (!array_key_exists($field, $properties)) {
                    if (($schema['additionalProperties'] ?? true) === false) {
                        throw new DomainException('Unexpected capability field: ' . $path . '.' . $field);
                    }
                    continue;
                }
                if (!is_array($properties[$field])) {
                    throw new DomainException('Capability property schema malformed.');
                }
                $this->check($properties[$field], $child, $path . '.' . $field, $depth + 1);
            }
        } elseif (is_array($value)) {
            if (isset($schema['minItems']) && count($value) < (int) $schema['minItems']) {
                throw new DomainException('Capability array too short.');
            }
            if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
                throw new DomainException('Capability array too long.');
            }
            if (isset($schema['items'])) {
                if (!is_array($schema['items'])) {
                    throw new DomainException('Capability array item schema malformed.');
                }
                foreach ($value as $i => $child) {
                    $this->check($schema['items'], $child, $path . '[' . $i . ']', $depth + 1);
                }
            }
        }
    }
}
