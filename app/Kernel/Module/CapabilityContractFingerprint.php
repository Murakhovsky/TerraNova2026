<?php
declare(strict_types=1);

namespace Kernel\Module;

use DomainException;
use JsonException;

/**
 * Pure, deterministic fingerprint of an owning Domain manifest contract and
 * its pinned local input/output schemas. Never resolves or invokes a handler.
 */
final class CapabilityContractFingerprint
{
    /**
     * @return array{capability_id:string,version:string,contract_digest:string,input_schema_digest:string,output_schema_digest:string}
     */
    public static function snapshot(CapabilityContract $contract, ?string $projectRoot = null): array
    {
        $root = $projectRoot ?? dirname(__DIR__, 3);
        $input = self::schema($root, $contract->inputSchema);
        $output = self::schema($root, $contract->outputSchema);
        $payload = [
            'profile' => 'cos-capability-contract-snapshot-v1',
            'contract' => $contract->toArray(),
            'input_schema_digest' => hash('sha256', self::canonical($input)),
            'output_schema_digest' => hash('sha256', self::canonical($output)),
        ];
        return [
            'capability_id' => $contract->id,
            'version' => $contract->version,
            'contract_digest' => hash('sha256', self::canonical($payload)),
            'input_schema_digest' => $payload['input_schema_digest'],
            'output_schema_digest' => $payload['output_schema_digest'],
        ];
    }

    /** @return array<string,mixed> */
    private static function schema(string $root, string $relative): array
    {
        if (!preg_match('#^resources/contracts/[a-z0-9_/-]+\.schema\.json$#D', $relative)
            || str_contains($relative, '..')) {
            throw new DomainException('Capability schema must be a trusted local contract.');
        }
        $base = realpath(rtrim($root, '/') . '/resources/contracts');
        $path = realpath(rtrim($root, '/') . '/' . $relative);
        if ($base === false || $path === false
            || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new DomainException('Capability schema missing or outside trusted contract root.');
        }
        try {
            $data = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new DomainException('Malformed capability schema JSON.', previous: $e);
        }
        if (!is_array($data) || array_is_list($data)
            || !isset($data['type']) || !is_string($data['type'])
                && !is_array($data['type'])) {
            throw new DomainException('Capability schema requires an object with an explicit type.');
        }
        self::assertSupportedSchema($data);
        return $data;
    }

    /** Mirrors the fail-closed vocabulary of CapabilityJsonInputValidator.
     * Top-level schemas require a type. A nested {} is legal and unrestricted.
     * This prevents unsupported output rules failing only AFTER a side effect.
     * @param array<string,mixed> $schema
     */
    private static function assertSupportedSchema(array $schema, int $depth = 0): void
    {
        if ($depth > 10) {
            throw new DomainException('Capability schema exceeds allowed depth.');
        }
        if ($depth > 0 && $schema === []) {
            return;
        }
        $supported = ['$schema','title','description','type','properties','required',
            'additionalProperties','items','minLength','maxLength','minimum',
            'maximum','pattern','const','enum','format','minItems','maxItems'];
        foreach (array_keys($schema) as $keyword) {
            if (!in_array($keyword, $supported, true)) {
                throw new DomainException('Unsupported capability schema keyword: ' . $keyword);
            }
        }
        $types = $schema['type'] ?? null;
        if (is_string($types)) {
            $types = [$types];
        }
        if (!is_array($types) || !array_is_list($types) || $types === []) {
            throw new DomainException('Capability schema requires an explicit type.');
        }
        foreach ($types as $type) {
            if (!is_string($type)
                || !in_array($type, ['object','array','string','integer','number','boolean','null'], true)) {
                throw new DomainException('Unsupported capability schema value type.');
            }
        }
        if (isset($schema['format']) && $schema['format'] !== 'date-time') {
            throw new DomainException('Unsupported capability schema format.');
        }
        if (array_key_exists('properties', $schema)) {
            if (!is_array($schema['properties']) || array_is_list($schema['properties'])) {
                throw new DomainException('Capability properties schema must be an object.');
            }
            foreach ($schema['properties'] as $name => $child) {
                if (!is_string($name) || !is_array($child) || ($child !== [] && array_is_list($child))) {
                    throw new DomainException('Invalid nested capability property.');
                }
                self::assertSupportedSchema($child, $depth + 1);
            }
        }
        if (array_key_exists('items', $schema)) {
            if (!is_array($schema['items']) || ($schema['items'] !== [] && array_is_list($schema['items']))) {
                throw new DomainException('Invalid capability items schema.');
            }
            self::assertSupportedSchema($schema['items'], $depth + 1);
        }
        if (isset($schema['required'])) {
            if (!is_array($schema['required']) || !array_is_list($schema['required'])) {
                throw new DomainException('Invalid capability required fields.');
            }
            foreach ($schema['required'] as $field) {
                if (!is_string($field) || $field === '') {
                    throw new DomainException('Invalid capability required field.');
                }
            }
        }
        if (isset($schema['additionalProperties']) && !is_bool($schema['additionalProperties'])) {
            throw new DomainException('Unsupported additionalProperties contract.');
        }
    }

    private static function canonical(mixed $value): string
    {
        try {
            return json_encode(self::normalize($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $e) {
            throw new DomainException('Unserializable capability contract.', previous: $e);
        }
    }

    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = self::normalize($child);
        }
        return $value;
    }
}
